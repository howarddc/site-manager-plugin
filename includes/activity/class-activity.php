<?php
/**
 * Site-wide activity log: storage, request context, querying and reports.
 *
 * Every detectable action on the site — by any user, cron, WP-CLI or an MCP
 * client — is written to {prefix}site_manager_events. The hooks that feed it
 * live in Site_Manager_Activity_Hooks.
 *
 * Intended for monthly client reporting (updates, content, users) and for
 * security audits (logins, role changes, file edits, settings).
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Activity {

	const SEVERITIES = array( 'info', 'notice', 'warning', 'critical' );

	const MAX_DETAILS_BYTES = 16000;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'site_manager_events';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			last_at datetime NOT NULL,
			occurrences int(10) unsigned NOT NULL DEFAULT 1,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			user_role varchar(60) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			via varchar(191) NOT NULL DEFAULT '',
			severity varchar(10) NOT NULL DEFAULT 'info',
			category varchar(40) NOT NULL,
			action varchar(60) NOT NULL,
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id varchar(191) NOT NULL DEFAULT '',
			object_name varchar(255) NOT NULL DEFAULT '',
			message text NOT NULL,
			details longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY category_action (category, action),
			KEY user_id (user_id),
			KEY object (object_type, object_id),
			KEY severity (severity)
		) {$charset};" );
	}

	public static function enabled() {
		return (bool) Site_Manager_Settings::get( 'activity_enabled' );
	}

	// ---------------------------------------------------------------
	// Writing
	// ---------------------------------------------------------------

	/**
	 * Record an event.
	 *
	 * @param array $e {
	 *     @type string       $category    e.g. plugin, theme, core, post, user, auth, setting, mcp.
	 *     @type string       $action      snake_case verb, e.g. plugin_updated, login_failed.
	 *     @type string       $message     Human-readable sentence.
	 *     @type string       $severity    info | notice | warning | critical.
	 *     @type string       $object_type What was acted on (plugin, post, user, option…).
	 *     @type string|int   $object_id
	 *     @type string       $object_name
	 *     @type array        $details     Extra data (before/after values, versions…).
	 *     @type WP_User|int  $user        Actor; defaults to the current user.
	 *     @type string       $user_login  Actor login when there is no user (e.g. failed login).
	 *     @type int          $dedupe      Seconds: fold into a matching recent event instead of inserting.
	 * }
	 */
	public static function log( array $e ) {
		if ( ! self::enabled() ) {
			return;
		}
		try {
			self::write( $e );
		} catch ( Throwable $ex ) {
			// Logging must never break the request.
		}
	}

	private static function write( array $e ) {
		global $wpdb;

		$user = isset( $e['user'] ) ? $e['user'] : wp_get_current_user();
		if ( is_numeric( $user ) ) {
			$user = get_userdata( (int) $user );
		}
		$user_id    = $user instanceof WP_User ? (int) $user->ID : 0;
		$user_login = $user instanceof WP_User && $user->ID ? $user->user_login : ( isset( $e['user_login'] ) ? (string) $e['user_login'] : '' );
		$user_role  = $user instanceof WP_User && $user->roles ? (string) reset( $user->roles ) : '';

		$severity = isset( $e['severity'] ) && in_array( $e['severity'], self::SEVERITIES, true ) ? $e['severity'] : 'info';
		$details  = isset( $e['details'] ) && $e['details'] !== array() ? wp_json_encode( $e['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) : null;
		if ( $details !== null && strlen( $details ) > self::MAX_DETAILS_BYTES ) {
			$details = wp_json_encode( array( 'truncated' => substr( $details, 0, self::MAX_DETAILS_BYTES ) ) );
		}

		$now = current_time( 'mysql', true );
		$row = array(
			'created_at'  => $now,
			'last_at'     => $now,
			'occurrences' => 1,
			'user_id'     => $user_id,
			'user_login'  => substr( $user_login, 0, 60 ),
			'user_role'   => substr( $user_role, 0, 60 ),
			'ip'          => self::ip(),
			'user_agent'  => substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '', 0, 255 ),
			'source'      => self::source(),
			'via'         => substr( self::via( $user_id ), 0, 191 ),
			'severity'    => $severity,
			'category'    => substr( (string) $e['category'], 0, 40 ),
			'action'      => substr( (string) $e['action'], 0, 60 ),
			'object_type' => substr( isset( $e['object_type'] ) ? (string) $e['object_type'] : '', 0, 40 ),
			'object_id'   => substr( isset( $e['object_id'] ) ? (string) $e['object_id'] : '', 0, 191 ),
			'object_name' => substr( isset( $e['object_name'] ) ? wp_strip_all_tags( (string) $e['object_name'] ) : '', 0, 255 ),
			'message'     => isset( $e['message'] ) ? (string) $e['message'] : '',
			'details'     => $details,
		);

		$suppress = $wpdb->suppress_errors( true );

		if ( ! empty( $e['dedupe'] ) ) {
			// Match on the identifying fields that are set; comparing empty
			// strings is unreliable across database drivers.
			$where  = array( 'action = %s', 'user_id = %d', 'last_at >= %s' );
			$params = array( $row['action'], $row['user_id'], gmdate( 'Y-m-d H:i:s', time() - (int) $e['dedupe'] ) );
			foreach ( array( 'object_id', 'object_name', 'ip' ) as $col ) {
				if ( $row[ $col ] !== '' ) {
					$where[]  = "{$col} = %s";
					$params[] = $row[ $col ];
				}
			}
			$existing = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 1',
				$params
			) );
			if ( $existing ) {
				$previous = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM ' . self::table() . ' WHERE id = %d', (int) $existing ) ), true );
				if ( is_array( $previous ) && isset( $e['details'] ) && is_array( $e['details'] ) ) {
					$details = wp_json_encode( self::merge_details( $previous, $e['details'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
				}
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . self::table() . ' SET occurrences = occurrences + 1, last_at = %s, message = %s, details = %s, severity = %s WHERE id = %d',
					$now, $row['message'], $details, $severity, (int) $existing
				) );
				$wpdb->suppress_errors( $suppress );
				return;
			}
		}

		$wpdb->insert( self::table(), $row );
		$wpdb->suppress_errors( $suppress );

		/**
		 * Fires after an activity event is recorded (e.g. to forward critical
		 * events to Slack or a SIEM).
		 *
		 * @param array $row The stored row.
		 */
		do_action( 'site_manager_activity_logged', $row );
	}

	/**
	 * Combine details of folded events: lists are unioned, {from, to} pairs
	 * keep the earliest "from" and latest "to", anything else takes the newest.
	 */
	private static function merge_details( array $old, array $new ) {
		foreach ( $new as $key => $value ) {
			if ( ! array_key_exists( $key, $old ) ) {
				$old[ $key ] = $value;
			} elseif ( is_array( $value ) && is_array( $old[ $key ] ) && array_key_exists( 'from', $old[ $key ] ) && array_key_exists( 'to', $value ) ) {
				$old[ $key ]['to'] = $value['to'];
			} elseif ( is_array( $value ) && is_array( $old[ $key ] ) && $value === array_values( $value ) && $old[ $key ] === array_values( $old[ $key ] ) ) {
				$old[ $key ] = array_values( array_unique( array_merge( $old[ $key ], $value ), SORT_REGULAR ) );
			} else {
				$old[ $key ] = $value;
			}
		}
		return $old;
	}

	// ---------------------------------------------------------------
	// Request context
	// ---------------------------------------------------------------

	public static function source() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( class_exists( 'Site_Manager_Server' ) && Site_Manager_Server::is_mcp_request() ) {
			return 'mcp';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || self::is_rest_uri() ) {
			return 'rest';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return strpos( $uri, 'wp-login.php' ) !== false ? 'login' : 'web';
	}

	private static function is_rest_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return strpos( $uri, '/' . rest_get_url_prefix() . '/' ) !== false || isset( $_GET['rest_route'] );
	}

	/** Which credential the request used: OAuth client or application password name. */
	private static function via( $user_id ) {
		if ( class_exists( 'Site_Manager_Server' ) && Site_Manager_Server::is_mcp_request() ) {
			return Site_Manager_Server::client_label();
		}
		if ( $user_id && function_exists( 'rest_get_authenticated_app_password' ) ) {
			$uuid = rest_get_authenticated_app_password();
			if ( $uuid ) {
				$item = WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
				return 'app-password:' . ( $item ? $item['name'] : $uuid );
			}
		}
		return '';
	}

	public static function ip() {
		$mode = Site_Manager_Settings::get( 'activity_ip' );
		if ( $mode === 'off' ) {
			return '';
		}
		$header = (string) Site_Manager_Settings::get( 'activity_ip_header' );
		$ip     = '';
		if ( $header && $header !== 'REMOTE_ADDR' && ! empty( $_SERVER[ $header ] ) ) {
			// X-Forwarded-For can be a list; the client is the first entry.
			$ip = trim( explode( ',', (string) $_SERVER[ $header ] )[0] );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		return $mode === 'anonymized' ? wp_privacy_anonymize_ip( $ip ) : $ip;
	}

	// ---------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------

	/** Local-time date string → UTC mysql datetime. */
	private static function utc( $local, $end_of_day = false ) {
		$local = trim( (string) $local );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $local ) ) {
			$local .= $end_of_day ? ' 23:59:59' : ' 00:00:00';
		}
		return get_gmt_from_date( $local );
	}

	/**
	 * Build WHERE clause + params from filters. Dates are in site local time.
	 */
	private static function where( array $f ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		$exact  = array( 'category', 'action', 'source', 'object_type', 'object_id', 'ip', 'user_login' );
		foreach ( $exact as $key ) {
			if ( isset( $f[ $key ] ) && $f[ $key ] !== '' && $f[ $key ] !== null ) {
				$values   = (array) $f[ $key ];
				$where[]  = $key . ' IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
				$params   = array_merge( $params, array_map( 'strval', $values ) );
			}
		}
		if ( ! empty( $f['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $f['user_id'];
		}
		if ( ! empty( $f['severity'] ) ) {
			$values  = (array) $f['severity'];
			$where[] = 'severity IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$params  = array_merge( $params, $values );
		}
		if ( ! empty( $f['min_severity'] ) && in_array( $f['min_severity'], self::SEVERITIES, true ) ) {
			$levels  = array_slice( self::SEVERITIES, array_search( $f['min_severity'], self::SEVERITIES, true ) );
			$where[] = 'severity IN (' . implode( ',', array_fill( 0, count( $levels ), '%s' ) ) . ')';
			$params  = array_merge( $params, $levels );
		}
		if ( ! empty( $f['after'] ) ) {
			$where[]  = 'last_at >= %s';
			$params[] = self::utc( $f['after'] );
		}
		if ( ! empty( $f['before'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = self::utc( $f['before'], true );
		}
		if ( ! empty( $f['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$where[]  = '(message LIKE %s OR object_name LIKE %s OR user_login LIKE %s OR ip LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		return array( implode( ' AND ', $where ), $params );
	}

	private static function prepare( $sql, array $params ) {
		global $wpdb;
		return $params ? $wpdb->prepare( $sql, $params ) : $sql;
	}

	public static function format_row( array $row ) {
		$tz = wp_timezone();
		$local = function ( $utc ) use ( $tz ) {
			return ( new DateTime( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
		};
		return array(
			'id'          => (int) $row['id'],
			'time'        => $local( $row['created_at'] ),
			'last_time'   => (int) $row['occurrences'] > 1 ? $local( $row['last_at'] ) : null,
			'occurrences' => (int) $row['occurrences'],
			'user_id'     => (int) $row['user_id'],
			'user'        => $row['user_login'],
			'role'        => $row['user_role'],
			'ip'          => $row['ip'],
			'user_agent'  => $row['user_agent'],
			'source'      => $row['source'],
			'via'         => $row['via'],
			'severity'    => $row['severity'],
			'category'    => $row['category'],
			'action'      => $row['action'],
			'object_type' => $row['object_type'],
			'object_id'   => $row['object_id'],
			'object_name' => $row['object_name'],
			'message'     => $row['message'],
			'details'     => $row['details'] ? json_decode( $row['details'], true ) : null,
		);
	}

	public static function query( array $filters, $page = 1, $per_page = 50 ) {
		global $wpdb;
		list( $where, $params ) = self::where( $filters );
		$table = self::table();
		$order = isset( $filters['order'] ) && strtoupper( $filters['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY id {$order} LIMIT %d OFFSET %d",
			array_merge( $params, array( (int) $per_page, max( 0, ( (int) $page - 1 ) * (int) $per_page ) ) )
		), ARRAY_A );
		return array( 'total' => $total, 'rows' => $rows ?: array() );
	}

	/** Distinct values of a column, for filter dropdowns. */
	public static function distinct( $column ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'category', 'source', 'severity', 'action' ), true ) ) {
			return array();
		}
		return $wpdb->get_col( "SELECT DISTINCT {$column} FROM " . self::table() . " ORDER BY {$column}" );
	}

	/**
	 * Summary for a period, shaped for monthly client reports and audits.
	 * Defaults to the previous calendar month (site timezone).
	 */
	public static function report( $after = '', $before = '' ) {
		global $wpdb;
		if ( ! $after && ! $before ) {
			$first  = new DateTime( 'first day of last month', wp_timezone() );
			$after  = $first->format( 'Y-m-01' );
			$before = $first->format( 'Y-m-t' );
		}
		$after  = $after ? $after : '1970-01-01';
		$before = $before ? $before : wp_date( 'Y-m-d' );
		$base   = array( 'after' => $after, 'before' => $before );
		$table  = self::table();

		$list = function ( array $filters, $limit = 500 ) use ( $base ) {
			$result = self::query( $filters + $base + array( 'order' => 'ASC' ), 1, $limit );
			return array_map( function ( $r ) {
				$r = self::format_row( $r );
				return array_filter( array(
					'time'     => $r['time'],
					'severity' => $r['severity'],
					'category' => $r['category'],
					'action'   => $r['action'],
					'user'    => $r['user'] ?: ( $r['source'] === 'cron' ? 'automatic' : $r['source'] ),
					'object'  => $r['object_name'],
					'message' => $r['message'],
					'details' => $r['details'],
					'count'   => $r['occurrences'] > 1 ? $r['occurrences'] : null,
				), function ( $v ) {
					return $v !== null && $v !== '';
				} );
			}, $result['rows'] );
		};

		$count_by = function ( $column, array $filters = array() ) use ( $base, $table, $wpdb ) {
			list( $where, $params ) = self::where( $filters + $base );
			$rows = $wpdb->get_results( self::prepare( "SELECT {$column} AS k, SUM(occurrences) AS n FROM {$table} WHERE {$where} GROUP BY {$column} ORDER BY n DESC", $params ), ARRAY_A );
			$out  = array();
			foreach ( (array) $rows as $r ) {
				$out[ $r['k'] === '' ? '(none)' : $r['k'] ] = (int) $r['n'];
			}
			return (object) $out;
		};

		$content = array();
		list( $where, $params ) = self::where( array( 'category' => 'post' ) + $base );
		foreach ( (array) $wpdb->get_results( self::prepare( "SELECT object_type, action, SUM(occurrences) AS n FROM {$table} WHERE {$where} GROUP BY object_type, action", $params ), ARRAY_A ) as $r ) {
			$content[ $r['object_type'] ][ $r['action'] ] = (int) $r['n'];
		}

		return array(
			'period'      => array( 'from' => $after, 'to' => $before, 'timezone' => wp_timezone_string() ),
			'site'        => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url() ),
			'totals'      => array(
				'by_category' => $count_by( 'category' ),
				'by_severity' => $count_by( 'severity' ),
				'by_source'   => $count_by( 'source' ),
			),
			'updates'     => array(
				'core'               => $list( array( 'action' => array( 'core_updated' ) ) ),
				'plugins_updated'    => $list( array( 'action' => 'plugin_updated' ) ),
				'themes_updated'     => $list( array( 'action' => 'theme_updated' ) ),
				'translations'       => $list( array( 'action' => 'translations_updated' ) ),
				'failed'             => $list( array( 'action' => array( 'update_failed' ) ) ),
			),
			'plugins'     => array(
				'installed'   => $list( array( 'action' => 'plugin_installed' ) ),
				'activated'   => $list( array( 'action' => 'plugin_activated' ) ),
				'deactivated' => $list( array( 'action' => 'plugin_deactivated' ) ),
				'deleted'     => $list( array( 'action' => 'plugin_deleted' ) ),
			),
			'themes'      => array(
				'installed' => $list( array( 'action' => 'theme_installed' ) ),
				'switched'  => $list( array( 'action' => 'theme_switched' ) ),
				'deleted'   => $list( array( 'action' => 'theme_deleted' ) ),
			),
			'users'       => array(
				'created'      => $list( array( 'action' => 'user_created' ) ),
				'deleted'      => $list( array( 'action' => 'user_deleted' ) ),
				'role_changes' => $list( array( 'action' => 'user_role_changed' ) ),
				'password_changes' => $list( array( 'action' => array( 'password_changed', 'password_reset' ) ), 200 ),
			),
			'logins'      => array(
				'successful_by_user' => $count_by( 'user_login', array( 'action' => 'login' ) ),
				'failed_total'       => array_sum( (array) $count_by( 'action', array( 'action' => 'login_failed' ) ) ),
				'failed_by_username' => array_slice( (array) $count_by( 'object_name', array( 'action' => 'login_failed' ) ), 0, 20, true ),
				'failed_by_ip'       => array_slice( (array) $count_by( 'ip', array( 'action' => 'login_failed' ) ), 0, 20, true ),
			),
			'content'     => (object) $content,
			'settings'    => $list( array( 'category' => 'setting' ), 200 ),
			'security'    => $list( array( 'min_severity' => 'warning' ), 200 ),
			'mcp'         => array(
				'tool_runs_by_tool' => $count_by( 'object_name', array( 'category' => 'mcp' ) ),
				'clients'           => $count_by( 'via', array( 'source' => 'mcp' ) ),
			),
			'woocommerce' => array(
				'order_status_changes' => $count_by( 'object_name', array( 'action' => 'order_status_changed' ) ),
				'refunds'              => $list( array( 'action' => 'order_refunded' ), 200 ),
			),
		);
	}

	/** Whether an event with this action/object was recorded in the last $seconds. */
	public static function recent( $action, $object_id, $seconds ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE action = %s AND object_id = %s AND last_at >= %s LIMIT 1',
			$action, (string) $object_id, gmdate( 'Y-m-d H:i:s', time() - (int) $seconds )
		) );
	}

	/**
	 * Event counts grouped by one dimension. "day" buckets in site time.
	 */
	public static function stats( $group_by, array $filters, $limit = 50 ) {
		global $wpdb;
		$columns = array( 'category', 'action', 'user_login', 'source', 'severity', 'ip', 'object_type', 'object_name' );
		list( $where, $params ) = self::where( $filters );
		$table = self::table();

		if ( $group_by === 'day' ) {
			$rows = $wpdb->get_results( self::prepare( "SELECT created_at, occurrences FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 200000", $params ), ARRAY_A );
			$days = array();
			foreach ( (array) $rows as $r ) {
				$day          = get_date_from_gmt( $r['created_at'], 'Y-m-d' );
				$days[ $day ] = ( isset( $days[ $day ] ) ? $days[ $day ] : 0 ) + (int) $r['occurrences'];
			}
			ksort( $days );
			return array( 'group_by' => 'day', 'counts' => (object) $days, 'total' => array_sum( $days ) );
		}
		if ( ! in_array( $group_by, $columns, true ) ) {
			return new WP_Error( 'bad_group', 'Unsupported group_by.' );
		}
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$group_by} AS k, SUM(occurrences) AS n FROM {$table} WHERE {$where} GROUP BY {$group_by} ORDER BY n DESC LIMIT %d",
			array_merge( $params, array( (int) $limit ) )
		), ARRAY_A );
		$counts = array();
		foreach ( (array) $rows as $r ) {
			$counts[ $r['k'] === '' ? '(none)' : $r['k'] ] = (int) $r['n'];
		}
		return array( 'group_by' => $group_by, 'counts' => (object) $counts, 'total' => array_sum( $counts ) );
	}

	public static function purge( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days <= 0 ) {
			return; // Keep forever.
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE last_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}

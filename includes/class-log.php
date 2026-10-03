<?php
/**
 * Activity log for MCP tool calls.
 *
 * Every write is logged; reads are logged only when the "log reads" setting
 * is on. Arguments are stored with secrets redacted and large values
 * truncated.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Log {

	const MAX_ARGS_BYTES = 4000;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'site_manager_log';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			client varchar(255) NOT NULL DEFAULT '',
			tool varchar(100) NOT NULL,
			writes tinyint(1) NOT NULL DEFAULT 0,
			status varchar(10) NOT NULL DEFAULT 'ok',
			message text NULL,
			args longtext NULL,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY tool (tool)
		) {$charset};" );
	}

	public static function record( $tool, array $args, $writes, $status, $message, $duration_ms, $client ) {
		global $wpdb;
		$encoded = wp_json_encode( self::redact( $args ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( (string) $encoded ) > self::MAX_ARGS_BYTES ) {
			$encoded = substr( $encoded, 0, self::MAX_ARGS_BYTES ) . '…';
		}
		$wpdb->insert( self::table(), array(
			'created_at'  => current_time( 'mysql', true ),
			'user_id'     => get_current_user_id(),
			'client'      => substr( (string) $client, 0, 255 ),
			'tool'        => substr( (string) $tool, 0, 100 ),
			'writes'      => $writes ? 1 : 0,
			'status'      => $status,
			'message'     => $message !== null ? substr( (string) $message, 0, 2000 ) : null,
			'args'        => $encoded,
			'duration_ms' => (int) $duration_ms,
		) );
	}

	/**
	 * Replace secret-looking values and shorten long strings (base64 file
	 * payloads, full page content) so the log stays readable.
	 */
	private static function redact( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::redact( $v, (string) $k );
			}
			return $out;
		}
		if ( $key !== '' && preg_match( '/pass(word)?|secret|token|api_?key/i', $key ) ) {
			return '[redacted]';
		}
		if ( is_string( $value ) && strlen( $value ) > 500 ) {
			return substr( $value, 0, 500 ) . '… (' . strlen( $value ) . ' bytes)';
		}
		return $value;
	}

	public static function query( array $filters = array(), $page = 1, $per_page = 50 ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['tool'] ) ) {
			$where[]  = 'tool = %s';
			$params[] = $filters['tool'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( isset( $filters['writes'] ) && $filters['writes'] !== '' ) {
			$where[]  = 'writes = %d';
			$params[] = (int) $filters['writes'];
		}
		$table     = self::table();
		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( (int) $per_page, $offset ) ) ), ARRAY_A );

		return array( 'total' => $total, 'rows' => $rows ?: array() );
	}

	public static function purge( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . ' WHERE created_at < %s',
			gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
		) );
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
	}
}

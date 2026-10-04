<?php
/**
 * Client reports: settings, data gathering, archive, email delivery and
 * monthly scheduling. Rendering lives in Site_Manager_Report_Renderer.
 *
 * Reports draw on the activity log (updates, content, users, security),
 * WooCommerce sales, form submissions and a live site-health snapshot.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Report {

	const OPTION  = 'site_manager_report_settings';
	const ARCHIVE = 'site_manager_reports';
	const KEEP    = 36;

	/** Report sections in display order: key => label. */
	public static function sections() {
		return array(
			'summary'  => __( 'At a glance', 'site-manager' ),
			'updates'  => __( 'Software updates', 'site-manager' ),
			'plugins'  => __( 'Plugins & themes', 'site-manager' ),
			'content'  => __( 'Content', 'site-manager' ),
			'users'    => __( 'Users & logins', 'site-manager' ),
			'security' => __( 'Security events', 'site-manager' ),
			'store'    => __( 'Store (WooCommerce)', 'site-manager' ),
			'forms'    => __( 'Form submissions', 'site-manager' ),
			'health'   => __( 'Site health snapshot', 'site-manager' ),
		);
	}

	public static function defaults() {
		return array(
			'title'          => __( 'Monthly Website Report', 'site-manager' ),
			'agency_name'    => '',
			'agency_url'     => '',
			'agency_contact' => '',
			'logo_id'        => 0,
			'accent_color'   => '#2271b1',
			'paper'          => 'letter',
			'intro'          => '',
			'sections'       => array_keys( self::sections() ),
			'recipients'     => array(),
			'subject'        => '{title}: {site} — {period}',
			'reply_to'       => '',
			'schedule'       => 'off',
			'send_day'       => 3,
			'last_sent'      => '',
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Validate and save settings. Unknown keys are ignored.
	 *
	 * @return array|WP_Error The stored settings.
	 */
	public static function update_settings( array $values ) {
		$s = self::settings();
		foreach ( array( 'title', 'agency_name', 'agency_contact', 'subject' ) as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$s[ $key ] = sanitize_text_field( $values[ $key ] );
			}
		}
		if ( isset( $values['agency_url'] ) ) {
			$s['agency_url'] = esc_url_raw( $values['agency_url'] );
		}
		if ( isset( $values['intro'] ) ) {
			$s['intro'] = sanitize_textarea_field( $values['intro'] );
		}
		if ( isset( $values['logo_id'] ) ) {
			$id = (int) $values['logo_id'];
			if ( $id && ! wp_attachment_is_image( $id ) ) {
				return new WP_Error( 'invalid_logo', 'logo_id must be an image in the media library.' );
			}
			$s['logo_id'] = $id;
		}
		if ( isset( $values['accent_color'] ) ) {
			$color = sanitize_hex_color( $values['accent_color'] );
			if ( ! $color ) {
				return new WP_Error( 'invalid_color', 'accent_color must be a hex color like #2271b1.' );
			}
			$s['accent_color'] = $color;
		}
		if ( isset( $values['paper'] ) ) {
			$s['paper'] = $values['paper'] === 'a4' ? 'a4' : 'letter';
		}
		if ( isset( $values['sections'] ) ) {
			$s['sections'] = array_values( array_intersect( array_keys( self::sections() ), (array) $values['sections'] ) );
		}
		if ( isset( $values['recipients'] ) ) {
			$list = self::parse_recipients( $values['recipients'] );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			$s['recipients'] = $list;
		}
		if ( isset( $values['reply_to'] ) ) {
			$reply = trim( (string) $values['reply_to'] );
			if ( $reply !== '' && ! is_email( $reply ) ) {
				return new WP_Error( 'invalid_reply_to', 'reply_to must be an email address.' );
			}
			$s['reply_to'] = $reply;
		}
		if ( isset( $values['schedule'] ) ) {
			$s['schedule'] = $values['schedule'] === 'monthly' ? 'monthly' : 'off';
		}
		if ( isset( $values['send_day'] ) ) {
			$s['send_day'] = max( 1, min( 28, (int) $values['send_day'] ) );
		}
		update_option( self::OPTION, $s, false );
		return $s;
	}

	/**
	 * Accept an array or a comma/newline-separated string of addresses.
	 *
	 * @return string[]|WP_Error
	 */
	public static function parse_recipients( $input ) {
		$items = is_array( $input ) ? $input : preg_split( '/[\s,;]+/', (string) $input );
		$out   = array();
		$bad   = array();
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( $item === '' ) {
				continue;
			}
			if ( is_email( $item ) ) {
				$out[] = strtolower( $item );
			} else {
				$bad[] = $item;
			}
		}
		if ( $bad ) {
			return new WP_Error( 'invalid_recipients', 'Not valid email addresses: ' . implode( ', ', $bad ) );
		}
		return array_values( array_unique( $out ) );
	}

	// ---------------------------------------------------------------
	// Periods
	// ---------------------------------------------------------------

	/**
	 * Resolve a period from a YYYY-MM month, explicit dates, or the previous
	 * calendar month. Returns [after, before, label].
	 */
	public static function period( $month = '', $after = '', $before = '' ) {
		$tz = wp_timezone();
		if ( $month ) {
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
				return new WP_Error( 'bad_month', 'month must be YYYY-MM.' );
			}
			$first = new DateTime( $month . '-01', $tz );
		} elseif ( $after || $before ) {
			$after  = $after ? $after : wp_date( 'Y-m-01' );
			$before = $before ? $before : wp_date( 'Y-m-d' );
			$a      = new DateTime( $after, $tz );
			$b      = new DateTime( $before, $tz );
			return array( $a->format( 'Y-m-d' ), $b->format( 'Y-m-d' ), wp_date( 'M j, Y', $a->getTimestamp() ) . ' – ' . wp_date( 'M j, Y', $b->getTimestamp() ) );
		} else {
			$first = new DateTime( 'first day of last month', $tz );
		}
		return array( $first->format( 'Y-m-01' ), $first->format( 'Y-m-t' ), wp_date( 'F Y', $first->getTimestamp() ) );
	}

	// ---------------------------------------------------------------
	// Data
	// ---------------------------------------------------------------

	/**
	 * Everything the report shows, as plain data (also returned by MCP).
	 */
	public static function data( $after, $before, $label ) {
		$s        = self::settings();
		$activity = Site_Manager_Activity::report( $after, $before );

		$updates = array();
		foreach ( array( 'core' => 'WordPress', 'plugins_updated' => 'Plugin', 'themes_updated' => 'Theme' ) as $key => $kind ) {
			foreach ( $activity['updates'][ $key ] as $row ) {
				$d         = isset( $row['details'] ) ? $row['details'] : array();
				$updates[] = array(
					'date'      => substr( $row['time'], 0, 10 ),
					'type'      => $kind,
					'name'      => isset( $row['object'] ) ? $row['object'] : $kind,
					'from'      => isset( $d['from'] ) ? (string) $d['from'] : '',
					'to'        => isset( $d['to'] ) ? (string) $d['to'] : '',
					'automatic' => ! empty( $d['automatic'] ),
				);
			}
		}
		$translations = 0;
		foreach ( $activity['updates']['translations'] as $row ) {
			$translations += isset( $row['details']['items'] ) ? count( $row['details']['items'] ) : 1;
		}

		$changes = array();
		$labels  = array(
			'installed'   => __( 'Installed', 'site-manager' ),
			'activated'   => __( 'Activated', 'site-manager' ),
			'deactivated' => __( 'Deactivated', 'site-manager' ),
			'deleted'     => __( 'Removed', 'site-manager' ),
			'switched'    => __( 'Theme changed', 'site-manager' ),
		);
		foreach ( array( 'plugins' => 'Plugin', 'themes' => 'Theme' ) as $group => $kind ) {
			foreach ( $activity[ $group ] as $what => $rows ) {
				foreach ( $rows as $row ) {
					if ( isset( $row['object'] ) && $row['object'] === 'Site Manager' ) {
						continue; // Agency tooling, not part of the client's site.
					}
					$changes[] = array(
						'date'   => substr( $row['time'], 0, 10 ),
						'type'   => $kind,
						'name'   => isset( $row['object'] ) ? $row['object'] : '',
						'change' => isset( $labels[ $what ] ) ? $labels[ $what ] : $what,
						'by'     => $row['user'],
					);
				}
			}
		}
		usort( $changes, function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		} );

		$content = array();
		$totals  = array( 'created' => 0, 'published' => 0, 'updated' => 0, 'trashed' => 0, 'deleted' => 0 );
		foreach ( (array) $activity['content'] as $type => $actions ) {
			$obj   = get_post_type_object( $type );
			$row   = array( 'type' => $obj ? $obj->labels->name : $type );
			$total = 0;
			foreach ( array_keys( $totals ) as $a ) {
				$n          = isset( $actions[ $a ] ) ? (int) $actions[ $a ] : 0;
				$row[ $a ]  = $n;
				$totals[ $a ] += $n;
				$total     += $n;
			}
			if ( $total ) {
				$content[] = $row;
			}
		}
		$media = (int) Site_Manager_Activity::stats( 'action', array( 'after' => $after, 'before' => $before, 'category' => 'media', 'action' => 'uploaded' ), 1 )['total'];

		$logins = $activity['logins'];
		// Client-facing: leave out agency tooling (MCP, Site Manager settings)
		// and events already shown in their own sections.
		$covered  = array( 'login_failed', 'user_created', 'user_deleted', 'user_role_changed' );
		$security = array_values( array_filter( $activity['security'], function ( $row ) use ( $covered ) {
			return ! in_array( $row['action'], $covered, true ) && ! in_array( $row['category'], array( 'mcp', 'site_manager', 'plugin', 'theme' ), true );
		} ) );

		$data = array(
			'title'    => $s['title'],
			'agency'   => array( 'name' => $s['agency_name'], 'url' => $s['agency_url'], 'contact' => $s['agency_contact'] ),
			'site'     => array( 'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'url' => home_url() ),
			'period'   => array( 'label' => $label, 'from' => $after, 'to' => $before ),
			'generated' => wp_date( 'F j, Y' ),
			'intro'    => $s['intro'],
			'summary'  => array(
				'updates'         => count( $updates ),
				'content_changes' => array_sum( $totals ),
				'security_events' => count( $security ),
				'failed_logins'   => (int) $logins['failed_total'],
			),
			'updates'  => array( 'items' => $updates, 'translations' => $translations, 'failed' => $activity['updates']['failed'] ),
			'plugins'  => $changes,
			'content'  => array( 'by_type' => $content, 'totals' => $totals, 'media_uploads' => $media ),
			'users'    => array(
				'created'          => $activity['users']['created'],
				'deleted'          => $activity['users']['deleted'],
				'role_changes'     => $activity['users']['role_changes'],
				'password_changes' => count( $activity['users']['password_changes'] ),
				'logins_by_user'   => (array) $logins['successful_by_user'],
				'failed_logins'    => (int) $logins['failed_total'],
				'failed_by_ip'     => (array) $logins['failed_by_ip'],
			),
			'security' => array_slice( $security, 0, 30 ),
			'store'    => null,
			'forms'    => self::form_counts( $after, $before ),
			'health'   => self::health(),
		);

		if ( class_exists( 'Site_Manager_Tools_WooCommerce' ) && Site_Manager_Tools_WooCommerce::is_active() ) {
			$data['store'] = Site_Manager_Tools_WooCommerce::sales_summary( $after, $before, 5 );
		}
		return $data;
	}

	/** Submissions per form in the period (Gravity Forms, WPForms Pro, Flamingo). */
	private static function form_counts( $after, $before ) {
		$out = array();
		if ( class_exists( 'GFAPI' ) ) {
			foreach ( (array) GFAPI::get_forms() as $form ) {
				$n = GFAPI::count_entries( $form['id'], array( 'status' => 'active', 'start_date' => $after, 'end_date' => $before ) );
				if ( $n ) {
					$out[] = array( 'form' => $form['title'], 'plugin' => 'Gravity Forms', 'submissions' => (int) $n );
				}
			}
		}
		if ( function_exists( 'wpforms' ) && method_exists( wpforms(), 'is_pro' ) && wpforms()->is_pro() && method_exists( wpforms(), 'get' ) && wpforms()->get( 'entry' ) ) {
			foreach ( get_posts( array( 'post_type' => 'wpforms', 'numberposts' => -1, 'post_status' => 'publish' ) ) as $form ) {
				$n = (int) wpforms()->get( 'entry' )->get_entries( array( 'form_id' => $form->ID, 'date' => array( $after, $before ) ), true );
				if ( $n ) {
					$out[] = array( 'form' => $form->post_title, 'plugin' => 'WPForms', 'submissions' => $n );
				}
			}
		}
		if ( post_type_exists( 'flamingo_inbound' ) ) {
			$ids    = get_posts( array(
				'post_type'   => 'flamingo_inbound',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'date_query'  => array( array( 'after' => $after, 'before' => $before . ' 23:59:59', 'inclusive' => true ) ),
			) );
			$counts = array();
			foreach ( $ids as $id ) {
				$terms = wp_get_object_terms( $id, 'flamingo_inbound_channel', array( 'fields' => 'names' ) );
				$name  = ! is_wp_error( $terms ) && $terms ? $terms[0] : __( 'Contact form', 'site-manager' );
				$counts[ $name ] = ( isset( $counts[ $name ] ) ? $counts[ $name ] : 0 ) + 1;
			}
			foreach ( $counts as $name => $n ) {
				$out[] = array( 'form' => $name, 'plugin' => 'Contact Form 7', 'submissions' => $n );
			}
		}
		return $out;
	}

	/** Point-in-time health snapshot. */
	private static function health() {
		global $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );
		$core    = get_site_transient( 'update_core' );
		$latest  = null;
		if ( $core && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( isset( $u->response ) && $u->response === 'upgrade' ) {
					$latest = $u->current;
					break;
				}
			}
		}
		$theme = wp_get_theme();
		return array(
			'wordpress'         => $wp_version,
			'wordpress_latest'  => $latest === null,
			'wordpress_update'  => $latest,
			'php'               => PHP_VERSION,
			'active_plugins'    => count( (array) get_option( 'active_plugins', array() ) ),
			'plugin_updates'    => $plugins && ! empty( $plugins->response ) ? count( $plugins->response ) : 0,
			'theme'             => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'theme_updates'     => $themes && ! empty( $themes->response ) ? count( $themes->response ) : 0,
			'https'             => strpos( home_url(), 'https://' ) === 0,
			'search_visible'    => (bool) get_option( 'blog_public' ),
		);
	}

	// ---------------------------------------------------------------
	// Generation, archive, delivery
	// ---------------------------------------------------------------

	private static function dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'site-manager-reports';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Block direct access; reports are served through an admin-only handler.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
		}
		return $dir;
	}

	/**
	 * Build the PDF for a period. With $save, store it in the archive.
	 *
	 * @return array{data: array, pdf: string, entry: array|null}
	 */
	public static function generate( $after, $before, $label, $save = true, $note = '' ) {
		$data = self::data( $after, $before, $label );
		if ( $note !== '' ) {
			$data['note'] = $note;
		}
		$pdf   = Site_Manager_Report_Renderer::render( $data, self::settings() );
		$entry = null;
		if ( $save ) {
			$file  = sprintf( 'report-%s-%s.pdf', substr( $after, 0, 7 ), wp_generate_password( 16, false, false ) );
			file_put_contents( self::dir() . '/' . $file, $pdf );
			$entry = array(
				'id'           => wp_generate_uuid4(),
				'period'       => $label,
				'from'         => $after,
				'to'           => $before,
				'file'         => $file,
				'bytes'        => strlen( $pdf ),
				'generated_at' => current_time( 'mysql' ),
				'generated_by' => wp_get_current_user()->user_login ?: 'system',
				'sent_to'      => array(),
				'sent_at'      => '',
			);
			self::archive_add( $entry );
		}
		return array( 'data' => $data, 'pdf' => $pdf, 'entry' => $entry );
	}

	public static function archive() {
		$list = get_option( self::ARCHIVE, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function archive_get( $id ) {
		foreach ( self::archive() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	public static function archive_path( array $entry ) {
		return self::dir() . '/' . basename( $entry['file'] );
	}

	private static function archive_add( array $entry ) {
		$list = self::archive();
		array_unshift( $list, $entry );
		foreach ( array_slice( $list, self::KEEP ) as $old ) {
			wp_delete_file( self::archive_path( $old ) );
		}
		update_option( self::ARCHIVE, array_slice( $list, 0, self::KEEP ), false );
	}

	private static function archive_update( $id, array $changes ) {
		$list = self::archive();
		foreach ( $list as &$entry ) {
			if ( $entry['id'] === $id ) {
				$entry = array_merge( $entry, $changes );
			}
		}
		unset( $entry );
		update_option( self::ARCHIVE, $list, false );
	}

	public static function download_url( $id ) {
		return add_query_arg( array(
			'action'   => 'site_manager_report_download',
			'id'       => rawurlencode( $id ),
			'_wpnonce' => wp_create_nonce( 'site_manager_report_download' ),
		), admin_url( 'admin-post.php' ) );
	}

	private static function fill( $template, array $data ) {
		return strtr( $template, array(
			'{title}'  => $data['title'],
			'{site}'   => $data['site']['name'],
			'{period}' => $data['period']['label'],
			'{agency}' => $data['agency']['name'],
		) );
	}

	/**
	 * Email an archived report.
	 *
	 * @param string   $id         Archive entry ID.
	 * @param string[] $recipients Overrides the saved recipients when given.
	 * @return array|WP_Error
	 */
	public static function send( $id, array $recipients = array(), $note = '' ) {
		$entry = self::archive_get( $id );
		if ( ! $entry || ! file_exists( self::archive_path( $entry ) ) ) {
			return new WP_Error( 'not_found', 'Report not found in the archive.' );
		}
		$s  = self::settings();
		$to = $recipients ? $recipients : $s['recipients'];
		if ( ! $to ) {
			return new WP_Error( 'no_recipients', 'No recipients configured. Add them in Settings → Site Manager → Reports or pass recipients.' );
		}

		$data    = self::data( $entry['from'], $entry['to'], $entry['period'] );
		$subject = self::fill( $s['subject'] ?: self::defaults()['subject'], $data );
		$body    = Site_Manager_Report_Renderer::email_html( $data, $s, $note );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $s['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $s['reply_to'];
		}

		// Attach under a friendly name (the archive file name is randomized).
		$tmp_dir  = get_temp_dir() . 'sm-report-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $tmp_dir );
		$friendly = $tmp_dir . '/' . sanitize_file_name( $data['site']['name'] . ' - ' . $data['title'] . ' - ' . $entry['period'] ) . '.pdf';
		copy( self::archive_path( $entry ), $friendly );

		$from_name = function ( $name ) use ( $s ) {
			return $s['agency_name'] ? $s['agency_name'] : $name;
		};
		$error = null;
		$catch = function ( $e ) use ( &$error ) {
			$error = $e;
		};
		add_filter( 'wp_mail_from_name', $from_name );
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( $to, $subject, $body, $headers, array( $friendly ) );
		remove_filter( 'wp_mail_from_name', $from_name );
		remove_action( 'wp_mail_failed', $catch );
		wp_delete_file( $friendly );
		@rmdir( $tmp_dir );

		if ( ! $sent ) {
			return $error instanceof WP_Error ? $error : new WP_Error( 'mail_failed', 'wp_mail() could not send the report.' );
		}
		self::archive_update( $id, array( 'sent_to' => $to, 'sent_at' => current_time( 'mysql' ) ) );
		Site_Manager_Activity::log( array(
			'category'    => 'site_manager',
			'action'      => 'report_sent',
			'severity'    => 'notice',
			'object_type' => 'report',
			'object_id'   => $id,
			'object_name' => $data['title'] . ' — ' . $entry['period'],
			'message'     => sprintf( 'Report for %s emailed to %s.', $entry['period'], implode( ', ', $to ) ),
			'details'     => array( 'recipients' => $to, 'subject' => $subject ),
		) );
		return array( 'sent' => true, 'to' => $to, 'subject' => $subject, 'report' => self::archive_get( $id ) );
	}

	/**
	 * Daily cron: on/after the configured day, send last month's report once.
	 */
	public static function maybe_send_scheduled() {
		$s = self::settings();
		if ( $s['schedule'] !== 'monthly' || ! $s['recipients'] ) {
			return;
		}
		if ( (int) wp_date( 'j' ) < (int) $s['send_day'] ) {
			return;
		}
		list( $after, $before, $label ) = self::period();
		if ( $s['last_sent'] === substr( $after, 0, 7 ) ) {
			return;
		}
		// Record the attempt first so a mail failure doesn't resend daily.
		$s['last_sent'] = substr( $after, 0, 7 );
		update_option( self::OPTION, $s, false );

		$report = self::generate( $after, $before, $label, true );
		$result = self::send( $report['entry']['id'] );
		if ( is_wp_error( $result ) ) {
			Site_Manager_Activity::log( array(
				'category'    => 'site_manager',
				'action'      => 'report_send_failed',
				'severity'    => 'warning',
				'object_type' => 'report',
				'object_id'   => $report['entry']['id'],
				'object_name' => $label,
				'message'     => sprintf( 'Scheduled report for %s could not be emailed: %s', $label, $result->get_error_message() ),
			) );
		}
	}
}

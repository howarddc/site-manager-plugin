<?php
/**
 * MCP tools: cron, caches, transients, rewrite rules, email and the debug log.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Maintenance {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'maintenance', __( 'Maintenance', 'site-manager' ), __( 'Scheduled tasks (cron), cache and transient flushing, rewrite rules, test email, debug log.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'cron_list', array(
			'category'     => 'maintenance',
			'description'  => 'List scheduled cron events (hook, next run, schedule, args) and the available schedules.',
			'input_schema' => $s::obj( array( 'hook' => $s::str( 'Filter by hook name substring.' ) ) ),
			'handler'      => array( $this, 'cron_list' ),
		) );

		$r->register( 'cron_run', array(
			'category'     => 'maintenance',
			'description'  => 'Run a cron hook immediately (fires its callbacks now with the given args). Does not change its schedule.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'hook' => $s::str(),
				'args' => $s::arr( new stdClass(), 'Arguments exactly as listed by cron_list.' ),
			), array( 'hook' ) ),
			'handler'      => array( $this, 'cron_run' ),
		) );

		$r->register( 'cron_unschedule', array(
			'category'     => 'maintenance',
			'description'  => 'Remove every scheduled occurrence of a cron hook (optionally only those with matching args).',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'hook' => $s::str(),
				'args' => $s::arr( new stdClass(), 'Only unschedule events with these args.' ),
			), array( 'hook' ) ),
			'handler'      => array( $this, 'cron_unschedule' ),
		) );

		$r->register( 'cache_flush', array(
			'category'     => 'maintenance',
			'description'  => 'Flush the object cache, and — when known caching plugins are active — their page caches (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed, SiteGround, WP Fastest Cache, Autoptimize).',
			'writes'       => true,
			'handler'      => array( $this, 'cache_flush' ),
		) );

		$r->register( 'transients_delete', array(
			'category'     => 'maintenance',
			'description'  => 'Delete expired transients (default), all transients, or a single transient by name.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'scope' => $s::enum( array( 'expired', 'all', 'one' ), '', 'expired' ),
				'name'  => $s::str( 'Transient name for scope=one.' ),
			) ),
			'handler'      => array( $this, 'transients_delete' ),
		) );

		$r->register( 'rewrite_flush', array(
			'category'     => 'maintenance',
			'description'  => 'Flush rewrite rules (fixes 404s on custom post types / after permalink changes).',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'hard' => $s::bool( 'Also rewrite .htaccess / web.config.', false ) ) ),
			'handler'      => array( $this, 'rewrite_flush' ),
		) );

		$r->register( 'email_send', array(
			'category'     => 'maintenance',
			'description'  => 'Send an email through WordPress (wp_mail) — e.g. to test that site email works. Confirm recipients with the user first.',
			'writes'       => true,
			'open_world'   => true,
			'input_schema' => $s::obj( array(
				'to'      => $s::arr( 'string' ),
				'subject' => $s::str(),
				'body'    => $s::str(),
				'html'    => $s::bool( 'Send as HTML.', false ),
			), array( 'to', 'subject', 'body' ) ),
			'handler'      => array( $this, 'email_send' ),
		) );

		$r->register( 'debug_log_read', array(
			'category'     => 'maintenance',
			'description'  => 'Read the last lines of the PHP / WordPress debug log (wp-content/debug.log or the WP_DEBUG_LOG path), optionally filtered.',
			'input_schema' => $s::obj( array(
				'lines'  => $s::int( '', array( 'default' => 100, 'maximum' => 2000 ) ),
				'filter' => $s::str( 'Only lines containing this text.' ),
			) ),
			'handler'      => array( $this, 'debug_log_read' ),
		) );
	}

	public function cron_list( array $args ) {
		$filter = (string) Site_Manager_Helpers::arg( $args, 'hook', '' );
		$events = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $instances ) {
				if ( $filter !== '' && strpos( $hook, $filter ) === false ) {
					continue;
				}
				foreach ( $instances as $event ) {
					$events[] = array(
						'hook'     => $hook,
						'next_run' => wp_date( 'Y-m-d H:i:s', $timestamp ),
						'in'       => human_time_diff( time(), $timestamp ) . ( $timestamp < time() ? ' ago (overdue)' : '' ),
						'schedule' => $event['schedule'] ?: 'once',
						'args'     => $event['args'],
					);
				}
			}
		}
		return array(
			'events'      => $events,
			'schedules'   => wp_get_schedules(),
			'cron_status' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron disabled (system cron expected)' : 'WP-Cron enabled',
		);
	}

	public function cron_run( array $args ) {
		$hook = (string) $args['hook'];
		if ( ! has_action( $hook ) ) {
			return new WP_Error( 'no_callbacks', sprintf( 'No callbacks are attached to "%s".', $hook ) );
		}
		ob_start();
		$started = microtime( true );
		do_action_ref_array( $hook, (array) Site_Manager_Helpers::arg( $args, 'args', array() ) );
		$output = ob_get_clean();
		return array(
			'hook'        => $hook,
			'ran'         => true,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'output'      => $output !== '' ? substr( $output, 0, 5000 ) : null,
		);
	}

	public function cron_unschedule( array $args ) {
		$hook = (string) $args['hook'];
		if ( array_key_exists( 'args', $args ) && $args['args'] !== null ) {
			$count = wp_clear_scheduled_hook( $hook, (array) $args['args'] );
		} else {
			$count = wp_unschedule_hook( $hook );
		}
		return is_wp_error( $count ) ? $count : array( 'hook' => $hook, 'removed' => (int) $count );
	}

	public function cache_flush() {
		$done = array( 'object_cache' => wp_cache_flush() );

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done['wp_rocket'] = true;
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done['w3_total_cache'] = true;
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done['wp_super_cache'] = true;
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$done['litespeed'] = true;
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$done['siteground'] = true;
		}
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->deleteCache( true );
			$done['wp_fastest_cache'] = true;
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
			$done['autoptimize'] = true;
		}
		return $done;
	}

	public function transients_delete( array $args ) {
		global $wpdb;
		$scope = Site_Manager_Helpers::arg( $args, 'scope', 'expired' );
		if ( $scope === 'one' ) {
			if ( empty( $args['name'] ) ) {
				return new WP_Error( 'missing_name', 'name is required for scope=one.' );
			}
			return array( 'deleted' => delete_transient( (string) $args['name'] ) || delete_site_transient( (string) $args['name'] ) );
		}
		if ( $scope === 'all' ) {
			$count = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
			wp_cache_flush();
			return array( 'deleted_rows' => (int) $count );
		}
		delete_expired_transients( true );
		return array( 'expired_deleted' => true );
	}

	public function rewrite_flush( array $args ) {
		flush_rewrite_rules( Site_Manager_Helpers::bool( $args, 'hard' ) );
		return array( 'flushed' => true, 'permalink_structure' => get_option( 'permalink_structure' ) ?: 'plain' );
	}

	public function email_send( array $args ) {
		$to      = array_filter( array_map( 'sanitize_email', (array) $args['to'] ) );
		$headers = Site_Manager_Helpers::bool( $args, 'html' ) ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();
		if ( ! $to ) {
			return new WP_Error( 'invalid_to', 'No valid recipient addresses.' );
		}
		$error = null;
		$catch = function ( $e ) use ( &$error ) {
			$error = $e;
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( $to, (string) $args['subject'], (string) $args['body'], $headers );
		remove_action( 'wp_mail_failed', $catch );
		if ( ! $sent ) {
			return $error instanceof WP_Error ? $error : new WP_Error( 'mail_failed', 'wp_mail() returned false.' );
		}
		return array( 'sent' => true, 'to' => array_values( $to ) );
	}

	public static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG !== '' && ! in_array( strtolower( WP_DEBUG_LOG ), array( 'true', '1' ), true ) ) {
			return WP_DEBUG_LOG;
		}
		$ini = ini_get( 'error_log' );
		$default = WP_CONTENT_DIR . '/debug.log';
		return file_exists( $default ) || ! $ini ? $default : $ini;
	}

	public function debug_log_read( array $args ) {
		$path  = self::debug_log_path();
		$lines = max( 1, min( 2000, (int) Site_Manager_Helpers::arg( $args, 'lines', 100 ) ) );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'no_log', 'No readable debug log found. Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php to start logging.' );
		}
		$filter = (string) Site_Manager_Helpers::arg( $args, 'filter', '' );
		$size   = filesize( $path );
		$fh     = fopen( $path, 'rb' );
		// Read at most the last 2 MB — enough for thousands of lines.
		$chunk = min( $size, 2 * MB_IN_BYTES );
		fseek( $fh, -$chunk, SEEK_END );
		$data = fread( $fh, $chunk );
		fclose( $fh );

		$all = preg_split( '/\r?\n/', rtrim( (string) $data ) );
		if ( $chunk < $size ) {
			array_shift( $all ); // Probably a partial line.
		}
		if ( $filter !== '' ) {
			$all = array_values( array_filter( $all, function ( $l ) use ( $filter ) {
				return stripos( $l, $filter ) !== false;
			} ) );
		}
		return array(
			'path'  => $path,
			'size'  => size_format( $size ),
			'lines' => array_slice( $all, -$lines ),
		);
	}
}

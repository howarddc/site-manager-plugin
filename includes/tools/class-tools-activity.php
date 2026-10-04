<?php
/**
 * MCP tools: site activity log (audit trail) and reports.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Activity {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'activity', __( 'Activity log', 'site-manager' ), __( 'Search the site-wide audit log and build activity reports.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'The site keeps an activity log of every detectable action (logins, failed logins, user and role changes, plugin/theme/core updates with versions, content changes, settings, file edits, exports, MCP tool runs). Use activity_report for monthly reports and audits and activity_log_query to investigate. Dates are in the site timezone.';
			return $lines;
		} );

		$r->register( 'activity_log_query', array(
			'category'     => 'activity',
			'description'  => 'Search the activity log, newest first. Filter by date range, user, category (auth, user, plugin, theme, core, post, media, taxonomy, menu, comment, setting, file, export, privacy, mcp, site_manager, woocommerce, form, redirect), action (e.g. login_failed, plugin_updated, published), severity, source (admin, rest, mcp, cron, cli, login, web, ajax, xmlrpc), object, IP or free text. Repeated events (e.g. failed-login floods) are folded into one row with an occurrences count.',
			'input_schema' => $s::obj( array(
				'after'        => $s::str( 'From date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, site time).' ),
				'before'       => $s::str( 'To date, inclusive.' ),
				'user'         => $s::any( 'User ID or login of the actor.' ),
				'category'     => $s::any( 'Category or array of categories.' ),
				'action'       => $s::any( 'Action or array of actions.' ),
				'severity'     => $s::any( 'info | notice | warning | critical, or an array.' ),
				'min_severity' => $s::enum( Site_Manager_Activity::SEVERITIES, 'Only this severity and above.' ),
				'source'       => $s::any( 'Source or array of sources.' ),
				'object_type'  => $s::str( 'e.g. plugin, user, post, page, option.' ),
				'object_id'    => $s::str( 'e.g. a post ID, user ID or plugin file.' ),
				'ip'           => $s::str(),
				'search'       => $s::str( 'Matches message, object name, user login or IP.' ),
				'order'        => $s::enum( array( 'DESC', 'ASC' ), '', 'DESC' ),
				'page'         => $s::page(),
				'per_page'     => $s::per_page( 50, 500 ),
			) ),
			'handler'      => array( $this, 'query' ),
		) );

		$r->register( 'activity_report', array(
			'category'     => 'activity',
			'description'  => 'Summarize activity for a period — defaults to last calendar month. Returns: core/plugin/theme/translation updates with old → new versions and whether automatic, failed updates; plugins and themes installed/activated/deactivated/deleted/switched; users created/deleted/role changes/password changes; successful logins by user and failed logins by username and IP; content changes by post type; settings changes; all warning/critical security events; MCP tool usage; WooCommerce order status changes and refunds; and totals by category, severity and source. Use this to write monthly client reports and security audits.',
			'input_schema' => $s::obj( array(
				'after'  => $s::str( 'Period start YYYY-MM-DD (default: first day of last month).' ),
				'before' => $s::str( 'Period end YYYY-MM-DD, inclusive (default: last day of last month).' ),
				'month'  => $s::str( 'Shortcut: YYYY-MM, e.g. "2026-09".' ),
			) ),
			'handler'      => array( $this, 'report' ),
		) );

		$r->register( 'activity_stats', array(
			'category'     => 'activity',
			'description'  => 'Count activity grouped by a dimension (category, action, user_login, source, severity, ip, object_type, object_name, or day) for a period and optional filters — e.g. "failed logins per IP this week" or "content updates per day".',
			'input_schema' => $s::obj( array(
				'group_by'     => $s::enum( array( 'category', 'action', 'user_login', 'source', 'severity', 'ip', 'object_type', 'object_name', 'day' ), '', 'action' ),
				'after'        => $s::str(),
				'before'       => $s::str(),
				'category'     => $s::any(),
				'action'       => $s::any(),
				'min_severity' => $s::enum( Site_Manager_Activity::SEVERITIES ),
				'limit'        => $s::int( '', array( 'default' => 50, 'maximum' => 1000 ) ),
			) ),
			'handler'      => array( $this, 'stats' ),
		) );
	}

	private static function filters( array $args ) {
		$f = array_intersect_key( $args, array_flip( array( 'after', 'before', 'category', 'action', 'severity', 'min_severity', 'source', 'object_type', 'object_id', 'ip', 'search', 'order' ) ) );
		if ( isset( $args['user'] ) && $args['user'] !== '' ) {
			if ( is_numeric( $args['user'] ) ) {
				$f['user_id'] = (int) $args['user'];
			} else {
				$f['user_login'] = (string) $args['user'];
			}
		}
		return $f;
	}

	public function query( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 50, 500 );
		$result   = Site_Manager_Activity::query( self::filters( $args ), $page, $per_page );
		return Site_Manager_Helpers::paged( array_map( array( 'Site_Manager_Activity', 'format_row' ), $result['rows'] ), $result['total'], $page, $per_page );
	}

	public function report( array $args ) {
		$after  = (string) Site_Manager_Helpers::arg( $args, 'after', '' );
		$before = (string) Site_Manager_Helpers::arg( $args, 'before', '' );
		if ( ! empty( $args['month'] ) ) {
			if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $args['month'] ) ) {
				return new WP_Error( 'bad_month', 'month must be YYYY-MM.' );
			}
			$first  = new DateTime( $args['month'] . '-01', wp_timezone() );
			$after  = $first->format( 'Y-m-01' );
			$before = $first->format( 'Y-m-t' );
		}
		return Site_Manager_Activity::report( $after, $before );
	}

	public function stats( array $args ) {
		return Site_Manager_Activity::stats(
			(string) Site_Manager_Helpers::arg( $args, 'group_by', 'action' ),
			self::filters( $args ),
			max( 1, min( 1000, (int) Site_Manager_Helpers::arg( $args, 'limit', 50 ) ) )
		);
	}
}

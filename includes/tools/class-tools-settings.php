<?php
/**
 * MCP tools: site settings (Settings screens) and raw options.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Settings {

	/**
	 * Settings screens → option names. Mirrors wp-admin/options.php's
	 * allowed options for the core settings pages.
	 */
	public static function groups() {
		return array(
			'general'    => array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'WPLANG', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'site_icon' ),
			'writing'    => array( 'default_category', 'default_post_format', 'use_smilies', 'default_pingback_flag', 'mailserver_url', 'mailserver_port', 'mailserver_login', 'default_email_category', 'ping_sites' ),
			'reading'    => array( 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'blog_public' ),
			'discussion' => array( 'default_ping_status', 'default_comment_status', 'require_name_email', 'comment_registration', 'close_comments_for_old_posts', 'close_comments_days_old', 'show_comments_cookies_opt_in', 'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order', 'comments_notify', 'moderation_notify', 'comment_moderation', 'comment_previously_approved', 'comment_max_links', 'moderation_keys', 'disallowed_keys', 'show_avatars', 'avatar_rating', 'avatar_default' ),
			'media'      => array( 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h', 'uploads_use_yearmonth_folders' ),
			'permalinks' => array( 'permalink_structure', 'category_base', 'tag_base' ),
			'privacy'    => array( 'wp_page_for_privacy_policy' ),
		);
	}

	/** Options whose change can lock everyone out of the site. */
	const DANGEROUS = array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'site_manager_settings' );

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'settings', __( 'Settings & options', 'site-manager' ), __( 'Settings screens (general, reading, discussion, media, permalinks) and any option in the options table.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'settings_get', array(
			'category'     => 'settings',
			'description'  => 'Read WordPress settings grouped by Settings screen: general, writing, reading, discussion, media, permalinks, privacy. Omit group for all.',
			'input_schema' => $s::obj( array(
				'group' => $s::enum( array_keys( self::groups() ) ),
			) ),
			'handler'      => array( $this, 'settings_get' ),
		) );

		$r->register( 'settings_update', array(
			'category'     => 'settings',
			'description'  => 'Update core settings by option name, e.g. {"blogname": "Acme", "posts_per_page": 12, "show_on_front": "page", "page_on_front": 42}. Only options on the core Settings screens are accepted; use option_update for anything else. Changing permalink_structure flushes rewrite rules. siteurl/home require confirm_url_change=true.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'settings'           => $s::map(),
				'confirm_url_change' => $s::bool( 'Required to change siteurl or home.', false ),
			), array( 'settings' ) ),
			'handler'      => array( $this, 'settings_update' ),
		) );

		$r->register( 'options_list', array(
			'category'     => 'settings',
			'description'  => 'Search the options table by name (SQL LIKE pattern or substring). Returns names, autoload flag and value size — use option_get for values. Useful for finding plugin settings.',
			'input_schema' => $s::obj( array(
				'search'              => $s::str( 'Substring of the option name, e.g. "woocommerce_" or "elementor".' ),
				'include_transients'  => $s::bool( '', false ),
				'autoload_only'       => $s::bool( '', false ),
				'page'                => $s::page(),
				'per_page'            => $s::per_page( 100, 500 ),
			) ),
			'handler'      => array( $this, 'options_list' ),
		) );

		$r->register( 'option_get', array(
			'category'     => 'settings',
			'description'  => 'Read one or more options by name. Serialized values are returned as JSON.',
			'input_schema' => $s::obj( array(
				'names' => $s::arr( 'string' ),
			), array( 'names' ) ),
			'handler'      => array( $this, 'option_get' ),
		) );

		$r->register( 'option_update', array(
			'category'     => 'settings',
			'description'  => 'Create or update any option. For array options, set merge=true to merge keys into the existing value instead of replacing it. Changing siteurl, home, active_plugins, template or stylesheet requires confirm=true.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'name'     => $s::str(),
				'value'    => $s::any( 'Any JSON value.' ),
				'merge'    => $s::bool( 'Shallow-merge into an existing array value.', false ),
				'autoload' => $s::bool( 'Autoload flag (only applied when given).' ),
				'confirm'  => $s::bool( 'Required for site-critical options.', false ),
			), array( 'name', 'value' ) ),
			'handler'      => array( $this, 'option_update' ),
		) );

		$r->register( 'option_delete', array(
			'category'     => 'settings',
			'description'  => 'Delete an option.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'name'    => $s::str(),
				'confirm' => $s::bool( 'Required for site-critical options.', false ),
			), array( 'name' ) ),
			'handler'      => array( $this, 'option_delete' ),
		) );
	}

	public function settings_get( array $args ) {
		$groups = self::groups();
		if ( ! empty( $args['group'] ) ) {
			$groups = array_intersect_key( $groups, array( $args['group'] => true ) );
		}
		$out = array();
		foreach ( $groups as $group => $names ) {
			foreach ( $names as $name ) {
				$out[ $group ][ $name ] = get_option( $name );
			}
		}
		return $out;
	}

	public function settings_update( array $args ) {
		$allowed = call_user_func_array( 'array_merge', array_values( self::groups() ) );
		$values  = (array) $args['settings'];
		$updated = array();
		$errors  = array();

		foreach ( $values as $name => $value ) {
			if ( ! in_array( $name, $allowed, true ) ) {
				$errors[ $name ] = 'Not a core setting; use option_update.';
				continue;
			}
			if ( in_array( $name, array( 'siteurl', 'home' ), true ) && ! Site_Manager_Helpers::bool( $args, 'confirm_url_change' ) ) {
				$errors[ $name ] = 'Changing the site URL can make the site unreachable. Pass confirm_url_change=true.';
				continue;
			}
			// sanitize_option applies the same rules as the Settings screens.
			$clean = sanitize_option( $name, $value );
			update_option( $name, $clean );
			$updated[ $name ] = get_option( $name );
		}

		if ( isset( $updated['permalink_structure'] ) || isset( $updated['category_base'] ) || isset( $updated['tag_base'] ) ) {
			flush_rewrite_rules( false );
		}
		return array( 'updated' => (object) $updated, 'errors' => (object) $errors );
	}

	public function options_list( array $args ) {
		global $wpdb;
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 100, 500 );
		$where    = array( '1=1' );
		$params   = array();
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! Site_Manager_Helpers::bool( $args, 'include_transients' ) ) {
			$where[] = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}
		if ( Site_Manager_Helpers::bool( $args, 'autoload_only' ) ) {
			$where[] = "autoload IN ('yes','on','auto-on','auto')";
		}
		$where_sql = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where_sql}", $params ) : "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where_sql}" );
		$rows      = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name AS name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE {$where_sql} ORDER BY option_name LIMIT %d OFFSET %d",
			array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
		), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['bytes'] = (int) $row['bytes'];
		}
		return Site_Manager_Helpers::paged( $rows, $total, $page, $per_page );
	}

	public function option_get( array $args ) {
		$out = array();
		foreach ( (array) $args['names'] as $name ) {
			$name         = (string) $name;
			$sentinel     = new stdClass();
			$value        = get_option( $name, $sentinel );
			$out[ $name ] = $value === $sentinel ? array( 'exists' => false ) : array( 'exists' => true, 'value' => $value );
		}
		return $out;
	}

	public function option_update( array $args ) {
		$name = (string) $args['name'];
		if ( in_array( $name, self::DANGEROUS, true ) && ! Site_Manager_Helpers::bool( $args, 'confirm' ) ) {
			return new WP_Error( 'confirm_required', sprintf( 'Changing "%s" can break the site. Pass confirm=true if you are sure.', $name ) );
		}
		$value = $args['value'];
		if ( Site_Manager_Helpers::bool( $args, 'merge' ) ) {
			$current = get_option( $name, array() );
			if ( ! is_array( $current ) || ! is_array( $value ) ) {
				return new WP_Error( 'merge_type', 'merge=true requires both the existing and new values to be objects/arrays.' );
			}
			$value = array_merge( $current, $value );
		}
		$autoload = array_key_exists( 'autoload', $args ) && $args['autoload'] !== null ? (bool) $args['autoload'] : null;
		update_option( $name, $value, $autoload );
		return array( 'name' => $name, 'value' => get_option( $name ) );
	}

	public function option_delete( array $args ) {
		$name = (string) $args['name'];
		if ( in_array( $name, self::DANGEROUS, true ) && ! Site_Manager_Helpers::bool( $args, 'confirm' ) ) {
			return new WP_Error( 'confirm_required', sprintf( 'Deleting "%s" can break the site. Pass confirm=true if you are sure.', $name ) );
		}
		return array( 'name' => $name, 'deleted' => delete_option( $name ) );
	}
}

<?php
/**
 * MCP tools: site overview, Site Health, and global search.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Site {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'site', __( 'Site overview', 'site-manager' ), __( 'Site summary, Site Health report, search across all content.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'site_info', array(
			'category'     => 'site',
			'description'  => 'Overview of the site: name, URLs, WordPress/PHP/database versions, active theme, active plugins, post types, taxonomies, content counts, permalink structure, timezone, multisite and debug flags, and which integrations (ACF, Yoast) are active. Call this first.',
			'handler'      => array( $this, 'site_info' ),
		) );

		$r->register( 'site_health', array(
			'category'     => 'site',
			'description'  => 'The full Site Health "Info" report (server, database, constants, filesystem permissions, media handling, theme and plugins). Pass sections to limit output, e.g. ["wp-server","wp-database","wp-constants"].',
			'input_schema' => $s::obj( array(
				'sections' => $s::arr( 'string' ),
			) ),
			'handler'      => array( $this, 'site_health' ),
		) );

		$r->register( 'site_search', array(
			'category'     => 'site',
			'description'  => 'Search titles and content across every post type (including drafts and private), plus terms and users, in one call.',
			'input_schema' => $s::obj( array(
				'query' => $s::str(),
				'limit' => $s::int( 'Max results per kind.', array( 'default' => 20 ) ),
			), array( 'query' ) ),
			'handler'      => array( $this, 'site_search' ),
		) );
	}

	public function site_info() {
		global $wpdb, $wp_version;
		Site_Manager_Helpers::load_admin_includes();

		$theme   = wp_get_theme();
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$plugins[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'] );
			}
		}

		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			$count = wp_count_posts( $pt->name );
			$types[ $pt->name ] = array(
				'label'   => $pt->label,
				'builtin' => (bool) $pt->_builtin,
				'publish' => isset( $count->publish ) ? (int) $count->publish : 0,
				'draft'   => isset( $count->draft ) ? (int) $count->draft : 0,
			);
		}
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$taxonomies[ $tax->name ] = array( 'label' => $tax->label, 'object_types' => $tax->object_type );
		}

		$users = count_users();

		return array(
			'name'               => get_bloginfo( 'name' ),
			'description'        => get_bloginfo( 'description' ),
			'home_url'           => home_url(),
			'site_url'           => site_url(),
			'admin_url'          => admin_url(),
			'admin_email'        => get_option( 'admin_email' ),
			'language'           => get_locale(),
			'timezone'           => wp_timezone_string(),
			'wordpress_version'  => $wp_version,
			'php_version'        => PHP_VERSION,
			'database'           => $wpdb->db_server_info(),
			'table_prefix'       => $wpdb->prefix,
			'multisite'          => is_multisite(),
			'permalink'          => get_option( 'permalink_structure' ) ?: 'plain',
			'front_page'         => get_option( 'show_on_front' ) === 'page' ? array( 'page_on_front' => (int) get_option( 'page_on_front' ), 'page_for_posts' => (int) get_option( 'page_for_posts' ) ) : 'latest posts',
			'search_engines'     => get_option( 'blog_public' ) ? 'visible' : 'discouraged',
			'theme'              => array(
				'name'        => $theme->get( 'Name' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'version'     => $theme->get( 'Version' ),
				'parent'      => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
				'block_theme' => $theme->is_block_theme(),
			),
			'active_plugins'     => $plugins,
			'post_types'         => $types,
			'taxonomies'         => $taxonomies,
			'users'              => array( 'total' => (int) $users['total_users'], 'by_role' => $users['avail_roles'] ),
			'integrations'       => array(
				'acf'   => Site_Manager_Tools_ACF::is_active() ? Site_Manager_Tools_ACF::version() : false,
				'yoast' => Site_Manager_Tools_Yoast::is_active() ? Site_Manager_Tools_Yoast::version() : false,
			),
			'debug'              => array(
				'WP_DEBUG'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG'       => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
				'DISALLOW_FILE_EDIT' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'DISALLOW_FILE_MODS' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
				'environment'        => wp_get_environment_type(),
			),
			'site_manager'       => array(
				'version'       => SITE_MANAGER_VERSION,
				'tools'         => count( Site_Manager_Registry::instance()->available() ),
				'gates_enabled' => array_values( array_filter( array_keys( Site_Manager_Settings::gates() ), array( 'Site_Manager_Settings', 'gate_open' ) ) ),
			),
		);
	}

	public function site_health( array $args ) {
		Site_Manager_Helpers::load_admin_includes();
		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		$data     = WP_Debug_Data::debug_data();
		$sections = (array) Site_Manager_Helpers::arg( $args, 'sections', array() );
		$out      = array();
		foreach ( $data as $key => $section ) {
			if ( $sections && ! in_array( $key, $sections, true ) ) {
				continue;
			}
			$fields = array();
			foreach ( $section['fields'] as $field_key => $field ) {
				$fields[ $field_key ] = isset( $field['debug'] ) ? $field['debug'] : $field['value'];
			}
			$out[ $key ] = array( 'label' => $section['label'], 'fields' => $fields );
		}
		return $out;
	}

	public function site_search( array $args ) {
		$q     = (string) $args['query'];
		$limit = max( 1, min( 100, (int) Site_Manager_Helpers::arg( $args, 'limit', 20 ) ) );

		$posts = get_posts( array(
			's'                => $q,
			'post_type'        => array_values( get_post_types( array( 'show_ui' => true ) ) ),
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => $limit,
			'suppress_filters' => false,
		) );
		$terms = get_terms( array(
			'taxonomy'   => array_values( get_taxonomies( array( 'show_ui' => true ) ) ),
			'search'     => $q,
			'number'     => $limit,
			'hide_empty' => false,
		) );
		$users = get_users( array( 'search' => '*' . $q . '*', 'number' => $limit ) );

		return array(
			'posts' => array_map( array( 'Site_Manager_Helpers', 'post_summary' ), $posts ),
			'terms' => is_wp_error( $terms ) ? array() : array_map( function ( $t ) {
				return Site_Manager_Helpers::term_detail( $t, false );
			}, $terms ),
			'users' => array_map( array( 'Site_Manager_Helpers', 'user_summary' ), $users ),
		);
	}
}

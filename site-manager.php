<?php
/**
 * Plugin Name:       Site Manager
 * Plugin URI:        https://howard.ai
 * Description:       Admin-only MCP server that exposes WordPress to Claude and other MCP clients: content and custom post types, custom fields, media, users, comments, settings, menus, themes, plugins, ACF and Yoast SEO.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Rob Howard
 * Author URI:        https://howard.ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-manager
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITE_MANAGER_VERSION', '0.1.0' );
define( 'SITE_MANAGER_DB_VERSION', '1' );
define( 'SITE_MANAGER_FILE', __FILE__ );
define( 'SITE_MANAGER_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-log.php';
require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-helpers.php';
require_once __DIR__ . '/includes/mcp/class-registry.php';
require_once __DIR__ . '/includes/mcp/class-server.php';
require_once __DIR__ . '/includes/oauth/class-oauth-store.php';
require_once __DIR__ . '/includes/oauth/class-oauth-discovery.php';
require_once __DIR__ . '/includes/oauth/class-oauth.php';
require_once __DIR__ . '/includes/admin/class-admin.php';

require_once __DIR__ . '/includes/tools/class-tools-site.php';
require_once __DIR__ . '/includes/tools/class-tools-content.php';
require_once __DIR__ . '/includes/tools/class-tools-taxonomies.php';
require_once __DIR__ . '/includes/tools/class-tools-media.php';
require_once __DIR__ . '/includes/tools/class-tools-users.php';
require_once __DIR__ . '/includes/tools/class-tools-comments.php';
require_once __DIR__ . '/includes/tools/class-tools-settings.php';
require_once __DIR__ . '/includes/tools/class-tools-menus.php';
require_once __DIR__ . '/includes/tools/class-tools-appearance.php';
require_once __DIR__ . '/includes/tools/class-tools-plugins.php';
require_once __DIR__ . '/includes/tools/class-tools-maintenance.php';
require_once __DIR__ . '/includes/tools/class-tools-developer.php';
require_once __DIR__ . '/includes/integrations/class-tools-acf.php';
require_once __DIR__ . '/includes/integrations/class-tools-yoast.php';

/**
 * Create / upgrade custom tables. Runs on activation and whenever the stored
 * schema version is behind (activation hooks don't fire on zip-replace updates).
 */
function site_manager_install() {
	Site_Manager_OAuth_Store::create_tables();
	Site_Manager_Log::create_table();
	update_option( 'site_manager_db_version', SITE_MANAGER_DB_VERSION, false );
	if ( ! wp_next_scheduled( 'site_manager_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'site_manager_daily' );
	}
}
register_activation_hook( __FILE__, 'site_manager_install' );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'site_manager_daily' );
} );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'site_manager_db_version' ) !== SITE_MANAGER_DB_VERSION ) {
		site_manager_install();
	}

	new Site_Manager_Server();
	new Site_Manager_OAuth_Discovery();
	new Site_Manager_OAuth();

	if ( is_admin() ) {
		new Site_Manager_Admin();
	}
} );

/**
 * Tools register late on init so custom post types, taxonomies, ACF and
 * Yoast are all loaded and can be detected.
 */
add_action( 'init', function () {
	$r = Site_Manager_Registry::instance();

	new Site_Manager_Tools_Site( $r );
	new Site_Manager_Tools_Content( $r );
	new Site_Manager_Tools_Taxonomies( $r );
	new Site_Manager_Tools_Media( $r );
	new Site_Manager_Tools_Users( $r );
	new Site_Manager_Tools_Comments( $r );
	new Site_Manager_Tools_Settings( $r );
	new Site_Manager_Tools_Menus( $r );
	new Site_Manager_Tools_Appearance( $r );
	new Site_Manager_Tools_Plugins( $r );
	new Site_Manager_Tools_Maintenance( $r );
	new Site_Manager_Tools_Developer( $r );

	if ( Site_Manager_Tools_ACF::is_active() ) {
		new Site_Manager_Tools_ACF( $r );
	}
	if ( Site_Manager_Tools_Yoast::is_active() ) {
		new Site_Manager_Tools_Yoast( $r );
	}

	/**
	 * Fires after the built-in tools are registered. Other plugins (or
	 * site-specific mu-plugins) can register additional tools here.
	 *
	 * @param Site_Manager_Registry $r
	 */
	do_action( 'site_manager_register_tools', $r );
}, 99 );

add_action( 'site_manager_daily', function () {
	Site_Manager_OAuth_Store::purge_expired();
	Site_Manager_Log::purge( Site_Manager_Settings::get( 'log_retention_days' ) );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( Site_Manager_Admin::url() ) . '">' . esc_html__( 'Settings', 'site-manager' ) . '</a>' );
	return $links;
} );

<?php
/**
 * Uninstall: drop Site Manager's tables and options.
 *
 * @package Site_Manager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'site_manager_oauth_clients', 'site_manager_oauth_tokens', 'site_manager_log', 'site_manager_events' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'site_manager_settings' );
delete_option( 'site_manager_report_settings' );
delete_option( 'site_manager_reports' );

// Archived report PDFs.
$sm_uploads = wp_upload_dir();
$sm_dir     = trailingslashit( $sm_uploads['basedir'] ) . 'site-manager-reports';
if ( is_dir( $sm_dir ) ) {
	foreach ( (array) glob( $sm_dir . '/{,.}*', GLOB_BRACE ) as $sm_file ) {
		if ( is_file( $sm_file ) ) {
			unlink( $sm_file );
		}
	}
	rmdir( $sm_dir );
}
delete_option( 'site_manager_db_version' );
wp_clear_scheduled_hook( 'site_manager_daily' );

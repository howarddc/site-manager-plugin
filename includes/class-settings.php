<?php
/**
 * Plugin settings, stored as a single option.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Settings {

	const OPTION = 'site_manager_settings';

	/**
	 * Gated capabilities that are off by default. Tools declare a `gate` that
	 * must be enabled here before they're listed or callable.
	 */
	public static function gates() {
		return array(
			'allow_db_write'   => array(
				'label'       => __( 'Database writes', 'site-manager' ),
				'description' => __( 'Run INSERT / UPDATE / DELETE / ALTER SQL against the database. Read-only SELECT queries are always available.', 'site-manager' ),
			),
			'allow_file_write' => array(
				'label'       => __( 'File writes', 'site-manager' ),
				'description' => __( 'Create, edit and delete files inside wp-content (themes, plugins, uploads). Disabled automatically when DISALLOW_FILE_EDIT is set.', 'site-manager' ),
			),
			'allow_php_exec'   => array(
				'label'       => __( 'PHP execution', 'site-manager' ),
				'description' => __( 'Evaluate arbitrary PHP inside WordPress. Full control of the server — enable only while you need it.', 'site-manager' ),
			),
		);
	}

	public static function defaults() {
		return array(
			'disabled_categories' => array(),
			'allow_db_write'      => false,
			'allow_file_write'    => false,
			'allow_php_exec'      => false,
			'log_reads'           => false,
			'log_retention_days'  => 30,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether a gate is currently open. File writes also honor
	 * DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS.
	 */
	public static function gate_open( $gate ) {
		if ( ! $gate ) {
			return true;
		}
		if ( $gate === 'allow_file_write' && ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) ) {
			return false;
		}
		return (bool) self::get( $gate );
	}

	public static function category_enabled( $category ) {
		return ! in_array( $category, (array) self::get( 'disabled_categories' ), true );
	}
}

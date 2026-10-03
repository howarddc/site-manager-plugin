<?php
/**
 * Terse builders for tool input JSON Schemas.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Schema {

	/**
	 * Object schema. Empty properties are encoded as `{}` by the server.
	 */
	public static function obj( array $properties = array(), array $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	public static function str( $description = '', array $extra = array() ) {
		return self::prop( 'string', $description, $extra );
	}

	public static function int( $description = '', array $extra = array() ) {
		return self::prop( 'integer', $description, $extra );
	}

	public static function num( $description = '', array $extra = array() ) {
		return self::prop( 'number', $description, $extra );
	}

	public static function bool( $description = '', $default = null ) {
		$extra = $default === null ? array() : array( 'default' => (bool) $default );
		return self::prop( 'boolean', $description, $extra );
	}

	public static function enum( array $values, $description = '', $default = null ) {
		$extra = array( 'enum' => array_values( $values ) );
		if ( $default !== null ) {
			$extra['default'] = $default;
		}
		return self::prop( 'string', $description, $extra );
	}

	public static function arr( $items, $description = '' ) {
		$items = is_string( $items ) ? array( 'type' => $items ) : $items;
		return self::prop( 'array', $description, array( 'items' => $items ) );
	}

	/** A free-form object (arbitrary keys). */
	public static function map( $description = '' ) {
		return self::prop( 'object', $description, array( 'additionalProperties' => true ) );
	}

	/** Any JSON value. */
	public static function any( $description = '' ) {
		return $description === '' ? new stdClass() : array( 'description' => $description );
	}

	public static function page() {
		return self::int( 'Page number (1-based).', array( 'minimum' => 1, 'default' => 1 ) );
	}

	public static function per_page( $default = 20, $max = 100 ) {
		return self::int( "Results per page (max {$max}).", array( 'minimum' => 1, 'maximum' => $max, 'default' => $default ) );
	}

	public static function dry_run() {
		return self::bool( 'Preview the change without applying it.', false );
	}

	private static function prop( $type, $description, array $extra ) {
		$p = array( 'type' => $type );
		if ( $description !== '' ) {
			$p['description'] = $description;
		}
		return array_merge( $p, $extra );
	}
}

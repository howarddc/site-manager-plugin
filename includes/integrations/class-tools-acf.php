<?php
/**
 * MCP tools: Advanced Custom Fields (free and PRO).
 *
 * Covers field groups (schema), field values on any object (posts, terms,
 * users, options pages, comments), ACF-registered post types and
 * taxonomies, and options pages.
 *
 * Values are read unformatted (raw IDs, not expanded objects) so they can be
 * written back as-is. Repeater, group and flexible-content rows are keyed
 * by sub-field name rather than field key.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_ACF {

	public static function is_active() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'get_field_objects' );
	}

	public static function version() {
		return defined( 'ACF_VERSION' ) ? ACF_VERSION . ( defined( 'ACF_PRO' ) && ACF_PRO ? ' (PRO)' : '' ) : true;
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'acf', __( 'Advanced Custom Fields', 'site-manager' ), __( 'ACF field groups, field values on posts/terms/users/options, ACF post types and taxonomies.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'Advanced Custom Fields is active: use acf_field_groups_list / acf_field_group_get to learn field names and types before writing values with acf_values_update.';
			return $lines;
		} );

		$object = $s::any( 'Where the values live: a post ID (number), "term_{id}", "user_{id}", "comment_{id}", "option" (default options page) or an options page\'s post_id.' );

		$r->register( 'acf_field_groups_list', array(
			'category'     => 'acf',
			'description'  => 'List ACF field groups with key, title, location rules, active state and field count. Pass object to list only the groups that apply to that post/term/user.',
			'input_schema' => $s::obj( array(
				'object' => $object,
			) ),
			'handler'      => array( $this, 'field_groups_list' ),
		) );

		$r->register( 'acf_field_group_get', array(
			'category'     => 'acf',
			'description'  => 'Get a field group\'s full definition — every field (name, key, type, choices, sub-fields, layouts, conditional logic) in ACF\'s export format. Read this before writing values so you know field names and types.',
			'input_schema' => $s::obj( array( 'key' => $s::str( 'Field group key (group_…) or ID.' ) ), array( 'key' ) ),
			'handler'      => array( $this, 'field_group_get' ),
		) );

		$r->register( 'acf_field_group_save', array(
			'category'     => 'acf',
			'description'  => 'Create or update a field group from ACF\'s export JSON (title, fields, location, etc.). Matching key updates the existing group. Fields need unique keys (field_…); new keys are generated when missing.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'group' => $s::map( 'Field group in ACF export format: {"key":"group_…","title":"…","fields":[…],"location":[[{"param":"post_type","operator":"==","value":"post"}]]}.' ),
			), array( 'group' ) ),
			'handler'      => array( $this, 'field_group_save' ),
		) );

		$r->register( 'acf_field_group_delete', array(
			'category'     => 'acf',
			'description'  => 'Delete a field group and its field definitions. Stored values remain in the database.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'key' => $s::str() ), array( 'key' ) ),
			'handler'      => array( $this, 'field_group_delete' ),
		) );

		$r->register( 'acf_values_get', array(
			'category'     => 'acf',
			'description'  => 'Get all ACF field values for an object (post, term, user, options page), with each field\'s type, label and key. Set formatted=true for display-ready values (expanded images, post objects).',
			'input_schema' => $s::obj( array(
				'object'    => $object,
				'formatted' => $s::bool( '', false ),
			), array( 'object' ) ),
			'handler'      => array( $this, 'values_get' ),
		) );

		$r->register( 'acf_values_update', array(
			'category'     => 'acf',
			'description'  => 'Set ACF field values on an object. Keys are field names or keys. Use raw values: attachment IDs for image/file/gallery, post IDs for post_object/relationship, term IDs for taxonomy, arrays of row objects (keyed by sub-field name) for repeaters / flexible content (include "acf_fc_layout" per row), an object for groups. null clears a field.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'object' => $object,
				'values' => $s::map(),
			), array( 'object', 'values' ) ),
			'handler'      => array( $this, 'values_update' ),
		) );

		$r->register( 'acf_options_pages_list', array(
			'category'     => 'acf',
			'description'  => 'List ACF options pages (PRO) with their post_id, which you pass as object to acf_values_get / acf_values_update.',
			'handler'      => array( $this, 'options_pages_list' ),
		) );

		$r->register( 'acf_post_types_list', array(
			'category'     => 'acf',
			'description'  => 'List post types and taxonomies registered through ACF\'s UI (ACF 6.1+), with their full settings.',
			'handler'      => array( $this, 'post_types_list' ),
		) );

		$r->register( 'acf_post_type_save', array(
			'category'     => 'acf',
			'description'  => 'Create or update a post type or taxonomy registered through ACF (ACF 6.1+), using ACF\'s export format. kind = post_type | taxonomy.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'kind'   => $s::enum( array( 'post_type', 'taxonomy' ) ),
				'config' => $s::map( 'ACF export format, e.g. {"key":"post_type_…","title":"Books","post_type":"book","labels":{…},"public":true,"supports":["title","editor"]}.' ),
			), array( 'kind', 'config' ) ),
			'handler'      => array( $this, 'post_type_save' ),
		) );
	}

	// ---------------------------------------------------------------
	// Shared helpers (also used by content/taxonomy/user tools)
	// ---------------------------------------------------------------

	/**
	 * Normalize the many ways callers name an ACF object.
	 */
	public static function object_id( $object ) {
		if ( is_numeric( $object ) ) {
			return (int) $object;
		}
		$object = trim( (string) $object );
		if ( $object === 'options' ) {
			return 'option';
		}
		return $object;
	}

	/**
	 * Field values keyed by name: { name: { type, label, key, value } }.
	 */
	public static function values_for( $object, $formatted = false ) {
		$objects = get_field_objects( self::object_id( $object ), (bool) $formatted, true );
		if ( ! $objects ) {
			return new stdClass();
		}
		$out = array();
		foreach ( $objects as $name => $field ) {
			$out[ $name ] = array(
				'type'  => $field['type'],
				'label' => $field['label'],
				'key'   => $field['key'],
				'value' => $formatted ? $field['value'] : self::humanize( $field, $field['value'] ),
			);
		}
		return (object) $out;
	}

	/**
	 * Re-key raw repeater / group / flexible-content values from field keys
	 * to sub-field names.
	 */
	private static function humanize( array $field, $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		switch ( $field['type'] ) {
			case 'group':
				return self::humanize_row( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), $value );
			case 'repeater':
				$rows = array();
				foreach ( $value as $row ) {
					$rows[] = is_array( $row ) ? self::humanize_row( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), $row ) : $row;
				}
				return $rows;
			case 'flexible_content':
				$layouts = array();
				foreach ( isset( $field['layouts'] ) ? (array) $field['layouts'] : array() as $layout ) {
					$layouts[ $layout['name'] ] = isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array();
				}
				$rows = array();
				foreach ( $value as $row ) {
					$name   = isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : '';
					$rows[] = array( 'acf_fc_layout' => $name ) + self::humanize_row( isset( $layouts[ $name ] ) ? $layouts[ $name ] : array(), $row );
				}
				return $rows;
		}
		return $value;
	}

	private static function humanize_row( array $sub_fields, array $row ) {
		$out = array();
		foreach ( $sub_fields as $sub ) {
			if ( array_key_exists( $sub['key'], $row ) ) {
				$out[ $sub['name'] ] = self::humanize( $sub, $row[ $sub['key'] ] );
			} elseif ( array_key_exists( $sub['name'], $row ) ) {
				$out[ $sub['name'] ] = self::humanize( $sub, $row[ $sub['name'] ] );
			}
		}
		return $out;
	}

	/**
	 * Write a { name_or_key: value } map. Returns per-field outcome.
	 */
	public static function update_values( $object, array $values ) {
		$object = self::object_id( $object );
		$result = array();
		foreach ( $values as $selector => $value ) {
			$field = acf_maybe_get_field( (string) $selector, $object, false );
			if ( ! $field ) {
				$result[ $selector ] = 'unknown field (check the name, or that its field group applies to this object)';
				continue;
			}
			if ( $value === null ) {
				delete_field( $field['key'], $object );
				$result[ $selector ] = 'cleared';
				continue;
			}
			// Update by key so ACF also writes the _{name} reference meta.
			$ok                  = update_field( $field['key'], $value, $object );
			$result[ $selector ] = $ok ? 'updated' : 'unchanged';
		}
		return $result;
	}

	private static function group_summary( array $group ) {
		return array(
			'key'      => $group['key'],
			'id'       => isset( $group['ID'] ) ? (int) $group['ID'] : null,
			'title'    => $group['title'],
			'active'   => (bool) $group['active'],
			'location' => $group['location'],
			'fields'   => count( (array) acf_get_fields( $group ) ),
			'local'    => isset( $group['local'] ) ? $group['local'] : null,
		);
	}

	// ---------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------

	public function field_groups_list( array $args ) {
		$filter = array();
		if ( isset( $args['object'] ) && $args['object'] !== '' ) {
			$object = self::object_id( $args['object'] );
			if ( is_int( $object ) ) {
				$filter = array( 'post_id' => $object, 'post_type' => get_post_type( $object ) );
			} elseif ( preg_match( '/^term_(\d+)$/', $object, $m ) ) {
				$term   = get_term( (int) $m[1] );
				$filter = $term && ! is_wp_error( $term ) ? array( 'taxonomy' => $term->taxonomy ) : array();
			} elseif ( preg_match( '/^user_(\d+)$/', $object, $m ) ) {
				$filter = array( 'user_id' => (int) $m[1], 'user_form' => 'edit' );
			} else {
				$filter = array( 'options_page' => $object === 'option' ? 'acf-options' : $object );
			}
		}
		return array_map( array( __CLASS__, 'group_summary' ), acf_get_field_groups( $filter ) );
	}

	public function field_group_get( array $args ) {
		$key   = is_numeric( $args['key'] ) ? (int) $args['key'] : (string) $args['key'];
		$group = acf_get_field_group( $key );
		if ( ! $group ) {
			return new WP_Error( 'not_found', 'Field group not found.' );
		}
		$group['fields'] = acf_get_fields( $group );
		return function_exists( 'acf_prepare_field_group_for_export' ) ? acf_prepare_field_group_for_export( $group ) : $group;
	}

	public function field_group_save( array $args ) {
		$group = (array) $args['group'];
		if ( empty( $group['title'] ) ) {
			return new WP_Error( 'missing_title', 'group.title is required.' );
		}
		if ( empty( $group['key'] ) ) {
			$group['key'] = 'group_' . uniqid();
		}
		$group['fields'] = self::ensure_field_keys( isset( $group['fields'] ) ? (array) $group['fields'] : array() );
		if ( empty( $group['location'] ) ) {
			return new WP_Error( 'missing_location', 'group.location is required, e.g. [[{"param":"post_type","operator":"==","value":"post"}]].' );
		}
		// Updating an existing DB group: import matches by key via its ID.
		$existing = acf_get_field_group( $group['key'] );
		if ( $existing && ! empty( $existing['ID'] ) ) {
			$group['ID'] = $existing['ID'];
		}
		$saved = acf_import_field_group( $group );
		if ( ! $saved || empty( $saved['key'] ) ) {
			return new WP_Error( 'save_failed', 'ACF could not save the field group.' );
		}
		return self::group_summary( acf_get_field_group( $saved['key'] ) );
	}

	private static function ensure_field_keys( array $fields ) {
		foreach ( $fields as &$field ) {
			$field = (array) $field;
			if ( empty( $field['key'] ) ) {
				$field['key'] = 'field_' . uniqid();
			}
			if ( empty( $field['label'] ) && ! empty( $field['name'] ) ) {
				$field['label'] = ucwords( str_replace( array( '_', '-' ), ' ', $field['name'] ) );
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::ensure_field_keys( (array) $field['sub_fields'] );
			}
			if ( ! empty( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $i => $layout ) {
					$layout = (array) $layout;
					if ( empty( $layout['key'] ) ) {
						$layout['key'] = 'layout_' . uniqid();
					}
					if ( ! empty( $layout['sub_fields'] ) ) {
						$layout['sub_fields'] = self::ensure_field_keys( (array) $layout['sub_fields'] );
					}
					$field['layouts'][ $i ] = $layout;
				}
			}
			// uniqid() can repeat within the same microsecond.
			usleep( 1 );
		}
		unset( $field );
		return $fields;
	}

	public function field_group_delete( array $args ) {
		$group = acf_get_field_group( is_numeric( $args['key'] ) ? (int) $args['key'] : (string) $args['key'] );
		if ( ! $group ) {
			return new WP_Error( 'not_found', 'Field group not found.' );
		}
		if ( empty( $group['ID'] ) ) {
			return new WP_Error( 'local_group', 'This field group is defined in PHP or local JSON, not the database; remove it from code instead.' );
		}
		acf_delete_field_group( $group['ID'] );
		return array( 'deleted' => $group['key'], 'title' => $group['title'] );
	}

	public function values_get( array $args ) {
		return self::values_for( $args['object'], Site_Manager_Helpers::bool( $args, 'formatted' ) );
	}

	public function values_update( array $args ) {
		$result = self::update_values( $args['object'], (array) $args['values'] );
		return array( 'object' => self::object_id( $args['object'] ), 'results' => $result );
	}

	public function options_pages_list() {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return new WP_Error( 'not_supported', 'Options pages require ACF PRO.' );
		}
		$out = array();
		foreach ( (array) acf_get_options_pages() as $slug => $page ) {
			$out[] = array(
				'menu_slug'   => $slug,
				'page_title'  => $page['page_title'],
				'post_id'     => $page['post_id'],
				'parent_slug' => $page['parent_slug'],
			);
		}
		return $out;
	}

	public function post_types_list() {
		if ( ! function_exists( 'acf_get_acf_post_types' ) ) {
			return new WP_Error( 'not_supported', 'ACF-registered post types require ACF 6.1 or newer.' );
		}
		return array(
			'post_types' => acf_get_acf_post_types(),
			'taxonomies' => function_exists( 'acf_get_acf_taxonomies' ) ? acf_get_acf_taxonomies() : array(),
		);
	}

	public function post_type_save( array $args ) {
		if ( ! function_exists( 'acf_import_internal_post_type' ) ) {
			return new WP_Error( 'not_supported', 'ACF-registered post types require ACF 6.1 or newer.' );
		}
		$kind   = $args['kind'] === 'taxonomy' ? 'acf-taxonomy' : 'acf-post-type';
		$config = (array) $args['config'];
		if ( empty( $config['key'] ) ) {
			$config['key'] = ( $kind === 'acf-taxonomy' ? 'taxonomy_' : 'post_type_' ) . uniqid();
		}
		$existing = acf_get_internal_post_type( $config['key'], $kind );
		if ( $existing && ! empty( $existing['ID'] ) ) {
			$config['ID'] = $existing['ID'];
		}
		$saved = acf_import_internal_post_type( $config, $kind );
		if ( ! $saved ) {
			return new WP_Error( 'save_failed', 'ACF could not save the definition.' );
		}
		return array(
			'saved' => $saved['key'],
			'note'  => 'Registered on the next request. Run rewrite_flush afterwards if the post type is public.',
		);
	}
}

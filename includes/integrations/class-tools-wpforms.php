<?php
/**
 * MCP tools: WPForms (Lite and Pro).
 *
 * Forms are `wpforms` posts whose content is the JSON form definition.
 * Entries are stored only by WPForms Pro.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_WPForms {

	public static function is_active() {
		return function_exists( 'wpforms' ) && defined( 'WPFORMS_VERSION' );
	}

	public static function version() {
		return WPFORMS_VERSION . ( self::is_pro() ? ' (Pro)' : ' (Lite)' );
	}

	private static function is_pro() {
		return function_exists( 'wpforms' ) && method_exists( wpforms(), 'is_pro' ) && wpforms()->is_pro();
	}

	/** wpforms()->get( $name ) on current versions, wpforms()->$name on older ones. */
	private static function handler( $name ) {
		$wpforms = wpforms();
		if ( method_exists( $wpforms, 'get' ) ) {
			return $wpforms->get( $name );
		}
		return isset( $wpforms->$name ) ? $wpforms->$name : null;
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'wpforms', __( 'WPForms', 'site-manager' ), __( 'WPForms forms (fields, notifications, confirmations) and Pro entries.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'WPForms is active: wpforms_form_get returns the form data (fields keyed by ID, settings incl. notifications and confirmations); modify and pass it to wpforms_form_save.' . ( self::is_pro() ? ' Entries are available via wpforms_entries_list.' : ' WPForms Lite does not store entries.' );
			return $lines;
		} );

		$r->register( 'wpforms_forms_list', array(
			'category'     => 'wpforms',
			'description'  => 'List WPForms forms with ID, title, status, field count, shortcode and (Pro) entry count.',
			'handler'      => array( $this, 'forms_list' ),
		) );

		$r->register( 'wpforms_form_get', array(
			'category'     => 'wpforms',
			'description'  => 'Get a form\'s full data: fields (id, type, label, choices, required…), settings, notifications and confirmations.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'form_get' ),
		) );

		$r->register( 'wpforms_form_save', array(
			'category'     => 'wpforms',
			'description'  => 'Create a form (omit id; title required) or update one with the full form data from wpforms_form_get. Field example: {"0":{"id":"0","type":"name","label":"Name","format":"simple","required":"1"},"1":{"id":"1","type":"email","label":"Email","required":"1"},"2":{"id":"2","type":"textarea","label":"Message"}}.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'    => $s::int( 'Omit to create.' ),
				'title' => $s::str(),
				'data'  => $s::map( 'Form data: {"fields": {...}, "settings": {...}}.' ),
			) ),
			'handler'      => array( $this, 'form_save' ),
		) );

		$r->register( 'wpforms_form_delete', array(
			'category'     => 'wpforms',
			'description'  => 'Permanently delete a form (and, on Pro, its entries).',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'form_delete' ),
		) );

		$r->register( 'wpforms_entries_list', array(
			'category'     => 'wpforms',
			'description'  => 'WPForms Pro: list entries for a form, newest first, with field values labeled.',
			'input_schema' => $s::obj( array(
				'form_id'  => $s::int(),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 20, 200 ),
			), array( 'form_id' ) ),
			'handler'      => array( $this, 'entries_list' ),
		) );

		$r->register( 'wpforms_entry_get', array(
			'category'     => 'wpforms',
			'description'  => 'WPForms Pro: get one entry with all field values and metadata.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'entry_get' ),
		) );
	}

	private static function get_form( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== 'wpforms' ) {
			return new WP_Error( 'not_found', 'Form not found.' );
		}
		$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( $post->post_content, true );
		return array( $post, is_array( $data ) ? $data : array() );
	}

	public function forms_list() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $post ) {
			list( , $data ) = self::get_form( $post->ID );
			$row            = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'fields'    => count( isset( $data['fields'] ) ? (array) $data['fields'] : array() ),
				'shortcode' => '[wpforms id="' . $post->ID . '"]',
			);
			if ( self::is_pro() && self::handler( 'entry' ) ) {
				$row['entries'] = (int) self::handler( 'entry' )->get_entries( array( 'form_id' => $post->ID ), true );
			}
			$out[] = $row;
		}
		return $out;
	}

	public function form_get( array $args ) {
		$form = self::get_form( $args['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		list( $post, $data ) = $form;
		return array( 'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status, 'data' => $data );
	}

	public function form_save( array $args ) {
		$handler = self::handler( 'form' );
		if ( ! $handler ) {
			return new WP_Error( 'unavailable', 'WPForms form handler is not available.' );
		}
		$data = isset( $args['data'] ) ? (array) $args['data'] : array();

		if ( empty( $args['id'] ) ) {
			if ( empty( $args['title'] ) ) {
				return new WP_Error( 'missing_title', 'title is required to create a form.' );
			}
			$id = $handler->add( (string) $args['title'] );
			if ( ! $id ) {
				return new WP_Error( 'create_failed', 'WPForms could not create the form.' );
			}
			list( , $existing ) = self::get_form( $id );
		} else {
			$form = self::get_form( $args['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$id       = (int) $args['id'];
			$existing = $form[1];
		}

		// Merge so a partial "data" (e.g. only fields) keeps the rest.
		$merged       = array_merge( $existing, $data );
		$merged['id'] = (string) $id;
		if ( ! empty( $args['title'] ) ) {
			$merged['settings']['form_title'] = (string) $args['title'];
		}
		if ( isset( $merged['fields'] ) && is_array( $merged['fields'] ) ) {
			$max = -1;
			foreach ( $merged['fields'] as $key => $f ) {
				$max = max( $max, (int) $key );
			}
			$merged['field_id'] = max( isset( $merged['field_id'] ) ? (int) $merged['field_id'] : 0, $max + 1 );
		}
		$result = $handler->update( $id, $merged );
		if ( ! $result ) {
			return new WP_Error( 'save_failed', 'WPForms could not save the form.' );
		}
		return $this->form_get( array( 'id' => $id ) );
	}

	public function form_delete( array $args ) {
		$form = self::get_form( $args['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$handler = self::handler( 'form' );
		$ok      = $handler && method_exists( $handler, 'delete' ) ? $handler->delete( array( (int) $args['id'] ) ) : wp_delete_post( (int) $args['id'], true );
		return $ok ? array( 'deleted' => array( 'id' => (int) $args['id'], 'title' => $form[0]->post_title ) ) : new WP_Error( 'delete_failed', 'Form could not be deleted.' );
	}

	private static function require_pro() {
		return self::is_pro() && self::handler( 'entry' ) ? true : new WP_Error( 'not_supported', 'Entries are stored only by WPForms Pro.' );
	}

	private static function entry_out( $entry ) {
		$fields = json_decode( (string) $entry->fields, true );
		$values = array();
		foreach ( (array) $fields as $field ) {
			$values[] = array(
				'id'    => isset( $field['id'] ) ? $field['id'] : null,
				'label' => isset( $field['name'] ) ? $field['name'] : '',
				'type'  => isset( $field['type'] ) ? $field['type'] : '',
				'value' => isset( $field['value'] ) ? $field['value'] : '',
			);
		}
		return array(
			'id'      => (int) $entry->entry_id,
			'form_id' => (int) $entry->form_id,
			'date'    => $entry->date,
			'status'  => $entry->status,
			'viewed'  => (bool) $entry->viewed,
			'starred' => (bool) $entry->starred,
			'ip'      => isset( $entry->ip_address ) ? $entry->ip_address : null,
			'fields'  => $values,
		);
	}

	public function entries_list( array $args ) {
		$pro = self::require_pro();
		if ( is_wp_error( $pro ) ) {
			return $pro;
		}
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 20, 200 );
		$handler  = self::handler( 'entry' );
		$query    = array( 'form_id' => (int) $args['form_id'], 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page, 'orderby' => 'entry_id', 'order' => 'DESC' );
		$entries  = (array) $handler->get_entries( $query );
		$total    = (int) $handler->get_entries( array( 'form_id' => (int) $args['form_id'] ), true );
		return Site_Manager_Helpers::paged( array_map( array( __CLASS__, 'entry_out' ), $entries ), $total, $page, $per_page );
	}

	public function entry_get( array $args ) {
		$pro = self::require_pro();
		if ( is_wp_error( $pro ) ) {
			return $pro;
		}
		$entry = self::handler( 'entry' )->get( (int) $args['id'] );
		return $entry ? self::entry_out( $entry ) : new WP_Error( 'not_found', 'Entry not found.' );
	}
}

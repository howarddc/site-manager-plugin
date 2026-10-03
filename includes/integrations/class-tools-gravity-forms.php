<?php
/**
 * MCP tools: Gravity Forms (via GFAPI).
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Gravity_Forms {

	public static function is_active() {
		return class_exists( 'GFAPI' );
	}

	public static function version() {
		return class_exists( 'GFForms' ) ? GFForms::$version : true;
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'gravity_forms', __( 'Gravity Forms', 'site-manager' ), __( 'Forms, fields, notifications, confirmations and entries.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'Gravity Forms is active: gf_form_get returns the full form object (fields, notifications, confirmations); modify it and pass it back to gf_form_save. Entry values are keyed by field ID ("1", "2.3" for sub-inputs).';
			return $lines;
		} );

		$r->register( 'gf_forms_list', array(
			'category'     => 'gravity_forms',
			'description'  => 'List Gravity Forms with ID, title, active state, field count, and entry counts (total / unread / starred).',
			'input_schema' => $s::obj( array(
				'include_inactive' => $s::bool( '', true ),
				'include_trash'    => $s::bool( '', false ),
			) ),
			'handler'      => array( $this, 'forms_list' ),
		) );

		$r->register( 'gf_form_get', array(
			'category'     => 'gravity_forms',
			'description'  => 'Get a form\'s full definition: fields (id, type, label, choices, required, conditional logic), notifications, confirmations and settings. Also returns a compact field map (id → label/type).',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'form_get' ),
		) );

		$r->register( 'gf_form_save', array(
			'category'     => 'gravity_forms',
			'description'  => 'Create a form (omit form.id) or update one by passing the complete form object, usually gf_form_get\'s output with changes. Minimal create: {"title":"Contact","fields":[{"id":1,"type":"name","label":"Name"},{"id":2,"type":"email","label":"Email","isRequired":true},{"id":3,"type":"textarea","label":"Message"}]}.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'form' => $s::map( 'Gravity Forms form object.' ) ), array( 'form' ) ),
			'handler'      => array( $this, 'form_save' ),
		) );

		$r->register( 'gf_form_set_active', array(
			'category'     => 'gravity_forms',
			'description'  => 'Activate or deactivate a form.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'     => $s::int(),
				'active' => $s::bool(),
			), array( 'id', 'active' ) ),
			'handler'      => array( $this, 'form_set_active' ),
		) );

		$r->register( 'gf_form_delete', array(
			'category'     => 'gravity_forms',
			'description'  => 'Permanently delete a form and all of its entries.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'      => $s::int(),
				'confirm' => $s::bool( 'Must be true; this deletes every entry too.', false ),
			), array( 'id', 'confirm' ) ),
			'handler'      => array( $this, 'form_delete' ),
		) );

		$r->register( 'gf_entries_list', array(
			'category'     => 'gravity_forms',
			'description'  => 'List entries for a form (or all forms with form_id=0), newest first. Filter by status, date range, read/starred, free-text search, or specific field values. Values are keyed by field ID, with a labels map.',
			'input_schema' => $s::obj( array(
				'form_id'       => $s::int( '0 = all forms.' ),
				'status'        => $s::enum( array( 'active', 'spam', 'trash' ), '', 'active' ),
				'search'        => $s::str( 'Match any field value.' ),
				'field_filters' => $s::arr( 'object', '[{"key":"2","value":"jane@example.com","operator":"is"}] — key is a field ID or entry property.' ),
				'start_date'    => $s::str( 'YYYY-MM-DD' ),
				'end_date'      => $s::str( 'YYYY-MM-DD' ),
				'is_read'       => $s::bool(),
				'is_starred'    => $s::bool(),
				'page'          => $s::page(),
				'per_page'      => $s::per_page( 20, 200 ),
			) ),
			'handler'      => array( $this, 'entries_list' ),
		) );

		$r->register( 'gf_entry_get', array(
			'category'     => 'gravity_forms',
			'description'  => 'Get one entry with values labeled by field, plus its notes.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'entry_get' ),
		) );

		$r->register( 'gf_entry_create', array(
			'category'     => 'gravity_forms',
			'description'  => 'Add an entry directly (no notifications or add-on feeds run). values: {"1.3":"Jane","1.6":"Doe","2":"jane@example.com"}.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'form_id' => $s::int(),
				'values'  => $s::map(),
			), array( 'form_id', 'values' ) ),
			'handler'      => array( $this, 'entry_create' ),
		) );

		$r->register( 'gf_entry_update', array(
			'category'     => 'gravity_forms',
			'description'  => 'Update an entry: field values, status (active / spam / trash), is_read, is_starred, or add a note.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'         => $s::int(),
				'values'     => $s::map( 'Field ID → value.' ),
				'status'     => $s::enum( array( 'active', 'spam', 'trash' ) ),
				'is_read'    => $s::bool(),
				'is_starred' => $s::bool(),
				'note'       => $s::str(),
			), array( 'id' ) ),
			'handler'      => array( $this, 'entry_update' ),
		) );

		$r->register( 'gf_entry_delete', array(
			'category'     => 'gravity_forms',
			'description'  => 'Permanently delete an entry (use gf_entry_update status=trash to trash it instead).',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'entry_delete' ),
		) );

		$r->register( 'gf_entry_resend_notifications', array(
			'category'     => 'gravity_forms',
			'description'  => 'Re-send a form\'s notifications for an entry (all, or the given notification IDs).',
			'writes'       => true,
			'open_world'   => true,
			'input_schema' => $s::obj( array(
				'id'               => $s::int( 'Entry ID.' ),
				'notification_ids' => $s::arr( 'string' ),
			), array( 'id' ) ),
			'handler'      => array( $this, 'entry_resend_notifications' ),
		) );
	}

	private static function field_map( array $form ) {
		$map = array();
		foreach ( (array) rgar( $form, 'fields' ) as $field ) {
			$map[ (string) $field->id ] = array( 'label' => $field->label, 'type' => $field->type );
			foreach ( (array) $field->inputs as $input ) {
				if ( empty( $input['isHidden'] ) ) {
					$map[ (string) $input['id'] ] = array( 'label' => $field->label . ' (' . $input['label'] . ')', 'type' => $field->type );
				}
			}
		}
		return $map;
	}

	/** Split an entry into meta + field values, dropping empties. */
	private static function entry_out( array $entry, array $map = array() ) {
		$meta   = array();
		$values = array();
		foreach ( $entry as $key => $value ) {
			if ( preg_match( '/^\d+(\.\d+)?$/', (string) $key ) ) {
				if ( $value !== '' && $value !== null ) {
					$values[ $key ] = $value;
				}
			} else {
				$meta[ $key ] = $value;
			}
		}
		$out = array(
			'id'           => (int) $entry['id'],
			'form_id'      => (int) $entry['form_id'],
			'date_created' => $entry['date_created'],
			'status'       => $entry['status'],
			'is_read'      => (bool) $entry['is_read'],
			'is_starred'   => (bool) $entry['is_starred'],
			'source_url'   => rgar( $entry, 'source_url' ),
			'ip'           => rgar( $entry, 'ip' ),
			'created_by'   => rgar( $entry, 'created_by' ),
			'values'       => (object) $values,
		);
		if ( $map ) {
			$labeled = array();
			foreach ( $values as $key => $value ) {
				$labeled[ isset( $map[ $key ] ) ? $map[ $key ]['label'] . " [{$key}]" : (string) $key ] = $value;
			}
			$out['labeled'] = (object) $labeled;
		}
		return $out;
	}

	public function forms_list( array $args ) {
		$active = Site_Manager_Helpers::bool( $args, 'include_inactive', true ) ? null : true;
		$forms  = GFAPI::get_forms( $active, Site_Manager_Helpers::bool( $args, 'include_trash' ) );
		$out    = array();
		foreach ( $forms as $form ) {
			$counts = GFFormsModel::get_form_counts( $form['id'] );
			$out[]  = array(
				'id'        => (int) $form['id'],
				'title'     => $form['title'],
				'active'    => (bool) rgar( $form, 'is_active', true ),
				'trashed'   => (bool) rgar( $form, 'is_trash' ),
				'fields'    => count( (array) $form['fields'] ),
				'entries'   => (int) rgar( $counts, 'total' ),
				'unread'    => (int) rgar( $counts, 'unread' ),
				'starred'   => (int) rgar( $counts, 'starred' ),
				'shortcode' => '[gravityform id="' . (int) $form['id'] . '" title="false"]',
			);
		}
		return $out;
	}

	public function form_get( array $args ) {
		$form = GFAPI::get_form( (int) $args['id'] );
		if ( ! $form ) {
			return new WP_Error( 'not_found', 'Form not found.' );
		}
		// Field objects → plain arrays for JSON.
		$form['fields'] = array_map( function ( $f ) {
			return is_object( $f ) && method_exists( $f, 'offsetGet' ) ? json_decode( wp_json_encode( $f ), true ) : $f;
		}, (array) $form['fields'] );
		return array( 'field_map' => (object) self::field_map( GFAPI::get_form( (int) $args['id'] ) ), 'form' => $form );
	}

	public function form_save( array $args ) {
		$form = (array) $args['form'];
		if ( empty( $form['title'] ) ) {
			return new WP_Error( 'missing_title', 'form.title is required.' );
		}
		// Give new fields IDs if the caller left them out.
		$form['fields'] = isset( $form['fields'] ) ? array_values( (array) $form['fields'] ) : array();
		$next           = 1;
		foreach ( $form['fields'] as $f ) {
			$next = max( $next, (int) rgar( (array) $f, 'id' ) + 1 );
		}
		foreach ( $form['fields'] as $i => $f ) {
			$f = (array) $f;
			if ( empty( $f['id'] ) ) {
				$f['id'] = $next++;
			}
			$form['fields'][ $i ] = $f;
		}

		if ( ! empty( $form['id'] ) ) {
			$existing = GFAPI::get_form( (int) $form['id'] );
			if ( ! $existing ) {
				return new WP_Error( 'not_found', 'Form not found.' );
			}
			$result = GFAPI::update_form( $form );
			$id     = (int) $form['id'];
		} else {
			$result = GFAPI::add_form( $form );
			$id     = (int) $result;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$saved = GFAPI::get_form( $id );
		return array( 'id' => $id, 'title' => $saved['title'], 'field_map' => (object) self::field_map( $saved ) );
	}

	public function form_set_active( array $args ) {
		$active = Site_Manager_Helpers::bool( $args, 'active' );
		$result = GFAPI::update_form_property( (int) $args['id'], 'is_active', $active ? 1 : 0 );
		return is_wp_error( $result ) ? $result : array( 'id' => (int) $args['id'], 'active' => $active );
	}

	public function form_delete( array $args ) {
		if ( ! Site_Manager_Helpers::bool( $args, 'confirm' ) ) {
			return new WP_Error( 'confirm_required', 'Pass confirm=true to delete the form and all its entries.' );
		}
		$form = GFAPI::get_form( (int) $args['id'] );
		if ( ! $form ) {
			return new WP_Error( 'not_found', 'Form not found.' );
		}
		$result = GFAPI::delete_form( (int) $args['id'] );
		return is_wp_error( $result ) ? $result : array( 'deleted' => array( 'id' => (int) $args['id'], 'title' => $form['title'] ) );
	}

	public function entries_list( array $args ) {
		$form_id  = (int) Site_Manager_Helpers::arg( $args, 'form_id', 0 );
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 20, 200 );
		$search   = array( 'status' => Site_Manager_Helpers::arg( $args, 'status', 'active' ) );
		foreach ( array( 'start_date', 'end_date' ) as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$search[ $k ] = $args[ $k ];
			}
		}
		$filters = array();
		if ( ! empty( $args['search'] ) ) {
			$filters[] = array( 'value' => (string) $args['search'], 'operator' => 'contains' );
		}
		foreach ( (array) Site_Manager_Helpers::arg( $args, 'field_filters', array() ) as $f ) {
			$filters[] = (array) $f;
		}
		if ( isset( $args['is_read'] ) ) {
			$filters[] = array( 'key' => 'is_read', 'value' => Site_Manager_Helpers::bool( $args, 'is_read' ) ? 1 : 0 );
		}
		if ( isset( $args['is_starred'] ) ) {
			$filters[] = array( 'key' => 'is_starred', 'value' => Site_Manager_Helpers::bool( $args, 'is_starred' ) ? 1 : 0 );
		}
		if ( $filters ) {
			$search['field_filters'] = $filters;
		}
		$total   = 0;
		$entries = GFAPI::get_entries( $form_id, $search, array( 'key' => 'date_created', 'direction' => 'DESC' ), array( 'offset' => ( $page - 1 ) * $per_page, 'page_size' => $per_page ), $total );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$maps   = array();
		$items  = array();
		foreach ( $entries as $entry ) {
			$fid = (int) $entry['form_id'];
			if ( ! isset( $maps[ $fid ] ) ) {
				$form         = GFAPI::get_form( $fid );
				$maps[ $fid ] = $form ? self::field_map( $form ) : array();
			}
			$items[] = self::entry_out( $entry, $maps[ $fid ] );
		}
		return Site_Manager_Helpers::paged( $items, $total, $page, $per_page );
	}

	public function entry_get( array $args ) {
		$entry = GFAPI::get_entry( (int) $args['id'] );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$form = GFAPI::get_form( (int) $entry['form_id'] );
		$out  = self::entry_out( $entry, $form ? self::field_map( $form ) : array() );
		$out['notes'] = array();
		foreach ( (array) GFAPI::get_notes( array( 'entry_id' => (int) $args['id'] ) ) as $note ) {
			$out['notes'][] = array( 'date' => $note->date_created, 'by' => $note->user_name, 'note' => $note->value );
		}
		return $out;
	}

	public function entry_create( array $args ) {
		$entry            = array_map( 'strval', array_map( function ( $v ) {
			return is_array( $v ) ? wp_json_encode( $v ) : $v;
		}, (array) $args['values'] ) );
		$entry['form_id'] = (int) $args['form_id'];
		$id               = GFAPI::add_entry( $entry );
		return is_wp_error( $id ) ? $id : $this->entry_get( array( 'id' => $id ) );
	}

	public function entry_update( array $args ) {
		$id    = (int) $args['id'];
		$entry = GFAPI::get_entry( $id );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		if ( ! empty( $args['values'] ) ) {
			foreach ( (array) $args['values'] as $field_id => $value ) {
				$result = GFAPI::update_entry_field( $id, (string) $field_id, is_array( $value ) ? wp_json_encode( $value ) : (string) $value );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		foreach ( array( 'status', 'is_read', 'is_starred' ) as $prop ) {
			if ( isset( $args[ $prop ] ) ) {
				$value = $prop === 'status' ? (string) $args[ $prop ] : ( Site_Manager_Helpers::bool( $args, $prop ) ? 1 : 0 );
				GFAPI::update_entry_property( $id, $prop, $value );
			}
		}
		if ( ! empty( $args['note'] ) ) {
			$user = wp_get_current_user();
			GFAPI::add_note( $id, $user->ID, $user->display_name, (string) $args['note'] );
		}
		return $this->entry_get( array( 'id' => $id ) );
	}

	public function entry_delete( array $args ) {
		$result = GFAPI::delete_entry( (int) $args['id'] );
		return is_wp_error( $result ) ? $result : array( 'deleted' => (int) $args['id'] );
	}

	public function entry_resend_notifications( array $args ) {
		$entry = GFAPI::get_entry( (int) $args['id'] );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$form = GFAPI::get_form( (int) $entry['form_id'] );
		$ids  = (array) Site_Manager_Helpers::arg( $args, 'notification_ids', array() );
		if ( ! $ids ) {
			$ids = array_keys( (array) rgar( $form, 'notifications' ) );
		}
		$sent = GFCommon::send_notifications( $ids, $form, $entry, true, 'form_submission' );
		return array( 'entry_id' => (int) $args['id'], 'notifications' => $ids, 'sent' => $sent );
	}
}

<?php
/**
 * MCP tools: Contact Form 7.
 *
 * CF7 does not store submissions itself; when Flamingo is active its
 * inbound messages are exposed too.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_CF7 {

	public static function is_active() {
		return class_exists( 'WPCF7_ContactForm' ) && function_exists( 'wpcf7_contact_form' );
	}

	public static function version() {
		return defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION . ( class_exists( 'Flamingo_Inbound_Message' ) ? ' (+Flamingo)' : '' ) : true;
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'cf7', __( 'Contact Form 7', 'site-manager' ), __( 'Contact forms (form template, mail, messages) and Flamingo submissions.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'Contact Form 7 is active: forms use CF7 form-tag markup (e.g. [text* your-name] [email* your-email] [submit "Send"]); mail templates reference tags as [your-name].' . ( class_exists( 'Flamingo_Inbound_Message' ) ? ' Submissions are stored by Flamingo (cf7_submissions_list).' : ' Submissions are not stored (install Flamingo to keep them).' );
			return $lines;
		} );

		$mail = $s::map( 'Mail settings: subject, sender, recipient, body, additional_headers, attachments, use_html, exclude_blank.' );

		$r->register( 'cf7_forms_list', array(
			'category'     => 'cf7',
			'description'  => 'List Contact Form 7 forms with ID, title and shortcode.',
			'handler'      => array( $this, 'forms_list' ),
		) );

		$r->register( 'cf7_form_get', array(
			'category'     => 'cf7',
			'description'  => 'Get a form: form-tag template, mail and mail_2 (autoresponder) settings, messages, additional settings, and any configuration errors.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'form_get' ),
		) );

		$r->register( 'cf7_form_save', array(
			'category'     => 'cf7',
			'description'  => 'Create a form (omit id) or update one. Only the parts you pass change. Returns any configuration errors CF7 detects.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'                  => $s::int( 'Omit to create.' ),
				'title'               => $s::str(),
				'form'                => $s::str( 'Form-tag template.' ),
				'mail'                => $mail,
				'mail_2'              => $s::map( 'Autoresponder; same keys as mail plus active (bool).' ),
				'messages'            => $s::map( 'Message overrides, e.g. {"mail_sent_ok":"Thanks!"}.' ),
				'additional_settings' => $s::str( 'e.g. "demo_mode: on".' ),
				'locale'              => $s::str(),
			) ),
			'handler'      => array( $this, 'form_save' ),
		) );

		$r->register( 'cf7_form_duplicate', array(
			'category'     => 'cf7',
			'description'  => 'Duplicate a form.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'form_duplicate' ),
		) );

		$r->register( 'cf7_form_delete', array(
			'category'     => 'cf7',
			'description'  => 'Delete a form permanently.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'form_delete' ),
		) );

		$r->register( 'cf7_submissions_list', array(
			'category'     => 'cf7',
			'description'  => 'Flamingo: list stored form submissions (newest first), optionally for one form, with subject, sender and field values.',
			'input_schema' => $s::obj( array(
				'form_id'  => $s::int( 'Only this form\'s submissions.' ),
				'search'   => $s::str(),
				'spam'     => $s::bool( 'Show spam instead.', false ),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 20, 100 ),
			) ),
			'handler'      => array( $this, 'submissions_list' ),
		) );
	}

	private static function require_form( $id ) {
		$form = wpcf7_contact_form( (int) $id );
		return $form ? $form : new WP_Error( 'not_found', 'Contact form not found.' );
	}

	private static function config_errors( WPCF7_ContactForm $form ) {
		if ( ! class_exists( 'WPCF7_ConfigValidator' ) ) {
			return array();
		}
		try {
			$validator = new WPCF7_ConfigValidator( $form );
			$validator->validate();
			$out = array();
			foreach ( (array) $validator->collect_error_messages() as $section => $errors ) {
				foreach ( (array) $errors as $e ) {
					$out[] = $section . ': ' . ( is_array( $e ) && isset( $e['message'] ) ? $e['message'] : wp_json_encode( $e ) );
				}
			}
			return $out;
		} catch ( Throwable $e ) {
			return array();
		}
	}

	public function forms_list() {
		$out = array();
		foreach ( WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) ) as $form ) {
			$out[] = array(
				'id'        => $form->id(),
				'title'     => $form->title(),
				'shortcode' => $form->shortcode(),
			);
		}
		return $out;
	}

	public function form_get( array $args ) {
		$form = self::require_form( $args['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return array(
			'id'                  => $form->id(),
			'title'               => $form->title(),
			'shortcode'           => $form->shortcode(),
			'locale'              => $form->locale(),
			'form'                => $form->prop( 'form' ),
			'mail'                => $form->prop( 'mail' ),
			'mail_2'              => $form->prop( 'mail_2' ),
			'messages'            => $form->prop( 'messages' ),
			'additional_settings' => $form->prop( 'additional_settings' ),
			'config_errors'       => self::config_errors( $form ),
		);
	}

	public function form_save( array $args ) {
		if ( ! empty( $args['id'] ) ) {
			$form = self::require_form( $args['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
		} else {
			if ( empty( $args['title'] ) ) {
				return new WP_Error( 'missing_title', 'title is required to create a form.' );
			}
			$form = WPCF7_ContactForm::get_template( array( 'title' => (string) $args['title'] ) );
		}
		if ( isset( $args['title'] ) ) {
			$form->set_title( (string) $args['title'] );
		}
		if ( isset( $args['locale'] ) ) {
			$form->set_locale( (string) $args['locale'] );
		}
		$props = $form->get_properties();
		foreach ( array( 'form', 'additional_settings' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$props[ $key ] = (string) $args[ $key ];
			}
		}
		foreach ( array( 'mail', 'mail_2', 'messages' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$props[ $key ] = array_merge( (array) $props[ $key ], $args[ $key ] );
			}
		}
		$form->set_properties( $props );
		$form->save();
		return $this->form_get( array( 'id' => $form->id() ) );
	}

	public function form_duplicate( array $args ) {
		$form = self::require_form( $args['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$copy = $form->copy();
		$copy->save();
		// Reload so the shortcode includes the hash assigned on save.
		$copy = wpcf7_contact_form( $copy->id() );
		return array( 'id' => $copy->id(), 'title' => $copy->title(), 'shortcode' => $copy->shortcode() );
	}

	public function form_delete( array $args ) {
		$form = self::require_form( $args['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$out = array( 'id' => $form->id(), 'title' => $form->title() );
		return $form->delete() ? array( 'deleted' => $out ) : new WP_Error( 'delete_failed', 'Form could not be deleted.' );
	}

	public function submissions_list( array $args ) {
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
			return new WP_Error( 'not_supported', 'Contact Form 7 does not store submissions. Install the Flamingo plugin to keep them.' );
		}
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args );
		$query    = array(
			'posts_per_page' => $per_page,
			'offset'         => ( $page - 1 ) * $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => Site_Manager_Helpers::bool( $args, 'spam' ) ? Flamingo_Inbound_Message::spam_status : 'publish',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = (string) $args['search'];
		}
		if ( ! empty( $args['form_id'] ) ) {
			$form = self::require_form( $args['form_id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$query['channel'] = $form->name();
		}
		$messages = Flamingo_Inbound_Message::find( $query );
		$items    = array();
		foreach ( $messages as $m ) {
			$items[] = array(
				'id'         => $m->id(),
				'date'       => get_post_field( 'post_date', $m->id() ),
				'channel'    => $m->channel,
				'subject'    => $m->subject,
				'from'       => $m->from,
				'from_email' => $m->from_email,
				'fields'     => (object) $m->fields,
			);
		}
		return Site_Manager_Helpers::paged( $items, (int) Flamingo_Inbound_Message::count( array_diff_key( $query, array_flip( array( 'posts_per_page', 'offset' ) ) ) ), $page, $per_page );
	}
}

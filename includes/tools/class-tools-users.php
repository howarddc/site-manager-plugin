<?php
/**
 * MCP tools: users, user meta, roles and capabilities.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Users {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'users', __( 'Users & roles', 'site-manager' ), __( 'User accounts, profile fields, user meta, roles and capabilities.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$profile = array(
			'email'        => $s::str(),
			'first_name'   => $s::str(),
			'last_name'    => $s::str(),
			'display_name' => $s::str(),
			'nickname'     => $s::str(),
			'url'          => $s::str( 'Website.' ),
			'description'  => $s::str( 'Biographical info.' ),
			'role'         => $s::str( 'Role slug (replaces existing roles).' ),
			'locale'       => $s::str(),
			'meta'         => $s::map( 'User meta {key: value}; null deletes.' ),
			'acf'          => $s::map( 'ACF user field values. Requires ACF.' ),
		);

		$r->register( 'users_list', array(
			'category'     => 'users',
			'description'  => 'List users, filtered by role, search (login, email, URL, name) or meta.',
			'input_schema' => $s::obj( array(
				'role'       => $s::str(),
				'search'     => $s::str(),
				'meta_key'   => $s::str(),
				'meta_value' => $s::str(),
				'orderby'    => $s::str( 'login, email, registered, display_name, post_count…', array( 'default' => 'registered' ) ),
				'order'      => $s::enum( array( 'ASC', 'DESC' ), '', 'DESC' ),
				'page'       => $s::page(),
				'per_page'   => $s::per_page( 50, 200 ),
			) ),
			'handler'      => array( $this, 'users_list' ),
		) );

		$r->register( 'user_get', array(
			'category'     => 'users',
			'description'  => 'Get a user by id, username or email, including profile fields, roles, capabilities, post count and meta.',
			'input_schema' => $s::obj( array(
				'id'              => $s::int(),
				'username'        => $s::str(),
				'email'           => $s::str(),
				'include_private' => $s::bool( 'Include protected (underscore) meta keys.', false ),
			) ),
			'handler'      => array( $this, 'user_get' ),
		) );

		$r->register( 'user_create', array(
			'category'     => 'users',
			'description'  => 'Create a user. A strong password is generated if none is given. Optionally email the new user their login details.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array(
				'username' => $s::str(),
				'password' => $s::str( 'Omit to auto-generate.' ),
				'notify'   => $s::bool( 'Send the new-user email.', false ),
			), $profile ), array( 'username', 'email' ) ),
			'handler'      => array( $this, 'user_create' ),
		) );

		$r->register( 'user_update', array(
			'category'     => 'users',
			'description'  => 'Update a user\'s profile fields, role, password, meta or ACF fields.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array(
				'id'       => $s::int(),
				'password' => $s::str( 'New password.' ),
			), $profile ), array( 'id' ) ),
			'handler'      => array( $this, 'user_update' ),
		) );

		$r->register( 'user_delete', array(
			'category'     => 'users',
			'description'  => 'Delete a user. Their content is reassigned to reassign_to, or deleted if reassign_to is omitted. You cannot delete yourself.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'          => $s::int(),
				'reassign_to' => $s::int( 'User ID to receive their posts.' ),
				'dry_run'     => $s::dry_run(),
			), array( 'id' ) ),
			'handler'      => array( $this, 'user_delete' ),
		) );

		$r->register( 'user_send_password_reset', array(
			'category'     => 'users',
			'description'  => 'Email a user a password-reset link.',
			'writes'       => true,
			'open_world'   => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'user_send_password_reset' ),
		) );

		$r->register( 'user_sessions_destroy', array(
			'category'     => 'users',
			'description'  => 'Log a user out everywhere by destroying all of their sessions.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'user_sessions_destroy' ),
		) );

		$r->register( 'roles_list', array(
			'category'     => 'users',
			'description'  => 'List roles with their capabilities and user counts.',
			'input_schema' => $s::obj( array(
				'include_capabilities' => $s::bool( '', true ),
			) ),
			'handler'      => array( $this, 'roles_list' ),
		) );

		$r->register( 'role_create', array(
			'category'     => 'users',
			'description'  => 'Create a role, optionally cloning capabilities from an existing role.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'role'         => $s::str( 'Slug.' ),
				'name'         => $s::str( 'Display name.' ),
				'clone_from'   => $s::str( 'Copy capabilities from this role.' ),
				'capabilities' => $s::arr( 'string', 'Additional capabilities to grant.' ),
			), array( 'role', 'name' ) ),
			'handler'      => array( $this, 'role_create' ),
		) );

		$r->register( 'role_update', array(
			'category'     => 'users',
			'description'  => 'Add or remove capabilities on a role. Removing capabilities from administrator is refused.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'role'   => $s::str(),
				'add'    => $s::arr( 'string' ),
				'remove' => $s::arr( 'string' ),
			), array( 'role' ) ),
			'handler'      => array( $this, 'role_update' ),
		) );

		$r->register( 'role_delete', array(
			'category'     => 'users',
			'description'  => 'Delete a custom role. Users with only that role are moved to the default role.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'role' => $s::str() ), array( 'role' ) ),
			'handler'      => array( $this, 'role_delete' ),
		) );

		$r->register( 'application_passwords_list', array(
			'category'     => 'users',
			'description'  => 'List a user\'s application passwords (names, created and last-used dates — never the passwords).',
			'input_schema' => $s::obj( array( 'user_id' => $s::int() ), array( 'user_id' ) ),
			'handler'      => array( $this, 'application_passwords_list' ),
		) );

		$r->register( 'application_password_revoke', array(
			'category'     => 'users',
			'description'  => 'Revoke one of a user\'s application passwords by uuid.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'user_id' => $s::int(),
				'uuid'    => $s::str(),
			), array( 'user_id', 'uuid' ) ),
			'handler'      => array( $this, 'application_password_revoke' ),
		) );
	}

	public function users_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 50, 200 );
		$q        = array(
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => Site_Manager_Helpers::arg( $args, 'orderby', 'registered' ),
			'order'       => Site_Manager_Helpers::arg( $args, 'order', 'DESC' ),
			'count_total' => true,
		);
		if ( ! empty( $args['role'] ) ) {
			$q['role'] = (string) $args['role'];
		}
		if ( ! empty( $args['search'] ) ) {
			$q['search'] = '*' . trim( (string) $args['search'], '*' ) . '*';
		}
		if ( ! empty( $args['meta_key'] ) ) {
			$q['meta_key'] = (string) $args['meta_key'];
			if ( isset( $args['meta_value'] ) ) {
				$q['meta_value'] = (string) $args['meta_value'];
			}
		}
		$query = new WP_User_Query( $q );
		return Site_Manager_Helpers::paged(
			array_map( array( 'Site_Manager_Helpers', 'user_summary' ), $query->get_results() ),
			$query->get_total(),
			$page,
			$per_page
		);
	}

	private function resolve_user( array $args ) {
		$user = false;
		if ( ! empty( $args['id'] ) ) {
			$user = get_userdata( (int) $args['id'] );
		} elseif ( ! empty( $args['username'] ) ) {
			$user = get_user_by( 'login', (string) $args['username'] );
		} elseif ( ! empty( $args['email'] ) ) {
			$user = get_user_by( 'email', (string) $args['email'] );
		}
		return $user ? $user : new WP_Error( 'not_found', 'User not found.' );
	}

	public function user_get( array $args ) {
		$user = $this->resolve_user( $args );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$out = Site_Manager_Helpers::user_summary( $user );
		$out += array(
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'nickname'     => $user->nickname,
			'url'          => $user->user_url,
			'description'  => $user->description,
			'locale'       => get_user_locale( $user ),
			'post_count'   => (int) count_user_posts( $user->ID ),
			'capabilities' => array_keys( array_filter( $user->allcaps ) ),
			'meta'         => $this->user_meta( $user->ID, Site_Manager_Helpers::bool( $args, 'include_private' ) ),
		);
		if ( class_exists( 'Site_Manager_Tools_ACF' ) && Site_Manager_Tools_ACF::is_active() ) {
			$out['acf'] = Site_Manager_Tools_ACF::values_for( 'user_' . $user->ID );
		}
		return $out;
	}

	/** User meta minus core profile/capability keys already shown elsewhere. */
	private function user_meta( $user_id, $include_private ) {
		global $wpdb;
		$meta = (array) Site_Manager_Helpers::meta_for( 'user', $user_id, $include_private );
		$skip = array( 'session_tokens', $wpdb->get_blog_prefix() . 'capabilities', $wpdb->get_blog_prefix() . 'user_level', 'first_name', 'last_name', 'nickname', 'description', 'locale' );
		return (object) array_diff_key( $meta, array_flip( $skip ) );
	}

	private function userdata_from_args( array $args ) {
		$map = array(
			'email'        => 'user_email',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'display_name' => 'display_name',
			'nickname'     => 'nickname',
			'url'          => 'user_url',
			'description'  => 'description',
			'role'         => 'role',
			'locale'       => 'locale',
			'password'     => 'user_pass',
		);
		$data = array();
		foreach ( $map as $arg => $field ) {
			if ( isset( $args[ $arg ] ) ) {
				$data[ $field ] = $args[ $arg ];
			}
		}
		if ( isset( $data['role'] ) && ! get_role( $data['role'] ) ) {
			return new WP_Error( 'invalid_role', sprintf( 'Role "%s" does not exist.', $data['role'] ) );
		}
		return $data;
	}

	private function apply_user_extras( $user_id, array $args ) {
		$report = array();
		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			$report['meta'] = Site_Manager_Helpers::apply_meta( 'user', $user_id, $args['meta'] );
		}
		if ( ! empty( $args['acf'] ) && is_array( $args['acf'] ) && class_exists( 'Site_Manager_Tools_ACF' ) && Site_Manager_Tools_ACF::is_active() ) {
			$report['acf'] = Site_Manager_Tools_ACF::update_values( 'user_' . $user_id, $args['acf'] );
		}
		return $report;
	}

	public function user_create( array $args ) {
		$data = $this->userdata_from_args( $args );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['user_login'] = (string) $args['username'];
		if ( empty( $data['user_pass'] ) ) {
			$data['user_pass'] = wp_generate_password( 24, true, true );
		}
		$id = wp_insert_user( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( Site_Manager_Helpers::bool( $args, 'notify' ) ) {
			wp_new_user_notification( $id, null, 'user' );
		}
		return array( 'user' => Site_Manager_Helpers::user_summary( $id ), 'applied' => $this->apply_user_extras( $id, $args ) );
	}

	public function user_update( array $args ) {
		$user = $this->resolve_user( $args );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$data = $this->userdata_from_args( $args );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['role'] ) && $user->ID === get_current_user_id() && ! get_role( $data['role'] )->has_cap( 'manage_options' ) ) {
			return new WP_Error( 'self_demotion', 'Refusing to remove your own administrator access.' );
		}
		if ( $data ) {
			$data['ID'] = $user->ID;
			$result     = wp_update_user( $data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return array( 'user' => Site_Manager_Helpers::user_summary( $user->ID ), 'applied' => $this->apply_user_extras( $user->ID, $args ) );
	}

	public function user_delete( array $args ) {
		$user = $this->resolve_user( $args );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( $user->ID === get_current_user_id() ) {
			return new WP_Error( 'self_delete', 'You cannot delete the user you are connected as.' );
		}
		$reassign = Site_Manager_Helpers::arg( $args, 'reassign_to' );
		if ( $reassign && ! get_userdata( (int) $reassign ) ) {
			return new WP_Error( 'invalid_reassign', 'reassign_to user does not exist.' );
		}
		$summary               = Site_Manager_Helpers::user_summary( $user );
		$summary['post_count'] = (int) count_user_posts( $user->ID, array_keys( get_post_types() ) );
		if ( Site_Manager_Helpers::bool( $args, 'dry_run' ) ) {
			return array(
				'dry_run'      => true,
				'would_delete' => $summary,
				'their_posts'  => $reassign ? 'reassigned to user ' . (int) $reassign : 'deleted',
			);
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! wp_delete_user( $user->ID, $reassign ? (int) $reassign : null ) ) {
			return new WP_Error( 'delete_failed', 'User could not be deleted.' );
		}
		return array( 'deleted' => $summary );
	}

	public function user_send_password_reset( array $args ) {
		$user = $this->resolve_user( $args );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$result = retrieve_password( $user->user_login );
		return is_wp_error( $result ) ? $result : array( 'sent_to' => $user->user_email );
	}

	public function user_sessions_destroy( array $args ) {
		$user = $this->resolve_user( $args );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		return array( 'user_id' => $user->ID, 'sessions_destroyed' => true );
	}

	public function roles_list( array $args ) {
		$with_caps = Site_Manager_Helpers::bool( $args, 'include_capabilities', true );
		$counts    = count_users();
		$out       = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$row = array(
				'role'  => $slug,
				'name'  => translate_user_role( $role['name'] ),
				'users' => isset( $counts['avail_roles'][ $slug ] ) ? (int) $counts['avail_roles'][ $slug ] : 0,
			);
			if ( $with_caps ) {
				$row['capabilities'] = array_keys( array_filter( $role['capabilities'] ) );
			}
			$out[] = $row;
		}
		return $out;
	}

	public function role_create( array $args ) {
		$slug = sanitize_key( (string) $args['role'] );
		if ( get_role( $slug ) ) {
			return new WP_Error( 'exists', 'That role already exists.' );
		}
		$caps = array();
		if ( ! empty( $args['clone_from'] ) ) {
			$source = get_role( (string) $args['clone_from'] );
			if ( ! $source ) {
				return new WP_Error( 'invalid_role', 'clone_from role does not exist.' );
			}
			$caps = $source->capabilities;
		}
		foreach ( (array) Site_Manager_Helpers::arg( $args, 'capabilities', array() ) as $cap ) {
			$caps[ (string) $cap ] = true;
		}
		add_role( $slug, sanitize_text_field( $args['name'] ), $caps );
		return array( 'role' => $slug, 'capabilities' => array_keys( array_filter( $caps ) ) );
	}

	public function role_update( array $args ) {
		$role = get_role( (string) $args['role'] );
		if ( ! $role ) {
			return new WP_Error( 'invalid_role', 'Role does not exist.' );
		}
		if ( $role->name === 'administrator' && ! empty( $args['remove'] ) ) {
			return new WP_Error( 'protected', 'Refusing to remove capabilities from the administrator role.' );
		}
		foreach ( (array) Site_Manager_Helpers::arg( $args, 'add', array() ) as $cap ) {
			$role->add_cap( (string) $cap );
		}
		foreach ( (array) Site_Manager_Helpers::arg( $args, 'remove', array() ) as $cap ) {
			$role->remove_cap( (string) $cap );
		}
		$role = get_role( $role->name );
		return array( 'role' => $role->name, 'capabilities' => array_keys( array_filter( $role->capabilities ) ) );
	}

	public function role_delete( array $args ) {
		$slug = (string) $args['role'];
		if ( in_array( $slug, array( 'administrator', get_option( 'default_role' ) ), true ) ) {
			return new WP_Error( 'protected', 'Refusing to delete the administrator or default role.' );
		}
		if ( ! get_role( $slug ) ) {
			return new WP_Error( 'invalid_role', 'Role does not exist.' );
		}
		$moved = array();
		foreach ( get_users( array( 'role' => $slug ) ) as $user ) {
			$user->remove_role( $slug );
			if ( empty( $user->roles ) ) {
				$user->set_role( get_option( 'default_role' ) );
				$moved[] = $user->ID;
			}
		}
		remove_role( $slug );
		return array( 'deleted' => $slug, 'users_moved_to_default_role' => $moved );
	}

	public function application_passwords_list( array $args ) {
		$out = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( (int) $args['user_id'] ) as $item ) {
			$out[] = array(
				'uuid'      => $item['uuid'],
				'name'      => $item['name'],
				'created'   => $item['created'] ? gmdate( 'c', $item['created'] ) : null,
				'last_used' => $item['last_used'] ? gmdate( 'c', $item['last_used'] ) : null,
				'last_ip'   => $item['last_ip'],
			);
		}
		return $out;
	}

	public function application_password_revoke( array $args ) {
		$result = WP_Application_Passwords::delete_application_password( (int) $args['user_id'], (string) $args['uuid'] );
		return is_wp_error( $result ) ? $result : array( 'revoked' => $args['uuid'] );
	}
}

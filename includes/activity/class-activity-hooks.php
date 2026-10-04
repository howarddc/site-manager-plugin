<?php
/**
 * WordPress hooks that feed the activity log.
 *
 * Covers authentication, users and roles, plugins, themes, core and
 * translation updates (including automatic ones and failures), content,
 * media, taxonomies, menus, widgets, comments, settings, the file editor,
 * exports, privacy requests, and a few popular plugins.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Activity_Hooks {

	/** Post types that are internal plumbing or high-volume visitor data. */
	const IGNORED_POST_TYPES = array(
		'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'nav_menu_item', 'scheduled-action',
		'acf-field', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription',
		'flamingo_inbound', 'flamingo_outbound', 'flamingo_contact', 'wpforms_log', 'elementor_snippet',
	);

	/** Internal post types that are still worth logging, with friendlier labels. */
	const APPEARANCE_POST_TYPES = array(
		'custom_css'       => 'Additional CSS',
		'wp_global_styles' => 'Global styles',
		'wp_template'      => 'Template',
		'wp_template_part' => 'Template part',
		'wp_navigation'    => 'Navigation menu',
	);

	/** Options whose changes are security-relevant. */
	const SECURITY_OPTIONS = array( 'users_can_register', 'default_role', 'admin_email', 'siteurl', 'home', 'blog_public' );

	/** Options never worth logging even on a settings-page save. */
	const NOISY_OPTIONS = array( 'cron', 'rewrite_rules', 'recently_edited', 'recently_activated', 'uninstall_plugins', 'site_manager_settings' );

	/** Values captured before an action destroys them (plugin data, versions…). */
	private $before = array();

	/** WordPress version when this request started (for core update logging). */
	private $boot_wp_version;

	public function __construct() {
		$this->boot_wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '';

		// Authentication.
		add_action( 'wp_login', array( $this, 'login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'login_failed' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'logout' ) );
		add_action( 'retrieve_password', array( $this, 'password_reset_requested' ) );
		add_action( 'after_password_reset', array( $this, 'password_reset' ) );

		// Users.
		add_action( 'user_register', array( $this, 'user_created' ) );
		add_action( 'profile_update', array( $this, 'user_updated' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'user_role_changed' ), 10, 3 );
		add_action( 'delete_user', array( $this, 'user_deleted' ), 10, 2 );
		add_action( 'wp_create_application_password', array( $this, 'app_password_created' ), 10, 2 );
		add_action( 'wp_delete_application_password', array( $this, 'app_password_deleted' ), 10, 2 );
		add_action( 'granted_super_admin', array( $this, 'super_admin_granted' ) );
		add_action( 'revoked_super_admin', array( $this, 'super_admin_revoked' ) );

		// Plugins, themes, core.
		add_action( 'activated_plugin', array( $this, 'plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'plugin_deactivated' ), 10, 2 );
		add_action( 'delete_plugin', array( $this, 'plugin_deleting' ) );
		add_action( 'deleted_plugin', array( $this, 'plugin_deleted' ), 10, 2 );
		add_filter( 'upgrader_package_options', array( $this, 'capture_versions' ) );
		add_action( 'upgrader_process_complete', array( $this, 'upgrader_complete' ), 10, 2 );
		add_action( '_core_updated_successfully', array( $this, 'core_updated' ) );
		add_action( 'automatic_updates_complete', array( $this, 'automatic_updates_complete' ) );
		add_action( 'update_site_option_auto_update_plugins', array( $this, 'auto_updates_changed' ), 10, 3 );
		add_action( 'update_site_option_auto_update_themes', array( $this, 'auto_updates_changed' ), 10, 3 );
		add_action( 'add_site_option_auto_update_plugins', array( $this, 'auto_updates_added' ), 10, 2 );
		add_action( 'add_site_option_auto_update_themes', array( $this, 'auto_updates_added' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'theme_switched' ), 10, 3 );
		add_action( 'delete_theme', array( $this, 'theme_deleting' ) );
		add_action( 'deleted_theme', array( $this, 'theme_deleted' ), 10, 2 );
		add_action( 'customize_save_after', array( $this, 'customizer_saved' ) );
		add_action( 'wp_ajax_edit-theme-plugin-file', array( $this, 'file_edited' ), 1 );

		// Content.
		add_action( 'transition_post_status', array( $this, 'post_status_changed' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'post_deleted' ) );
		add_action( 'add_attachment', array( $this, 'attachment_added' ) );
		add_action( 'attachment_updated', array( $this, 'attachment_updated' ), 10, 3 );
		add_action( 'delete_attachment', array( $this, 'attachment_deleted' ) );
		add_action( 'elementor/document/after_save', array( $this, 'elementor_saved' ) );

		// Taxonomies and menus.
		add_action( 'created_term', array( $this, 'term_created' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'term_edited' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term_deleted' ), 10, 4 );
		add_action( 'wp_update_nav_menu', array( $this, 'menu_updated' ) );
		add_action( 'wp_update_nav_menu_item', array( $this, 'menu_updated' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'widgets_updated' ) );

		// Comments (moderation and comments by logged-in users).
		add_action( 'wp_insert_comment', array( $this, 'comment_posted' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'comment_edited' ) );
		add_action( 'transition_comment_status', array( $this, 'comment_status_changed' ), 10, 3 );
		add_action( 'delete_comment', array( $this, 'comment_deleted' ), 10, 2 );

		// Settings.
		add_action( 'updated_option', array( $this, 'option_updated' ), 10, 3 );
		add_action( 'added_option', array( $this, 'option_added' ), 10, 2 );

		// Data leaving the site.
		add_action( 'export_wp', array( $this, 'content_exported' ) );
		add_action( 'wp_privacy_personal_data_export_file_created', array( $this, 'privacy_exported' ), 10, 5 );
		add_action( 'wp_privacy_personal_data_erased', array( $this, 'privacy_erased' ) );

		// Plugins with their own data stores.
		add_action( 'woocommerce_order_status_changed', array( $this, 'wc_order_status' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'wc_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_settings_saved', array( $this, 'wc_settings_saved' ) );
		add_action( 'gform_after_save_form', array( $this, 'gf_form_saved' ), 10, 2 );
		add_action( 'gform_after_delete_form', array( $this, 'gf_form_deleted' ) );
		add_action( 'gform_delete_entry', array( $this, 'gf_entry_deleted' ) );
		add_action( 'redirection_redirect_updated', array( $this, 'redirect_saved' ), 10, 2 );
		add_action( 'redirection_redirect_deleted', array( $this, 'redirect_deleted' ) );
	}

	private static function log( array $e ) {
		Site_Manager_Activity::log( $e );
	}

	// ---------------------------------------------------------------
	// Authentication
	// ---------------------------------------------------------------

	public function login( $login, $user ) {
		self::log( array(
			'category'    => 'auth',
			'action'      => 'login',
			'user'        => $user,
			'object_type' => 'user',
			'object_id'   => $user->ID,
			'object_name' => $login,
			'message'     => sprintf( '%s logged in.', $login ),
		) );
	}

	public function login_failed( $username, $error = null ) {
		$exists = (bool) ( get_user_by( 'login', $username ) ?: get_user_by( 'email', $username ) );
		self::log( array(
			'category'    => 'auth',
			'action'      => 'login_failed',
			'severity'    => 'warning',
			'user'        => 0,
			'user_login'  => '',
			'object_type' => 'user',
			'object_name' => (string) $username,
			'message'     => sprintf( 'Failed login for %s"%s".', $exists ? '' : 'unknown user ', $username ),
			'details'     => array(
				'user_exists' => $exists,
				'reason'      => $error instanceof WP_Error ? $error->get_error_code() : null,
			),
			'dedupe'      => 15 * MINUTE_IN_SECONDS,
		) );
	}

	public function logout( $user_id = 0 ) {
		$user = get_userdata( $user_id ?: get_current_user_id() );
		if ( ! $user ) {
			return;
		}
		self::log( array(
			'category'    => 'auth',
			'action'      => 'logout',
			'user'        => $user,
			'object_type' => 'user',
			'object_id'   => $user->ID,
			'object_name' => $user->user_login,
			'message'     => sprintf( '%s logged out.', $user->user_login ),
		) );
	}

	public function password_reset_requested( $user_login ) {
		self::log( array(
			'category'    => 'auth',
			'action'      => 'password_reset_requested',
			'severity'    => 'notice',
			'object_type' => 'user',
			'object_name' => (string) $user_login,
			'message'     => sprintf( 'Password reset requested for %s.', $user_login ),
		) );
	}

	public function password_reset( $user ) {
		self::log( array(
			'category'    => 'user',
			'action'      => 'password_reset',
			'severity'    => user_can( $user, 'manage_options' ) ? 'warning' : 'notice',
			'user'        => $user,
			'object_type' => 'user',
			'object_id'   => $user->ID,
			'object_name' => $user->user_login,
			'message'     => sprintf( '%s reset their password via email link.', $user->user_login ),
		) );
	}

	// ---------------------------------------------------------------
	// Users
	// ---------------------------------------------------------------

	public function user_created( $user_id ) {
		$user  = get_userdata( $user_id );
		$roles = $user ? implode( ', ', $user->roles ) : '';
		$admin = $user && in_array( 'administrator', $user->roles, true );
		self::log( array(
			'category'    => 'user',
			'action'      => 'user_created',
			'severity'    => $admin ? 'critical' : 'notice',
			'object_type' => 'user',
			'object_id'   => $user_id,
			'object_name' => $user ? $user->user_login : '',
			'message'     => get_current_user_id()
				? sprintf( 'User %s created with role %s.', $user ? $user->user_login : $user_id, $roles ?: '(none)' )
				: sprintf( 'User %s registered (role %s).', $user ? $user->user_login : $user_id, $roles ?: '(none)' ),
			'details'     => array( 'email' => $user ? $user->user_email : null, 'roles' => $user ? $user->roles : array() ),
		) );
	}

	public function user_updated( $user_id, $old ) {
		$new = get_userdata( $user_id );
		if ( ! $new || ! $old instanceof WP_User ) {
			return;
		}
		$changes = array();
		foreach ( array( 'user_email' => 'email', 'display_name' => 'display name', 'user_url' => 'website', 'user_login' => 'username' ) as $field => $label ) {
			if ( $old->$field !== $new->$field ) {
				$changes[ $label ] = array( 'from' => $old->$field, 'to' => $new->$field );
			}
		}
		if ( $old->user_pass !== $new->user_pass ) {
			self::log( array(
				'category'    => 'user',
				'action'      => 'password_changed',
				'severity'    => user_can( $new, 'manage_options' ) ? 'warning' : 'notice',
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $new->user_login,
				'message'     => get_current_user_id() === (int) $user_id
					? sprintf( '%s changed their password.', $new->user_login )
					: sprintf( 'Password changed for %s.', $new->user_login ),
			) );
		}
		if ( ! $changes ) {
			return;
		}
		self::log( array(
			'category'    => 'user',
			'action'      => 'user_updated',
			'severity'    => isset( $changes['email'] ) && user_can( $new, 'manage_options' ) ? 'warning' : 'info',
			'object_type' => 'user',
			'object_id'   => $user_id,
			'object_name' => $new->user_login,
			'message'     => sprintf( 'Profile of %s updated (%s).', $new->user_login, implode( ', ', array_keys( $changes ) ) ),
			'details'     => $changes,
			'dedupe'      => 60,
		) );
	}

	public function user_role_changed( $user_id, $role, $old_roles ) {
		if ( array_values( (array) $old_roles ) === array( $role ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		// wp_insert_user() assigns the initial role before user_register fires;
		// that's part of user creation, which user_created() logs.
		if ( ! $old_roles && $user && strtotime( $user->user_registered . ' UTC' ) >= time() - 30 ) {
			return;
		}
		$elevated = $role === 'administrator' && ! in_array( 'administrator', (array) $old_roles, true );
		$demoted  = in_array( 'administrator', (array) $old_roles, true ) && $role !== 'administrator';
		self::log( array(
			'category'    => 'user',
			'action'      => 'user_role_changed',
			'severity'    => $elevated ? 'critical' : ( $demoted ? 'warning' : 'notice' ),
			'object_type' => 'user',
			'object_id'   => $user_id,
			'object_name' => $user ? $user->user_login : '',
			'message'     => sprintf( 'Role of %s changed from %s to %s.', $user ? $user->user_login : $user_id, $old_roles ? implode( ', ', $old_roles ) : '(none)', $role ?: '(none)' ),
			'details'     => array( 'from' => array_values( (array) $old_roles ), 'to' => $role ),
		) );
	}

	public function user_deleted( $user_id, $reassign ) {
		$user = get_userdata( $user_id );
		self::log( array(
			'category'    => 'user',
			'action'      => 'user_deleted',
			'severity'    => $user && in_array( 'administrator', $user->roles, true ) ? 'critical' : 'warning',
			'object_type' => 'user',
			'object_id'   => $user_id,
			'object_name' => $user ? $user->user_login : '',
			'message'     => sprintf( 'User %s deleted%s.', $user ? $user->user_login : $user_id, $reassign ? sprintf( '; content reassigned to user %d', $reassign ) : '; their content was deleted' ),
			'details'     => array( 'email' => $user ? $user->user_email : null, 'roles' => $user ? $user->roles : array(), 'reassign' => $reassign ),
		) );
	}

	public function app_password_created( $user_id, $item ) {
		$user = get_userdata( $user_id );
		self::log( array(
			'category'    => 'user',
			'action'      => 'app_password_created',
			'severity'    => 'warning',
			'object_type' => 'user',
			'object_id'   => $user_id,
			'object_name' => $user ? $user->user_login : '',
			'message'     => sprintf( 'Application password "%s" created for %s.', $item['name'], $user ? $user->user_login : $user_id ),
		) );
	}

	public function app_password_deleted( $user_id, $item ) {
		$user = get_userdata( $user_id );
		self::log( array(
			'category'    => 'user',
			'action'      => 'app_password_revoked',
			'severity'    => 'notice',
			'object_type' => 'user',
			'object_id'   => $user_id,
			'object_name' => $user ? $user->user_login : '',
			'message'     => sprintf( 'Application password "%s" revoked for %s.', $item['name'], $user ? $user->user_login : $user_id ),
		) );
	}

	public function super_admin_granted( $user_id ) {
		$user = get_userdata( $user_id );
		self::log( array( 'category' => 'user', 'action' => 'super_admin_granted', 'severity' => 'critical', 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $user ? $user->user_login : '', 'message' => sprintf( 'Super admin granted to %s.', $user ? $user->user_login : $user_id ) ) );
	}

	public function super_admin_revoked( $user_id ) {
		$user = get_userdata( $user_id );
		self::log( array( 'category' => 'user', 'action' => 'super_admin_revoked', 'severity' => 'warning', 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $user ? $user->user_login : '', 'message' => sprintf( 'Super admin revoked from %s.', $user ? $user->user_login : $user_id ) ) );
	}

	// ---------------------------------------------------------------
	// Plugins, themes, core
	// ---------------------------------------------------------------

	private static function plugin_data( $file ) {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return array( 'Name' => $file, 'Version' => '' );
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugin_data( $path, false, false );
	}

	public function plugin_activated( $file, $network ) {
		$data = self::plugin_data( $file );
		self::log( array(
			'category'    => 'plugin',
			'action'      => 'plugin_activated',
			'severity'    => 'notice',
			'object_type' => 'plugin',
			'object_id'   => $file,
			'object_name' => $data['Name'],
			'message'     => sprintf( 'Plugin activated: %s %s%s.', $data['Name'], $data['Version'], $network ? ' (network-wide)' : '' ),
			'details'     => array( 'version' => $data['Version'], 'network' => (bool) $network ),
		) );
	}

	public function plugin_deactivated( $file, $network ) {
		$data = self::plugin_data( $file );
		self::log( array(
			'category'    => 'plugin',
			'action'      => 'plugin_deactivated',
			'severity'    => $file === plugin_basename( SITE_MANAGER_FILE ) ? 'critical' : 'notice',
			'object_type' => 'plugin',
			'object_id'   => $file,
			'object_name' => $data['Name'],
			'message'     => sprintf( 'Plugin deactivated: %s %s%s.', $data['Name'], $data['Version'], $network ? ' (network-wide)' : '' ),
			'details'     => array( 'version' => $data['Version'], 'network' => (bool) $network ),
		) );
	}

	public function plugin_deleting( $file ) {
		$this->before[ 'plugin:' . $file ] = self::plugin_data( $file );
	}

	public function plugin_deleted( $file, $deleted ) {
		$data = isset( $this->before[ 'plugin:' . $file ] ) ? $this->before[ 'plugin:' . $file ] : array( 'Name' => $file, 'Version' => '' );
		self::log( array(
			'category'    => 'plugin',
			'action'      => $deleted ? 'plugin_deleted' : 'plugin_delete_failed',
			'severity'    => 'warning',
			'object_type' => 'plugin',
			'object_id'   => $file,
			'object_name' => $data['Name'],
			'message'     => sprintf( $deleted ? 'Plugin deleted: %s %s.' : 'Failed to delete plugin %s %s.', $data['Name'], $data['Version'] ),
			'details'     => array( 'version' => $data['Version'] ),
		) );
	}

	/** Remember installed versions before an update replaces the files. */
	public function capture_versions( $options ) {
		$extra = isset( $options['hook_extra'] ) ? (array) $options['hook_extra'] : array();
		if ( ! empty( $extra['plugin'] ) ) {
			$data = self::plugin_data( $extra['plugin'] );
			$this->before[ 'version:plugin:' . $extra['plugin'] ] = $data['Version'];
		}
		if ( ! empty( $extra['theme'] ) ) {
			$theme = wp_get_theme( $extra['theme'] );
			$this->before[ 'version:theme:' . $extra['theme'] ] = $theme->exists() ? $theme->get( 'Version' ) : '';
		}
		return $options;
	}

	private static function automatic() {
		return did_action( 'pre_auto_update' ) > 0 || wp_doing_cron();
	}

	public function upgrader_complete( $upgrader, $extra ) {
		$type   = isset( $extra['type'] ) ? $extra['type'] : '';
		$action = isset( $extra['action'] ) ? $extra['action'] : '';
		$auto   = self::automatic();

		if ( $type === 'plugin' ) {
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache( false );
			}
			if ( $action === 'install' ) {
				$file = method_exists( $upgrader, 'plugin_info' ) ? $upgrader->plugin_info() : '';
				if ( ! $file ) {
					return;
				}
				$data = self::plugin_data( $file );
				$overwrite = ! empty( $upgrader->skin->overwrite ) ? $upgrader->skin->overwrite : '';
				self::log( array(
					'category'    => 'plugin',
					'action'      => $overwrite ? 'plugin_updated' : 'plugin_installed',
					'severity'    => 'notice',
					'object_type' => 'plugin',
					'object_id'   => $file,
					'object_name' => $data['Name'],
					'message'     => sprintf( $overwrite ? 'Plugin replaced by upload: %s %s.' : 'Plugin installed: %s %s.', $data['Name'], $data['Version'] ),
					'details'     => array( 'version' => $data['Version'], 'method' => $overwrite ? 'upload-replace' : 'install' ),
				) );
				return;
			}
			$files = ! empty( $extra['plugins'] ) ? (array) $extra['plugins'] : ( ! empty( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
			foreach ( $files as $file ) {
				$data = self::plugin_data( $file );
				$from = isset( $this->before[ 'version:plugin:' . $file ] ) ? $this->before[ 'version:plugin:' . $file ] : '';
				if ( $from !== '' && $from === $data['Version'] ) {
					continue; // Nothing changed (failed or skipped).
				}
				self::log( array(
					'category'    => 'plugin',
					'action'      => 'plugin_updated',
					'severity'    => 'notice',
					'object_type' => 'plugin',
					'object_id'   => $file,
					'object_name' => $data['Name'],
					'message'     => sprintf( 'Plugin %supdated: %s %s → %s.', $auto ? 'auto-' : '', $data['Name'], $from ?: '?', $data['Version'] ),
					'details'     => array( 'from' => $from, 'to' => $data['Version'], 'automatic' => $auto ),
				) );
			}
		} elseif ( $type === 'theme' ) {
			if ( $action === 'install' ) {
				$theme = method_exists( $upgrader, 'theme_info' ) ? $upgrader->theme_info() : null;
				if ( ! $theme ) {
					return;
				}
				self::log( array(
					'category'    => 'theme',
					'action'      => 'theme_installed',
					'severity'    => 'notice',
					'object_type' => 'theme',
					'object_id'   => $theme->get_stylesheet(),
					'object_name' => $theme->get( 'Name' ),
					'message'     => sprintf( 'Theme installed: %s %s.', $theme->get( 'Name' ), $theme->get( 'Version' ) ),
					'details'     => array( 'version' => $theme->get( 'Version' ) ),
				) );
				return;
			}
			$slugs = ! empty( $extra['themes'] ) ? (array) $extra['themes'] : ( ! empty( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
			foreach ( $slugs as $slug ) {
				$theme = wp_get_theme( $slug );
				$to    = $theme->get( 'Version' );
				$from  = isset( $this->before[ 'version:theme:' . $slug ] ) ? $this->before[ 'version:theme:' . $slug ] : '';
				if ( $from !== '' && $from === $to ) {
					continue;
				}
				self::log( array(
					'category'    => 'theme',
					'action'      => 'theme_updated',
					'severity'    => 'notice',
					'object_type' => 'theme',
					'object_id'   => $slug,
					'object_name' => $theme->get( 'Name' ),
					'message'     => sprintf( 'Theme %supdated: %s %s → %s.', $auto ? 'auto-' : '', $theme->get( 'Name' ), $from ?: '?', $to ),
					'details'     => array( 'from' => $from, 'to' => $to, 'automatic' => $auto ),
				) );
			}
		} elseif ( $type === 'translation' && ! empty( $extra['translations'] ) ) {
			$items = array();
			foreach ( (array) $extra['translations'] as $t ) {
				$items[] = trim( ( isset( $t['type'] ) ? $t['type'] : '' ) . ' ' . ( isset( $t['slug'] ) ? $t['slug'] : '' ) . ' (' . ( isset( $t['language'] ) ? $t['language'] : '' ) . ')' );
			}
			self::log( array(
				'category'    => 'core',
				'action'      => 'translations_updated',
				'object_type' => 'translation',
				'object_name' => count( $items ) . ' translation(s)',
				'message'     => sprintf( '%d translation%s %supdated.', count( $items ), count( $items ) === 1 ? '' : 's', $auto ? 'auto-' : '' ),
				'details'     => array( 'items' => $items, 'automatic' => $auto ),
			) );
		}
	}

	public function core_updated( $new_version ) {
		$auto = self::automatic();
		self::log( array(
			'category'    => 'core',
			'action'      => 'core_updated',
			'severity'    => 'notice',
			'object_type' => 'core',
			'object_id'   => 'wordpress',
			'object_name' => 'WordPress',
			'message'     => sprintf( 'WordPress %supdated: %s → %s.', $auto ? 'auto-' : '', $this->boot_wp_version ?: '?', $new_version ),
			'details'     => array( 'from' => $this->boot_wp_version, 'to' => $new_version, 'automatic' => $auto ),
		) );
	}

	/** Record automatic updates that failed (successes are logged by the upgrader hooks). */
	public function automatic_updates_complete( $results ) {
		foreach ( (array) $results as $type => $items ) {
			foreach ( (array) $items as $item ) {
				if ( ! is_object( $item ) || ! is_wp_error( $item->result ) && $item->result !== false ) {
					continue;
				}
				$name = isset( $item->name ) ? $item->name : ( isset( $item->item->slug ) ? $item->item->slug : $type );
				self::log( array(
					'category'    => $type === 'translation' ? 'core' : $type,
					'action'      => 'update_failed',
					'severity'    => 'warning',
					'object_type' => $type,
					'object_name' => $name,
					'message'     => sprintf( 'Automatic %s update failed: %s.', $type, $name ),
					'details'     => array( 'error' => is_wp_error( $item->result ) ? $item->result->get_error_message() : 'unknown', 'messages' => isset( $item->messages ) ? $item->messages : array() ),
				) );
			}
		}
	}

	public function auto_updates_changed( $option, $value, $old ) {
		$type    = $option === 'auto_update_themes' ? 'theme' : 'plugin';
		$enabled = array_diff( (array) $value, (array) $old );
		$off     = array_diff( (array) $old, (array) $value );
		foreach ( array( 'enabled' => $enabled, 'disabled' => $off ) as $state => $items ) {
			foreach ( $items as $item ) {
				$name = $type === 'plugin' ? self::plugin_data( $item )['Name'] : wp_get_theme( $item )->get( 'Name' );
				self::log( array(
					'category'    => $type,
					'action'      => 'auto_update_' . $state,
					'severity'    => $state === 'disabled' ? 'notice' : 'info',
					'object_type' => $type,
					'object_id'   => $item,
					'object_name' => $name,
					'message'     => sprintf( 'Auto-updates %s for %s %s.', $state, $type, $name ),
				) );
			}
		}
	}

	public function auto_updates_added( $option, $value ) {
		$this->auto_updates_changed( $option, $value, array() );
	}

	public function theme_switched( $new_name, $new_theme, $old_theme ) {
		self::log( array(
			'category'    => 'theme',
			'action'      => 'theme_switched',
			'severity'    => 'warning',
			'object_type' => 'theme',
			'object_id'   => $new_theme->get_stylesheet(),
			'object_name' => $new_name,
			'message'     => sprintf( 'Theme switched from %s to %s.', $old_theme ? $old_theme->get( 'Name' ) : '?', $new_name ),
			'details'     => array( 'from' => $old_theme ? $old_theme->get_stylesheet() : null, 'to' => $new_theme->get_stylesheet() ),
		) );
	}

	public function theme_deleting( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		$this->before[ 'theme:' . $stylesheet ] = array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) );
	}

	public function theme_deleted( $stylesheet, $deleted ) {
		$data = isset( $this->before[ 'theme:' . $stylesheet ] ) ? $this->before[ 'theme:' . $stylesheet ] : array( 'name' => $stylesheet, 'version' => '' );
		self::log( array(
			'category'    => 'theme',
			'action'      => $deleted ? 'theme_deleted' : 'theme_delete_failed',
			'severity'    => 'warning',
			'object_type' => 'theme',
			'object_id'   => $stylesheet,
			'object_name' => $data['name'],
			'message'     => sprintf( $deleted ? 'Theme deleted: %s %s.' : 'Failed to delete theme %s %s.', $data['name'], $data['version'] ),
		) );
	}

	public function customizer_saved( $manager ) {
		$settings = method_exists( $manager, 'unsanitized_post_values' ) ? array_keys( (array) $manager->unsanitized_post_values() ) : array();
		self::log( array(
			'category'    => 'theme',
			'action'      => 'customizer_saved',
			'severity'    => 'notice',
			'object_type' => 'theme',
			'object_id'   => get_stylesheet(),
			'object_name' => wp_get_theme()->get( 'Name' ),
			'message'     => sprintf( 'Customizer changes published (%d setting%s).', count( $settings ), count( $settings ) === 1 ? '' : 's' ),
			'details'     => array( 'settings' => $settings ),
		) );
	}

	public function file_edited() {
		$file   = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$owner  = isset( $_POST['plugin'] ) ? 'plugin ' . sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : ( isset( $_POST['theme'] ) ? 'theme ' . sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '' );
		self::log( array(
			'category'    => 'file',
			'action'      => 'file_edited',
			'severity'    => 'critical',
			'object_type' => 'file',
			'object_id'   => $file,
			'object_name' => $file,
			'message'     => sprintf( 'File edited in the built-in editor: %s (%s).', $file, $owner ),
		) );
	}

	// ---------------------------------------------------------------
	// Content
	// ---------------------------------------------------------------

	private static function post_type_label( $post_type ) {
		if ( isset( self::APPEARANCE_POST_TYPES[ $post_type ] ) ) {
			return self::APPEARANCE_POST_TYPES[ $post_type ];
		}
		$obj = get_post_type_object( $post_type );
		return $obj ? $obj->labels->singular_name : $post_type;
	}

	/** Whether changes to this post should be logged. */
	private static function trackable( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( in_array( $post->post_type, (array) apply_filters( 'site_manager_activity_ignored_post_types', self::IGNORED_POST_TYPES ), true ) ) {
			return false;
		}
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return false;
		}
		if ( isset( self::APPEARANCE_POST_TYPES[ $post->post_type ] ) ) {
			return true;
		}
		$obj = get_post_type_object( $post->post_type );
		return $obj && $obj->show_ui;
	}

	private static function post_category( WP_Post $post ) {
		return isset( self::APPEARANCE_POST_TYPES[ $post->post_type ] ) ? 'theme' : ( $post->post_type === 'attachment' ? 'media' : 'post' );
	}

	private static function post_event( WP_Post $post, $action, $message, $severity = 'info', array $details = array(), $dedupe = 0 ) {
		$title = $post->post_title !== '' ? $post->post_title : '(no title)';
		self::log( array(
			'category'    => self::post_category( $post ),
			'action'      => $action,
			'severity'    => $severity,
			'object_type' => $post->post_type,
			'object_id'   => $post->ID,
			'object_name' => $title,
			'message'     => sprintf( $message, self::post_type_label( $post->post_type ), $title ),
			'details'     => $details,
			'dedupe'      => $dedupe,
		) );
	}

	public function post_status_changed( $new, $old, $post ) {
		if ( $new === $old || $new === 'auto-draft' || $new === 'inherit' || ! self::trackable( $post ) ) {
			return;
		}
		$details = array( 'from' => $old, 'to' => $new );
		if ( $new === 'trash' ) {
			$msg    = '%s "%s" moved to trash.';
			$action = 'trashed';
		} elseif ( $old === 'trash' ) {
			$msg    = '%s "%s" restored from trash (now ' . $new . ').';
			$action = 'restored';
		} elseif ( $new === 'publish' ) {
			$msg    = $old === 'future' ? '%s "%s" published (scheduled).' : '%s "%s" published.';
			$action = 'published';
		} elseif ( $new === 'future' ) {
			$msg    = '%s "%s" scheduled for ' . $post->post_date . '.';
			$action = 'scheduled';
		} elseif ( $old === 'new' || $old === 'auto-draft' ) {
			$msg    = '%s "%s" created as ' . $new . '.';
			$action = 'created';
		} elseif ( $old === 'publish' ) {
			$msg    = '%s "%s" unpublished (now ' . $new . ').';
			$action = 'unpublished';
		} else {
			$msg    = '%s "%s" status changed from ' . $old . ' to ' . $new . '.';
			$action = 'status_changed';
		}
		self::post_event( $post, $action, $msg, $action === 'unpublished' || $action === 'trashed' ? 'notice' : 'info', $details );
	}

	public function post_updated( $post_id, $after, $before ) {
		if ( $after->post_status !== $before->post_status || in_array( $after->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) || ! self::trackable( $after ) ) {
			return;
		}
		$fields  = array( 'post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt', 'post_name' => 'slug', 'post_author' => 'author', 'post_parent' => 'parent', 'post_date' => 'date', 'menu_order' => 'order', 'post_password' => 'password' );
		$changed = array();
		foreach ( $fields as $field => $label ) {
			if ( (string) $after->$field !== (string) $before->$field ) {
				$changed[] = $label;
			}
		}
		if ( ! $changed ) {
			return;
		}
		$details = array( 'changed' => $changed );
		if ( in_array( 'title', $changed, true ) ) {
			$details['title'] = array( 'from' => $before->post_title, 'to' => $after->post_title );
		}
		if ( in_array( 'slug', $changed, true ) ) {
			$details['slug'] = array( 'from' => $before->post_name, 'to' => $after->post_name );
		}
		self::post_event( $after, 'updated', '%s "%s" updated.', 'info', $details, 5 * MINUTE_IN_SECONDS );
	}

	public function post_deleted( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status === 'auto-draft' || $post->post_type === 'attachment' || ! self::trackable( $post ) ) {
			return;
		}
		self::post_event( $post, 'deleted', '%s "%s" permanently deleted.', 'warning', array( 'status' => $post->post_status ) );
	}

	public function attachment_added( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::post_event( $post, 'uploaded', '%s uploaded: "%s".', 'info', array( 'mime_type' => $post->post_mime_type, 'file' => wp_basename( (string) get_attached_file( $post_id ) ) ) );
		}
	}

	public function attachment_updated( $post_id, $after, $before ) {
		if ( $after->post_title !== $before->post_title || $after->post_excerpt !== $before->post_excerpt || $after->post_content !== $before->post_content ) {
			self::post_event( $after, 'updated', '%s "%s" details updated.', 'info', array(), 5 * MINUTE_IN_SECONDS );
		}
	}

	public function attachment_deleted( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::post_event( $post, 'deleted', '%s deleted: "%s".', 'notice', array( 'file' => wp_basename( (string) get_attached_file( $post_id ) ) ) );
		}
	}

	public function elementor_saved( $document ) {
		$post = method_exists( $document, 'get_post' ) ? $document->get_post() : null;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( $post->post_type === 'elementor_library' && get_post_meta( $post->ID, '_elementor_template_type', true ) === 'kit' ) {
			self::log( array( 'category' => 'theme', 'action' => 'elementor_kit_updated', 'severity' => 'notice', 'object_type' => 'elementor_kit', 'object_id' => $post->ID, 'object_name' => $post->post_title, 'message' => 'Elementor site settings (kit) updated.', 'dedupe' => 5 * MINUTE_IN_SECONDS ) );
			return;
		}
		if ( self::trackable( $post ) ) {
			self::post_event( $post, 'updated', '%s "%s" edited with Elementor.', 'info', array( 'builder' => 'elementor' ), 5 * MINUTE_IN_SECONDS );
		}
	}

	// ---------------------------------------------------------------
	// Taxonomies, menus, widgets
	// ---------------------------------------------------------------

	private static function tax_trackable( $taxonomy ) {
		if ( $taxonomy === 'nav_menu' ) {
			return true;
		}
		$tax = get_taxonomy( $taxonomy );
		return $tax && $tax->show_ui;
	}

	private static function term_event( $term_id, $taxonomy, $action, $verb, $term = null ) {
		if ( ! self::tax_trackable( $taxonomy ) ) {
			return;
		}
		$term  = $term ?: get_term( $term_id, $taxonomy );
		$name  = $term && ! is_wp_error( $term ) ? $term->name : (string) $term_id;
		$menu  = $taxonomy === 'nav_menu';
		$label = $menu ? 'Menu' : ( get_taxonomy( $taxonomy ) ? get_taxonomy( $taxonomy )->labels->singular_name : $taxonomy );
		self::log( array(
			'category'    => $menu ? 'menu' : 'taxonomy',
			'action'      => ( $menu ? 'menu_' : 'term_' ) . $action,
			'severity'    => $action === 'deleted' ? 'notice' : 'info',
			'object_type' => $taxonomy,
			'object_id'   => $term_id,
			'object_name' => $name,
			'message'     => sprintf( '%s "%s" %s.', $label, $name, $verb ),
		) );
	}

	public function term_created( $term_id, $tt_id, $taxonomy ) {
		self::term_event( $term_id, $taxonomy, 'created', 'created' );
	}

	public function term_edited( $term_id, $tt_id, $taxonomy ) {
		self::term_event( $term_id, $taxonomy, 'updated', 'updated' );
	}

	public function term_deleted( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		self::term_event( $term_id, $taxonomy, 'deleted', 'deleted', $deleted_term );
	}

	public function menu_updated( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		self::log( array(
			'category'    => 'menu',
			'action'      => 'menu_items_updated',
			'object_type' => 'nav_menu',
			'object_id'   => $menu_id,
			'object_name' => $menu ? $menu->name : '',
			'message'     => sprintf( 'Menu "%s" items updated.', $menu ? $menu->name : $menu_id ),
			'dedupe'      => 2 * MINUTE_IN_SECONDS,
		) );
	}

	public function widgets_updated() {
		if ( did_action( 'switch_theme' ) ) {
			return; // Side effect of the theme switch, which is logged itself.
		}
		self::log( array(
			'category'    => 'theme',
			'action'      => 'widgets_updated',
			'object_type' => 'widgets',
			'object_name' => 'Widgets',
			'message'     => 'Widget areas updated.',
			'dedupe'      => 2 * MINUTE_IN_SECONDS,
		) );
	}

	// ---------------------------------------------------------------
	// Comments
	// ---------------------------------------------------------------

	private static function comment_event( $comment, $action, $message, $severity = 'info' ) {
		$comment = get_comment( $comment );
		if ( ! $comment ) {
			return;
		}
		self::log( array(
			'category'    => 'comment',
			'action'      => $action,
			'severity'    => $severity,
			'object_type' => 'comment',
			'object_id'   => $comment->comment_ID,
			'object_name' => get_the_title( $comment->comment_post_ID ),
			'message'     => sprintf( $message, $comment->comment_author, get_the_title( $comment->comment_post_ID ) ),
		) );
	}

	public function comment_posted( $id, $comment ) {
		if ( (int) $comment->user_id > 0 ) {
			self::comment_event( $comment, 'comment_posted', 'Comment by %s posted on "%s".' );
		}
	}

	public function comment_edited( $id ) {
		if ( is_user_logged_in() ) {
			self::comment_event( $id, 'comment_edited', 'Comment by %s on "%s" edited.' );
		}
	}

	public function comment_status_changed( $new, $old, $comment ) {
		if ( ! is_user_logged_in() || $new === $old ) {
			return;
		}
		$labels = array( 'approved' => 'approved', 'unapproved' => 'unapproved', 'spam' => 'marked as spam', 'trash' => 'trashed' );
		self::comment_event( $comment, 'comment_' . $new, 'Comment by %s on "%s" ' . ( isset( $labels[ $new ] ) ? $labels[ $new ] : $new ) . '.' );
	}

	public function comment_deleted( $id, $comment ) {
		if ( is_user_logged_in() ) {
			self::comment_event( $comment, 'comment_deleted', 'Comment by %s on "%s" permanently deleted.', 'notice' );
		}
	}

	// ---------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------

	private static function watched_options() {
		static $list = null;
		if ( $list === null ) {
			$list = array_merge( call_user_func_array( 'array_merge', array_values( Site_Manager_Tools_Settings::groups() ) ), self::SECURITY_OPTIONS );
		}
		return $list;
	}

	/** True during a Settings API form submit (options.php), which covers most plugin settings pages too. */
	private static function settings_form_submit() {
		return is_admin() && isset( $_POST['option_page'] ) && did_action( 'load-options.php' );
	}

	private static function short( $value ) {
		if ( is_scalar( $value ) || $value === null ) {
			$value = (string) $value;
			return strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '…' : $value;
		}
		$json = wp_json_encode( $value );
		return strlen( (string) $json ) > 1000 ? '(' . strlen( $json ) . ' bytes of structured data)' : $value;
	}

	public function option_updated( $option, $old, $new ) {
		if ( $option === 'site_manager_settings' ) {
			$this->site_manager_settings_changed( (array) $old, (array) $new );
			return;
		}
		if ( in_array( $option, self::NOISY_OPTIONS, true ) || strpos( $option, '_transient' ) === 0 || strpos( $option, '_site_transient' ) === 0 ) {
			return;
		}
		// WordPress fires this when only the type changed ("10" → 10).
		if ( ( is_scalar( $old ) || $old === null ) && ( is_scalar( $new ) || $new === null ) && (string) $old === (string) $new ) {
			return;
		}
		$watched = in_array( $option, self::watched_options(), true );
		if ( ! $watched && ! self::settings_form_submit() ) {
			return;
		}
		$security = in_array( $option, self::SECURITY_OPTIONS, true );
		self::log( array(
			'category'    => 'setting',
			'action'      => 'option_updated',
			'severity'    => $security ? 'warning' : 'notice',
			'object_type' => 'option',
			'object_id'   => $option,
			'object_name' => $option,
			'message'     => sprintf( 'Setting "%s" changed.', $option ),
			'details'     => array( 'from' => self::short( $old ), 'to' => self::short( $new ), 'page' => isset( $_POST['option_page'] ) ? sanitize_key( $_POST['option_page'] ) : null ),
		) );
	}

	public function option_added( $option, $value ) {
		if ( ! self::settings_form_submit() || in_array( $option, self::NOISY_OPTIONS, true ) || strpos( $option, '_transient' ) === 0 ) {
			return;
		}
		self::log( array(
			'category'    => 'setting',
			'action'      => 'option_added',
			'severity'    => 'notice',
			'object_type' => 'option',
			'object_id'   => $option,
			'object_name' => $option,
			'message'     => sprintf( 'Setting "%s" saved for the first time.', $option ),
			'details'     => array( 'value' => self::short( $value ) ),
		) );
	}

	private function site_manager_settings_changed( array $old, array $new ) {
		$gates = Site_Manager_Settings::gates();
		foreach ( $gates as $key => $gate ) {
			$was = ! empty( $old[ $key ] );
			$now = ! empty( $new[ $key ] );
			if ( $was !== $now ) {
				self::log( array(
					'category'    => 'site_manager',
					'action'      => $now ? 'capability_enabled' : 'capability_disabled',
					'severity'    => $now ? 'critical' : 'notice',
					'object_type' => 'setting',
					'object_id'   => $key,
					'object_name' => $gate['label'],
					'message'     => sprintf( 'Site Manager: %s %s for MCP clients.', $gate['label'], $now ? 'ENABLED' : 'disabled' ),
				) );
			}
		}
		$rest = array_diff_key( $new, $gates );
		$prev = array_diff_key( $old, $gates );
		if ( wp_json_encode( $rest ) !== wp_json_encode( $prev ) ) {
			self::log( array(
				'category'    => 'site_manager',
				'action'      => 'settings_updated',
				'severity'    => 'notice',
				'object_type' => 'setting',
				'object_id'   => 'site_manager_settings',
				'object_name' => 'Site Manager settings',
				'message'     => 'Site Manager settings changed.',
				'details'     => array( 'from' => $prev, 'to' => $rest ),
			) );
		}
	}

	// ---------------------------------------------------------------
	// Exports and privacy
	// ---------------------------------------------------------------

	public function content_exported( $args ) {
		self::log( array(
			'category'    => 'export',
			'action'      => 'content_exported',
			'severity'    => 'warning',
			'object_type' => 'export',
			'object_name' => isset( $args['content'] ) ? (string) $args['content'] : 'all',
			'message'     => sprintf( 'Site content exported (WXR, content: %s).', isset( $args['content'] ) ? $args['content'] : 'all' ),
			'details'     => $args,
		) );
	}

	public function privacy_exported( $archive_pathname, $archive_url, $html_report_pathname, $request_id, $json_report_pathname = '' ) {
		$request = function_exists( 'wp_get_user_request' ) ? wp_get_user_request( $request_id ) : null;
		self::log( array(
			'category'    => 'privacy',
			'action'      => 'personal_data_exported',
			'severity'    => 'notice',
			'object_type' => 'user_request',
			'object_id'   => $request_id,
			'object_name' => $request ? $request->email : '',
			'message'     => sprintf( 'Personal data export generated for %s.', $request ? $request->email : '#' . $request_id ),
		) );
	}

	public function privacy_erased( $request_id ) {
		$request = function_exists( 'wp_get_user_request' ) ? wp_get_user_request( $request_id ) : null;
		self::log( array(
			'category'    => 'privacy',
			'action'      => 'personal_data_erased',
			'severity'    => 'warning',
			'object_type' => 'user_request',
			'object_id'   => $request_id,
			'object_name' => $request ? $request->email : '',
			'message'     => sprintf( 'Personal data erased for %s.', $request ? $request->email : '#' . $request_id ),
		) );
	}

	// ---------------------------------------------------------------
	// Plugin integrations
	// ---------------------------------------------------------------

	public function wc_order_status( $order_id, $from, $to, $order = null ) {
		self::log( array(
			'category'    => 'woocommerce',
			'action'      => 'order_status_changed',
			'severity'    => in_array( $to, array( 'refunded', 'cancelled', 'failed' ), true ) ? 'notice' : 'info',
			'object_type' => 'shop_order',
			'object_id'   => $order_id,
			'object_name' => $from . ' → ' . $to,
			'message'     => sprintf( 'Order #%s status changed from %s to %s.', $order && is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : $order_id, $from, $to ),
			'details'     => array( 'from' => $from, 'to' => $to, 'total' => $order && is_callable( array( $order, 'get_total' ) ) ? $order->get_total() : null ),
		) );
	}

	public function wc_order_refunded( $order_id, $refund_id ) {
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
		self::log( array(
			'category'    => 'woocommerce',
			'action'      => 'order_refunded',
			'severity'    => 'notice',
			'object_type' => 'shop_order',
			'object_id'   => $order_id,
			'object_name' => 'Order #' . $order_id,
			'message'     => sprintf( 'Order #%d refunded %s.', $order_id, $refund ? html_entity_decode( wp_strip_all_tags( wc_price( $refund->get_amount() ) ) ) : '' ),
			'details'     => array( 'refund_id' => $refund_id, 'amount' => $refund ? $refund->get_amount() : null, 'reason' => $refund ? $refund->get_reason() : null ),
		) );
	}

	public function wc_settings_saved() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		self::log( array(
			'category'    => 'setting',
			'action'      => 'woocommerce_settings_saved',
			'severity'    => 'notice',
			'object_type' => 'woocommerce_settings',
			'object_id'   => $tab,
			'object_name' => 'WooCommerce ' . $tab,
			'message'     => sprintf( 'WooCommerce settings saved (%s tab).', $tab ),
		) );
	}

	public function gf_form_saved( $form, $is_new ) {
		self::log( array(
			'category'    => 'form',
			'action'      => $is_new ? 'form_created' : 'form_updated',
			'object_type' => 'gravity_form',
			'object_id'   => rgar( $form, 'id' ),
			'object_name' => rgar( $form, 'title' ),
			'message'     => sprintf( 'Gravity Form "%s" %s.', rgar( $form, 'title' ), $is_new ? 'created' : 'updated' ),
			'dedupe'      => $is_new ? 0 : 2 * MINUTE_IN_SECONDS,
		) );
	}

	public function gf_form_deleted( $form_id ) {
		self::log( array( 'category' => 'form', 'action' => 'form_deleted', 'severity' => 'warning', 'object_type' => 'gravity_form', 'object_id' => $form_id, 'object_name' => 'Form #' . $form_id, 'message' => sprintf( 'Gravity Form #%d deleted (with its entries).', $form_id ) ) );
	}

	public function gf_entry_deleted( $entry_id ) {
		self::log( array( 'category' => 'form', 'action' => 'entry_deleted', 'severity' => 'notice', 'object_type' => 'gravity_form_entry', 'object_id' => $entry_id, 'object_name' => 'Entry #' . $entry_id, 'message' => sprintf( 'Gravity Forms entry #%d deleted.', $entry_id ) ) );
	}

	public function redirect_saved( $id, $redirect = null ) {
		$source = $redirect && is_callable( array( $redirect, 'get_url' ) ) ? $redirect->get_url() : '#' . $id;
		self::log( array(
			'category'    => 'redirect',
			'action'      => 'redirect_saved',
			'object_type' => 'redirect',
			'object_id'   => $id,
			'object_name' => $source,
			'message'     => sprintf( 'Redirect for %s created or updated.', $source ),
			'details'     => $redirect && is_callable( array( $redirect, 'to_json' ) ) ? array_intersect_key( $redirect->to_json(), array_flip( array( 'url', 'action_code', 'action_data', 'enabled' ) ) ) : array(),
			'dedupe'      => MINUTE_IN_SECONDS,
		) );
	}

	public function redirect_deleted( $redirect ) {
		$source = is_object( $redirect ) && is_callable( array( $redirect, 'get_url' ) ) ? $redirect->get_url() : '';
		self::log( array(
			'category'    => 'redirect',
			'action'      => 'redirect_deleted',
			'severity'    => 'notice',
			'object_type' => 'redirect',
			'object_id'   => is_object( $redirect ) && is_callable( array( $redirect, 'get_id' ) ) ? $redirect->get_id() : '',
			'object_name' => $source,
			'message'     => sprintf( 'Redirect for %s deleted.', $source ),
		) );
	}
}

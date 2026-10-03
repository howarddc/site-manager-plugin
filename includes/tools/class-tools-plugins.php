<?php
/**
 * MCP tools: plugins and WordPress updates.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Plugins {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'plugins', __( 'Plugins & updates', 'site-manager' ), __( 'Install, activate, deactivate, update and delete plugins; core, plugin and theme updates.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$plugin_arg = $s::str( 'Plugin file (e.g. "akismet/akismet.php") or folder slug ("akismet").' );

		$r->register( 'plugins_list', array(
			'category'     => 'plugins',
			'description'  => 'List installed plugins (including must-use and drop-ins) with version, active status, available updates and auto-update status.',
			'input_schema' => $s::obj( array(
				'status' => $s::enum( array( 'all', 'active', 'inactive', 'update_available', 'mustuse', 'dropins' ), '', 'all' ),
			) ),
			'handler'      => array( $this, 'plugins_list' ),
		) );

		$r->register( 'plugin_search', array(
			'category'     => 'plugins',
			'description'  => 'Search the WordPress.org plugin directory.',
			'open_world'   => true,
			'input_schema' => $s::obj( array(
				'search'   => $s::str(),
				'per_page' => $s::per_page( 10, 30 ),
			), array( 'search' ) ),
			'handler'      => array( $this, 'plugin_search' ),
		) );

		$r->register( 'plugin_activate', array(
			'category'     => 'plugins',
			'description'  => 'Activate one or more installed plugins.',
			'writes'       => true,
			'capability'   => 'activate_plugins',
			'input_schema' => $s::obj( array( 'plugins' => $s::arr( 'string', 'Plugin files or slugs.' ) ), array( 'plugins' ) ),
			'handler'      => array( $this, 'plugin_activate' ),
		) );

		$r->register( 'plugin_deactivate', array(
			'category'     => 'plugins',
			'description'  => 'Deactivate one or more plugins. Site Manager itself cannot be deactivated this way.',
			'writes'       => true,
			'destructive'  => true,
			'capability'   => 'activate_plugins',
			'input_schema' => $s::obj( array( 'plugins' => $s::arr( 'string', 'Plugin files or slugs.' ) ), array( 'plugins' ) ),
			'handler'      => array( $this, 'plugin_deactivate' ),
		) );

		$r->register( 'plugin_install', array(
			'category'     => 'plugins',
			'description'  => 'Install a plugin from WordPress.org by slug, or from a zip URL. Optionally activate it.',
			'writes'       => true,
			'open_world'   => true,
			'capability'   => 'install_plugins',
			'input_schema' => $s::obj( array(
				'slug'     => $s::str( 'WordPress.org plugin slug, e.g. "wordpress-seo".' ),
				'zip_url'  => $s::str( 'URL of a plugin zip (alternative to slug).' ),
				'activate' => $s::bool( '', false ),
			) ),
			'handler'      => array( $this, 'plugin_install' ),
		) );

		$r->register( 'plugin_update', array(
			'category'     => 'plugins',
			'description'  => 'Update one or more plugins to their latest versions.',
			'writes'       => true,
			'open_world'   => true,
			'capability'   => 'update_plugins',
			'input_schema' => $s::obj( array( 'plugins' => $s::arr( 'string', 'Plugin files or slugs.' ) ), array( 'plugins' ) ),
			'handler'      => array( $this, 'plugin_update' ),
		) );

		$r->register( 'plugin_delete', array(
			'category'     => 'plugins',
			'description'  => 'Delete plugins (deactivating them first). Runs their uninstall routines, which may remove their data.',
			'writes'       => true,
			'destructive'  => true,
			'capability'   => 'delete_plugins',
			'input_schema' => $s::obj( array( 'plugins' => $s::arr( 'string', 'Plugin files or slugs.' ) ), array( 'plugins' ) ),
			'handler'      => array( $this, 'plugin_delete' ),
		) );

		$r->register( 'plugin_auto_update_set', array(
			'category'     => 'plugins',
			'description'  => 'Enable or disable automatic updates for plugins.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'plugins' => $s::arr( 'string' ),
				'enabled' => $s::bool(),
			), array( 'plugins', 'enabled' ) ),
			'handler'      => array( $this, 'plugin_auto_update_set' ),
		) );

		$r->register( 'updates_check', array(
			'category'     => 'plugins',
			'description'  => 'Check WordPress.org for available core, plugin, theme and translation updates.',
			'open_world'   => true,
			'handler'      => array( $this, 'updates_check' ),
		) );

		$r->register( 'core_update', array(
			'category'     => 'plugins',
			'description'  => 'Update WordPress core to the latest offered version. Take a backup first and confirm with the user.',
			'writes'       => true,
			'destructive'  => true,
			'open_world'   => true,
			'capability'   => 'update_core',
			'input_schema' => $s::obj( array(
				'confirm' => $s::bool( 'Must be true.', false ),
			), array( 'confirm' ) ),
			'handler'      => array( $this, 'core_update' ),
		) );
	}

	private static function load() {
		Site_Manager_Helpers::load_admin_includes();
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * Resolve a plugin slug or file to its plugin file path.
	 */
	public static function resolve( $plugin ) {
		self::load();
		$plugin = trim( (string) $plugin );
		$all    = get_plugins();
		if ( isset( $all[ $plugin ] ) ) {
			return $plugin;
		}
		foreach ( array_keys( $all ) as $file ) {
			if ( strpos( $file, $plugin . '/' ) === 0 || $file === $plugin . '.php' ) {
				return $file;
			}
		}
		return new WP_Error( 'not_found', sprintf( 'Plugin "%s" is not installed.', $plugin ) );
	}

	/**
	 * Turn an upgrader result + skin into a WP_Error, or null on success.
	 */
	public static function upgrader_error( $result, $skin ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( $result === null || $result === false ) {
			global $wp_filesystem;
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
				return $wp_filesystem->errors;
			}
			return new WP_Error( 'upgrade_failed', 'The operation failed. WordPress may need filesystem credentials (FS_METHOD) to write files on this server.', array( 'details' => $skin->get_upgrade_messages() ) );
		}
		return null;
	}

	private static function is_self( $file ) {
		return $file === plugin_basename( SITE_MANAGER_FILE );
	}

	public function plugins_list( array $args ) {
		self::load();
		$status  = Site_Manager_Helpers::arg( $args, 'status', 'all' );
		$updates = get_site_transient( 'update_plugins' );
		$auto    = (array) get_site_option( 'auto_update_plugins', array() );
		$out     = array();

		if ( $status === 'mustuse' ) {
			foreach ( get_mu_plugins() as $file => $data ) {
				$out[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'] );
			}
			return $out;
		}
		if ( $status === 'dropins' ) {
			foreach ( get_dropins() as $file => $data ) {
				$out[] = array( 'file' => $file, 'name' => $data['Name'], 'description' => $data['Description'] );
			}
			return $out;
		}

		foreach ( get_plugins() as $file => $data ) {
			$active = is_plugin_active( $file );
			$update = isset( $updates->response[ $file ]->new_version ) ? $updates->response[ $file ]->new_version : null;
			if ( ( $status === 'active' && ! $active ) || ( $status === 'inactive' && $active ) || ( $status === 'update_available' && ! $update ) ) {
				continue;
			}
			$out[] = array(
				'file'        => $file,
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'active'      => $active,
				'network'     => is_multisite() && is_plugin_active_for_network( $file ),
				'update'      => $update,
				'auto_update' => in_array( $file, $auto, true ),
				'author'      => wp_strip_all_tags( $data['Author'] ),
				'description' => wp_strip_all_tags( $data['Description'] ),
			);
		}
		return $out;
	}

	public function plugin_search( array $args ) {
		self::load();
		$api = plugins_api( 'query_plugins', array(
			'search'   => (string) $args['search'],
			'per_page' => Site_Manager_Helpers::per_page( $args, 10, 30 ),
			'fields'   => array( 'short_description' => true, 'icons' => false, 'sections' => false ),
		) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		return array_map( function ( $p ) {
			$p = (array) $p;
			return array(
				'slug'            => $p['slug'],
				'name'            => wp_specialchars_decode( $p['name'] ),
				'version'         => $p['version'],
				'rating'          => $p['rating'],
				'active_installs' => $p['active_installs'],
				'last_updated'    => $p['last_updated'],
				'tested'          => $p['tested'],
				'description'     => $p['short_description'],
			);
		}, $api->plugins );
	}

	private function each_plugin( array $plugins, callable $fn ) {
		$results = array();
		foreach ( $plugins as $plugin ) {
			$file = self::resolve( $plugin );
			if ( is_wp_error( $file ) ) {
				$results[ $plugin ] = array( 'error' => $file->get_error_message() );
				continue;
			}
			$results[ $file ] = $fn( $file );
		}
		return $results;
	}

	public function plugin_activate( array $args ) {
		return $this->each_plugin( (array) $args['plugins'], function ( $file ) {
			$result = activate_plugin( $file );
			return is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'active' => true );
		} );
	}

	public function plugin_deactivate( array $args ) {
		return $this->each_plugin( (array) $args['plugins'], function ( $file ) {
			if ( self::is_self( $file ) ) {
				return array( 'error' => 'Site Manager cannot deactivate itself.' );
			}
			deactivate_plugins( $file );
			return array( 'active' => is_plugin_active( $file ) );
		} );
	}

	public function plugin_install( array $args ) {
		self::load();
		if ( ! empty( $args['slug'] ) ) {
			$api = plugins_api( 'plugin_information', array( 'slug' => sanitize_key( $args['slug'] ), 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
			$package = $api->download_link;
		} elseif ( ! empty( $args['zip_url'] ) ) {
			$package = esc_url_raw( (string) $args['zip_url'] );
		} else {
			return new WP_Error( 'missing_source', 'Pass slug or zip_url.' );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );
		$error    = self::upgrader_error( $result, $skin );
		if ( $error ) {
			return $error;
		}
		$file = $upgrader->plugin_info();
		$out  = array( 'installed' => $file );
		if ( $file && Site_Manager_Helpers::bool( $args, 'activate' ) ) {
			$activated     = activate_plugin( $file );
			$out['active'] = ! is_wp_error( $activated );
			if ( is_wp_error( $activated ) ) {
				$out['activation_error'] = $activated->get_error_message();
			}
		}
		return $out;
	}

	public function plugin_update( array $args ) {
		self::load();
		wp_update_plugins();
		return $this->each_plugin( (array) $args['plugins'], function ( $file ) {
			$was_active = is_plugin_active( $file );
			$skin       = new WP_Ajax_Upgrader_Skin();
			$upgrader   = new Plugin_Upgrader( $skin );
			$result     = $upgrader->upgrade( $file );
			$error      = self::upgrader_error( $result, $skin );
			if ( $error ) {
				return array( 'error' => $error->get_error_message() );
			}
			// The upgrader deactivates during the swap; make sure it's back on.
			if ( $was_active && ! is_plugin_active( $file ) ) {
				activate_plugin( $file );
			}
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			return array( 'version' => $data['Version'], 'active' => is_plugin_active( $file ) );
		} );
	}

	public function plugin_delete( array $args ) {
		self::load();
		$files   = array();
		$results = array();
		foreach ( (array) $args['plugins'] as $plugin ) {
			$file = self::resolve( $plugin );
			if ( is_wp_error( $file ) ) {
				$results[ $plugin ] = array( 'error' => $file->get_error_message() );
			} elseif ( self::is_self( $file ) ) {
				$results[ $file ] = array( 'error' => 'Site Manager cannot delete itself.' );
			} else {
				$files[] = $file;
			}
		}
		if ( $files ) {
			deactivate_plugins( $files, true );
			$deleted = delete_plugins( $files );
			foreach ( $files as $file ) {
				$results[ $file ] = is_wp_error( $deleted ) ? array( 'error' => $deleted->get_error_message() ) : array( 'deleted' => (bool) $deleted );
			}
		}
		return $results;
	}

	public function plugin_auto_update_set( array $args ) {
		$auto    = (array) get_site_option( 'auto_update_plugins', array() );
		$enabled = Site_Manager_Helpers::bool( $args, 'enabled' );
		$results = $this->each_plugin( (array) $args['plugins'], function ( $file ) use ( &$auto, $enabled ) {
			$auto = $enabled ? array_unique( array_merge( $auto, array( $file ) ) ) : array_values( array_diff( $auto, array( $file ) ) );
			return array( 'auto_update' => $enabled );
		} );
		update_site_option( 'auto_update_plugins', array_values( $auto ) );
		return $results;
	}

	public function updates_check() {
		self::load();
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();

		$core = array();
		foreach ( (array) get_core_updates() as $u ) {
			if ( isset( $u->response ) && $u->response === 'upgrade' ) {
				$core[] = array( 'version' => $u->current, 'locale' => $u->locale );
			}
		}
		$plugins = array();
		foreach ( get_plugin_updates() as $file => $data ) {
			$plugins[] = array( 'file' => $file, 'name' => $data->Name, 'current' => $data->Version, 'new' => $data->update->new_version );
		}
		$themes = array();
		foreach ( get_theme_updates() as $slug => $theme ) {
			$themes[] = array( 'stylesheet' => $slug, 'name' => $theme->get( 'Name' ), 'current' => $theme->get( 'Version' ), 'new' => $theme->update['new_version'] );
		}
		return array(
			'wordpress'    => array( 'current' => get_bloginfo( 'version' ), 'updates' => $core ),
			'plugins'      => $plugins,
			'themes'       => $themes,
			'translations' => count( wp_get_translation_updates() ),
		);
	}

	public function core_update( array $args ) {
		if ( ! Site_Manager_Helpers::bool( $args, 'confirm' ) ) {
			return new WP_Error( 'confirm_required', 'Pass confirm=true to update WordPress core.' );
		}
		self::load();
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check( array(), true );
		$update = get_preferred_from_update_core();
		if ( ! $update || ( isset( $update->response ) && $update->response !== 'upgrade' ) ) {
			return array( 'updated' => false, 'message' => 'WordPress is already up to date.', 'version' => get_bloginfo( 'version' ) );
		}
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'updated' => true, 'version' => $result );
	}
}

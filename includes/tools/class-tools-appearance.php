<?php
/**
 * MCP tools: themes, customizer settings (theme mods), additional CSS and
 * block-theme global styles.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Appearance {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'appearance', __( 'Appearance', 'site-manager' ), __( 'Themes, customizer settings, additional CSS, global styles.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'themes_list', array(
			'category'     => 'appearance',
			'description'  => 'List installed themes with version, parent theme, whether it\'s active, a block theme, and has an update available.',
			'handler'      => array( $this, 'themes_list' ),
		) );

		$r->register( 'theme_activate', array(
			'category'     => 'appearance',
			'description'  => 'Switch the active theme. Changes the whole front end — confirm with the user first.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'stylesheet' => $s::str( 'Theme directory slug.' ) ), array( 'stylesheet' ) ),
			'handler'      => array( $this, 'theme_activate' ),
		) );

		$r->register( 'theme_install', array(
			'category'     => 'appearance',
			'description'  => 'Install a theme from WordPress.org by slug, or from a zip URL. Optionally activate it.',
			'writes'       => true,
			'open_world'   => true,
			'capability'   => 'install_themes',
			'input_schema' => $s::obj( array(
				'slug'     => $s::str( 'WordPress.org theme slug.' ),
				'zip_url'  => $s::str( 'URL of a theme zip (alternative to slug).' ),
				'activate' => $s::bool( '', false ),
			) ),
			'handler'      => array( $this, 'theme_install' ),
		) );

		$r->register( 'theme_update', array(
			'category'     => 'appearance',
			'description'  => 'Update an installed theme to the latest available version.',
			'writes'       => true,
			'open_world'   => true,
			'capability'   => 'update_themes',
			'input_schema' => $s::obj( array( 'stylesheet' => $s::str() ), array( 'stylesheet' ) ),
			'handler'      => array( $this, 'theme_update' ),
		) );

		$r->register( 'theme_delete', array(
			'category'     => 'appearance',
			'description'  => 'Delete an installed (inactive) theme.',
			'writes'       => true,
			'destructive'  => true,
			'capability'   => 'delete_themes',
			'input_schema' => $s::obj( array( 'stylesheet' => $s::str() ), array( 'stylesheet' ) ),
			'handler'      => array( $this, 'theme_delete' ),
		) );

		$r->register( 'theme_mods_get', array(
			'category'     => 'appearance',
			'description'  => 'Read the active theme\'s customizer settings (theme mods): logo, header, colors, menu locations, theme-specific options.',
			'handler'      => array( $this, 'theme_mods_get' ),
		) );

		$r->register( 'theme_mods_update', array(
			'category'     => 'appearance',
			'description'  => 'Set customizer settings (theme mods) on the active theme: {"custom_logo": 12, "header_textcolor": "000000"}. null removes a mod.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'mods' => $s::map() ), array( 'mods' ) ),
			'handler'      => array( $this, 'theme_mods_update' ),
		) );

		$r->register( 'custom_css_get', array(
			'category'     => 'appearance',
			'description'  => 'Get the Additional CSS for the active theme.',
			'handler'      => array( $this, 'custom_css_get' ),
		) );

		$r->register( 'custom_css_update', array(
			'category'     => 'appearance',
			'description'  => 'Replace the Additional CSS for the active theme (or append with append=true).',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'css'    => $s::str(),
				'append' => $s::bool( '', false ),
			), array( 'css' ) ),
			'handler'      => array( $this, 'custom_css_update' ),
		) );

		$r->register( 'global_styles_get', array(
			'category'     => 'appearance',
			'description'  => 'Block themes: get the user global styles (Site Editor → Styles) and the merged theme.json settings for colors, typography and spacing.',
			'input_schema' => $s::obj( array(
				'include_merged' => $s::bool( 'Include the full merged theme.json data (large).', false ),
			) ),
			'handler'      => array( $this, 'global_styles_get' ),
		) );

		$r->register( 'global_styles_update', array(
			'category'     => 'appearance',
			'description'  => 'Block themes: replace the user global styles JSON ({"version": 3, "settings": {...}, "styles": {...}}). Get the current value first and modify it.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'styles' => $s::map( 'theme.json-shaped object.' ) ), array( 'styles' ) ),
			'handler'      => array( $this, 'global_styles_update' ),
		) );
	}

	public function themes_list() {
		$updates = get_site_transient( 'update_themes' );
		$active  = get_stylesheet();
		$out     = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'stylesheet'  => $slug,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'parent'      => $theme->parent() ? $theme->get_template() : null,
				'active'      => $slug === $active,
				'block_theme' => $theme->is_block_theme(),
				'update'      => isset( $updates->response[ $slug ]['new_version'] ) ? $updates->response[ $slug ]['new_version'] : null,
				'author'      => wp_strip_all_tags( $theme->get( 'Author' ) ),
			);
		}
		return $out;
	}

	public function theme_activate( array $args ) {
		$theme = wp_get_theme( (string) $args['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'not_found', 'Theme not installed.' );
		}
		if ( ! $theme->is_allowed() ) {
			return new WP_Error( 'not_allowed', 'Theme is not allowed on this site.' );
		}
		if ( $theme->errors() ) {
			return $theme->errors();
		}
		switch_theme( $theme->get_stylesheet() );
		return array( 'active' => get_stylesheet(), 'name' => wp_get_theme()->get( 'Name' ) );
	}

	public function theme_install( array $args ) {
		Site_Manager_Helpers::load_admin_includes();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( ! empty( $args['slug'] ) ) {
			$api = themes_api( 'theme_information', array( 'slug' => sanitize_key( $args['slug'] ), 'fields' => array( 'sections' => false ) ) );
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
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package );
		$error    = Site_Manager_Tools_Plugins::upgrader_error( $result, $skin );
		if ( $error ) {
			return $error;
		}
		$stylesheet = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : null;
		if ( $stylesheet && Site_Manager_Helpers::bool( $args, 'activate' ) ) {
			switch_theme( $stylesheet );
		}
		return array( 'installed' => $stylesheet, 'active' => get_stylesheet() );
	}

	public function theme_update( array $args ) {
		Site_Manager_Helpers::load_admin_includes();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_update_themes();
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( (string) $args['stylesheet'] );
		$error    = Site_Manager_Tools_Plugins::upgrader_error( $result, $skin );
		if ( $error ) {
			return $error;
		}
		return array( 'stylesheet' => $args['stylesheet'], 'version' => wp_get_theme( (string) $args['stylesheet'] )->get( 'Version' ), 'messages' => $skin->get_upgrade_messages() );
	}

	public function theme_delete( array $args ) {
		Site_Manager_Helpers::load_admin_includes();
		$slug = (string) $args['stylesheet'];
		if ( $slug === get_stylesheet() || $slug === get_template() ) {
			return new WP_Error( 'active_theme', 'Cannot delete the active theme or its parent.' );
		}
		$result = delete_theme( $slug );
		return is_wp_error( $result ) ? $result : array( 'deleted' => $slug );
	}

	public function theme_mods_get() {
		return array( 'theme' => get_stylesheet(), 'mods' => (object) get_theme_mods() );
	}

	public function theme_mods_update( array $args ) {
		foreach ( (array) $args['mods'] as $name => $value ) {
			if ( $value === null ) {
				remove_theme_mod( (string) $name );
			} else {
				set_theme_mod( (string) $name, $value );
			}
		}
		return $this->theme_mods_get();
	}

	public function custom_css_get() {
		return array( 'theme' => get_stylesheet(), 'css' => wp_get_custom_css() );
	}

	public function custom_css_update( array $args ) {
		$css = (string) $args['css'];
		if ( Site_Manager_Helpers::bool( $args, 'append' ) ) {
			$css = rtrim( wp_get_custom_css() ) . "\n\n" . $css;
		}
		$result = wp_update_custom_css_post( $css );
		return is_wp_error( $result ) ? $result : array( 'theme' => get_stylesheet(), 'length' => strlen( $css ) );
	}

	public function global_styles_get( array $args ) {
		if ( ! wp_is_block_theme() && ! wp_theme_has_theme_json() ) {
			return new WP_Error( 'not_supported', 'The active theme does not use theme.json global styles.' );
		}
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = get_post( $post_id );
		$out     = array(
			'post_id' => $post_id,
			'styles'  => $post ? json_decode( $post->post_content, true ) : null,
		);
		if ( Site_Manager_Helpers::bool( $args, 'include_merged' ) ) {
			$out['merged'] = WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data();
		}
		return $out;
	}

	public function global_styles_update( array $args ) {
		if ( ! wp_is_block_theme() && ! wp_theme_has_theme_json() ) {
			return new WP_Error( 'not_supported', 'The active theme does not use theme.json global styles.' );
		}
		$styles = (array) $args['styles'];
		$styles['isGlobalStylesUserThemeJSON'] = true;
		if ( empty( $styles['version'] ) ) {
			$styles['version'] = WP_Theme_JSON::LATEST_SCHEMA;
		}
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$result  = wp_update_post( wp_slash( array(
			'ID'           => $post_id,
			'post_content' => wp_json_encode( $styles ),
		) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		WP_Theme_JSON_Resolver::clean_cached_data();
		return array( 'post_id' => $post_id, 'updated' => true );
	}
}

<?php
/**
 * MCP tools: classic navigation menus, menu locations, and widget areas.
 *
 * Block themes store navigation as `wp_navigation` posts — those are
 * edited with the content tools.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Menus {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'menus', __( 'Menus & widgets', 'site-manager' ), __( 'Navigation menus, menu items, menu locations, sidebars and widgets.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$item_props = array(
			'title'       => $s::str( 'Label (defaults to the linked object\'s title).' ),
			'type'        => $s::enum( array( 'custom', 'post_type', 'taxonomy', 'post_type_archive' ), 'custom = arbitrary URL; post_type = link to a post/page; taxonomy = link to a term.' ),
			'object'      => $s::str( 'Post type or taxonomy slug, e.g. "page", "category".' ),
			'object_id'   => $s::int( 'Post or term ID being linked.' ),
			'url'         => $s::str( 'For type=custom.' ),
			'parent_id'   => $s::int( 'Parent menu item ID for nesting (0 = top level).' ),
			'position'    => $s::int( 'menu_order (1-based).' ),
			'target'      => $s::enum( array( '', '_blank' ) ),
			'classes'     => $s::str( 'Space-separated CSS classes.' ),
			'attr_title'  => $s::str(),
			'description' => $s::str(),
			'xfn'         => $s::str(),
		);

		$r->register( 'menus_list', array(
			'category'     => 'menus',
			'description'  => 'List classic navigation menus, registered theme menu locations, and which menu is assigned to each location. (Block themes also store navigation in wp_navigation posts — see post_list post_type=wp_navigation.)',
			'handler'      => array( $this, 'menus_list' ),
		) );

		$r->register( 'menu_get', array(
			'category'     => 'menus',
			'description'  => 'Get a menu and its items as a nested tree.',
			'input_schema' => $s::obj( array( 'menu' => $s::any( 'Menu ID, slug or name.' ) ), array( 'menu' ) ),
			'handler'      => array( $this, 'menu_get' ),
		) );

		$r->register( 'menu_create', array(
			'category'     => 'menus',
			'description'  => 'Create a menu, optionally assigning it to theme locations.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'name'      => $s::str(),
				'locations' => $s::arr( 'string', 'Theme location slugs to assign.' ),
			), array( 'name' ) ),
			'handler'      => array( $this, 'menu_create' ),
		) );

		$r->register( 'menu_update', array(
			'category'     => 'menus',
			'description'  => 'Rename a menu.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'menu' => $s::any( 'Menu ID, slug or name.' ),
				'name' => $s::str(),
			), array( 'menu', 'name' ) ),
			'handler'      => array( $this, 'menu_update' ),
		) );

		$r->register( 'menu_delete', array(
			'category'     => 'menus',
			'description'  => 'Delete a menu and all its items.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'menu' => $s::any( 'Menu ID, slug or name.' ) ), array( 'menu' ) ),
			'handler'      => array( $this, 'menu_delete' ),
		) );

		$r->register( 'menu_item_add', array(
			'category'     => 'menus',
			'description'  => 'Add an item to a menu: a page/post (type=post_type), a term (type=taxonomy), or a custom URL (type=custom).',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'menu' => $s::any( 'Menu ID, slug or name.' ) ), $item_props ), array( 'menu', 'type' ) ),
			'handler'      => array( $this, 'menu_item_add' ),
		) );

		$r->register( 'menu_item_update', array(
			'category'     => 'menus',
			'description'  => 'Update a menu item (label, link, parent, position, classes…). Only the fields you pass change.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'item_id' => $s::int() ), $item_props ), array( 'item_id' ) ),
			'handler'      => array( $this, 'menu_item_update' ),
		) );

		$r->register( 'menu_item_delete', array(
			'category'     => 'menus',
			'description'  => 'Remove an item from a menu.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'item_id' => $s::int() ), array( 'item_id' ) ),
			'handler'      => array( $this, 'menu_item_delete' ),
		) );

		$r->register( 'menu_locations_set', array(
			'category'     => 'menus',
			'description'  => 'Assign menus to theme locations: {"primary": 12, "footer": 0}. 0 unassigns.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'locations' => $s::map() ), array( 'locations' ) ),
			'handler'      => array( $this, 'menu_locations_set' ),
		) );

		$r->register( 'sidebars_list', array(
			'category'     => 'menus',
			'description'  => 'List registered widget areas (sidebars) and the widgets in each, with their settings. Edit widgets via rest_request on /wp/v2/widgets.',
			'handler'      => array( $this, 'sidebars_list' ),
		) );
	}

	private function resolve_menu( $menu ) {
		$obj = wp_get_nav_menu_object( is_numeric( $menu ) ? (int) $menu : (string) $menu );
		return $obj ? $obj : new WP_Error( 'not_found', 'Menu not found.' );
	}

	public function menus_list() {
		$locations = get_nav_menu_locations();
		$menus     = array();
		foreach ( wp_get_nav_menus() as $m ) {
			$menus[] = array(
				'id'        => (int) $m->term_id,
				'name'      => $m->name,
				'slug'      => $m->slug,
				'items'     => (int) $m->count,
				'locations' => array_keys( array_filter( $locations, function ( $id ) use ( $m ) {
					return (int) $id === (int) $m->term_id;
				} ) ),
			);
		}
		$registered = array();
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$registered[] = array(
				'location' => $slug,
				'label'    => $label,
				'menu_id'  => isset( $locations[ $slug ] ) ? (int) $locations[ $slug ] : 0,
			);
		}
		return array( 'menus' => $menus, 'locations' => $registered, 'block_theme' => wp_is_block_theme() );
	}

	private static function item_row( $item ) {
		return array(
			'item_id'     => (int) $item->ID,
			'title'       => $item->title,
			'url'         => $item->url,
			'type'        => $item->type,
			'object'      => $item->object,
			'object_id'   => (int) $item->object_id,
			'parent_id'   => (int) $item->menu_item_parent,
			'position'    => (int) $item->menu_order,
			'target'      => $item->target,
			'classes'     => trim( implode( ' ', array_filter( (array) $item->classes ) ) ),
			'attr_title'  => $item->attr_title,
			'description' => $item->description,
		);
	}

	public function menu_get( array $args ) {
		$menu = $this->resolve_menu( $args['menu'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
		$rows  = array();
		foreach ( (array) $items as $item ) {
			$rows[ (int) $item->ID ] = self::item_row( $item ) + array( 'children' => array() );
		}
		// Build the tree by reference so children of children attach correctly.
		$tree = array();
		foreach ( $rows as $id => &$row ) {
			if ( $row['parent_id'] && isset( $rows[ $row['parent_id'] ] ) ) {
				$rows[ $row['parent_id'] ]['children'][] = &$row;
			} else {
				$tree[] = &$row;
			}
		}
		unset( $row );
		return array( 'id' => (int) $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug, 'items' => $tree );
	}

	public function menu_create( array $args ) {
		$id = wp_create_nav_menu( (string) $args['name'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! empty( $args['locations'] ) ) {
			$locations = get_nav_menu_locations();
			foreach ( (array) $args['locations'] as $loc ) {
				$locations[ (string) $loc ] = $id;
			}
			set_theme_mod( 'nav_menu_locations', $locations );
		}
		return $this->menu_get( array( 'menu' => $id ) );
	}

	public function menu_update( array $args ) {
		$menu = $this->resolve_menu( $args['menu'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$result = wp_update_nav_menu_object( $menu->term_id, array( 'menu-name' => (string) $args['name'] ) );
		return is_wp_error( $result ) ? $result : array( 'id' => (int) $menu->term_id, 'name' => (string) $args['name'] );
	}

	public function menu_delete( array $args ) {
		$menu = $this->resolve_menu( $args['menu'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$result = wp_delete_nav_menu( $menu->term_id );
		return is_wp_error( $result ) ? $result : array( 'deleted' => array( 'id' => (int) $menu->term_id, 'name' => $menu->name ) );
	}

	private function item_data( array $args, array $base = array() ) {
		$map = array(
			'title'       => 'menu-item-title',
			'type'        => 'menu-item-type',
			'object'      => 'menu-item-object',
			'object_id'   => 'menu-item-object-id',
			'url'         => 'menu-item-url',
			'parent_id'   => 'menu-item-parent-id',
			'position'    => 'menu-item-position',
			'target'      => 'menu-item-target',
			'classes'     => 'menu-item-classes',
			'attr_title'  => 'menu-item-attr-title',
			'description' => 'menu-item-description',
			'xfn'         => 'menu-item-xfn',
		);
		$data = $base;
		foreach ( $map as $arg => $key ) {
			if ( isset( $args[ $arg ] ) ) {
				$data[ $key ] = $args[ $arg ];
			}
		}
		$data['menu-item-status'] = 'publish';
		if ( isset( $data['menu-item-type'] ) && $data['menu-item-type'] === 'post_type' && empty( $data['menu-item-object'] ) && ! empty( $data['menu-item-object-id'] ) ) {
			$data['menu-item-object'] = get_post_type( (int) $data['menu-item-object-id'] );
		}
		if ( isset( $data['menu-item-type'] ) && $data['menu-item-type'] === 'taxonomy' && empty( $data['menu-item-object'] ) && ! empty( $data['menu-item-object-id'] ) ) {
			$term                     = get_term( (int) $data['menu-item-object-id'] );
			$data['menu-item-object'] = $term && ! is_wp_error( $term ) ? $term->taxonomy : '';
		}
		return $data;
	}

	public function menu_item_add( array $args ) {
		$menu = $this->resolve_menu( $args['menu'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$id = wp_update_nav_menu_item( $menu->term_id, 0, $this->item_data( $args ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::item_row( wp_setup_nav_menu_item( get_post( $id ) ) );
	}

	public function menu_item_update( array $args ) {
		$post = Site_Manager_Helpers::require_post( (int) $args['item_id'], 'nav_menu_item' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$item  = wp_setup_nav_menu_item( $post );
		$menus = wp_get_object_terms( $post->ID, 'nav_menu', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $menus ) || ! $menus ) {
			return new WP_Error( 'orphan', 'Menu item is not attached to a menu.' );
		}
		// wp_update_nav_menu_item resets unspecified fields, so start from the current values.
		$base = $this->item_data( self::item_row( $item ) + array( 'xfn' => $item->xfn ) );
		$id   = wp_update_nav_menu_item( (int) $menus[0], $post->ID, $this->item_data( $args, $base ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::item_row( wp_setup_nav_menu_item( get_post( $id ) ) );
	}

	public function menu_item_delete( array $args ) {
		$post = Site_Manager_Helpers::require_post( (int) $args['item_id'], 'nav_menu_item' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return wp_delete_post( $post->ID, true ) ? array( 'deleted' => $post->ID ) : new WP_Error( 'delete_failed', 'Menu item could not be deleted.' );
	}

	public function menu_locations_set( array $args ) {
		$registered = get_registered_nav_menus();
		$locations  = get_nav_menu_locations();
		$errors     = array();
		foreach ( (array) $args['locations'] as $loc => $menu_id ) {
			if ( ! isset( $registered[ $loc ] ) ) {
				$errors[ $loc ] = 'Not a registered location.';
				continue;
			}
			$locations[ $loc ] = (int) $menu_id;
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		return array( 'locations' => get_nav_menu_locations(), 'errors' => (object) $errors );
	}

	public function sidebars_list() {
		global $wp_registered_sidebars, $wp_registered_widgets;
		$assigned = wp_get_sidebars_widgets();
		$out      = array();
		$areas    = $wp_registered_sidebars + array( 'wp_inactive_widgets' => array( 'name' => 'Inactive widgets', 'description' => '' ) );
		foreach ( $areas as $id => $sidebar ) {
			$widgets = array();
			foreach ( isset( $assigned[ $id ] ) ? (array) $assigned[ $id ] : array() as $widget_id ) {
				$row = array( 'widget_id' => $widget_id );
				if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
					$instances       = get_option( 'widget_' . $m[1], array() );
					$row['id_base']  = $m[1];
					$row['settings'] = isset( $instances[ (int) $m[2] ] ) ? $instances[ (int) $m[2] ] : null;
				}
				$row['name'] = isset( $wp_registered_widgets[ $widget_id ]['name'] ) ? $wp_registered_widgets[ $widget_id ]['name'] : null;
				$widgets[]   = $row;
			}
			$out[] = array(
				'id'          => $id,
				'name'        => $sidebar['name'],
				'description' => isset( $sidebar['description'] ) ? $sidebar['description'] : '',
				'widgets'     => $widgets,
			);
		}
		return $out;
	}
}

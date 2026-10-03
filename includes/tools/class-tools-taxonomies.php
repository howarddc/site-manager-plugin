<?php
/**
 * MCP tools: taxonomies and terms (categories, tags, custom taxonomies),
 * term meta, and post ↔ term assignment.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Taxonomies {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'taxonomies', __( 'Taxonomies', 'site-manager' ), __( 'Categories, tags, custom taxonomies, term meta.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'taxonomies_list', array(
			'category'     => 'taxonomies',
			'description'  => 'List registered taxonomies (built-in and custom) with the post types they apply to, hierarchy and term counts.',
			'input_schema' => $s::obj( array(
				'include_internal' => $s::bool( 'Include taxonomies without an admin UI.', false ),
			) ),
			'handler'      => array( $this, 'taxonomies_list' ),
		) );

		$r->register( 'terms_list', array(
			'category'     => 'taxonomies',
			'description'  => 'List terms in a taxonomy, with optional search, parent filter and pagination.',
			'input_schema' => $s::obj( array(
				'taxonomy'   => $s::str(),
				'search'     => $s::str(),
				'parent'     => $s::int( 'Only direct children of this term (0 = top level).' ),
				'hide_empty' => $s::bool( '', false ),
				'orderby'    => $s::str( 'name, slug, count, term_id, term_order…', array( 'default' => 'name' ) ),
				'order'      => $s::enum( array( 'ASC', 'DESC' ), '', 'ASC' ),
				'page'       => $s::page(),
				'per_page'   => $s::per_page( 100, 500 ),
			), array( 'taxonomy' ) ),
			'handler'      => array( $this, 'terms_list' ),
		) );

		$r->register( 'term_get', array(
			'category'     => 'taxonomies',
			'description'  => 'Get a term with its meta (and ACF / Yoast values when those plugins are active). Look up by id, or by taxonomy + slug.',
			'input_schema' => $s::obj( array(
				'id'       => $s::int(),
				'taxonomy' => $s::str(),
				'slug'     => $s::str(),
			) ),
			'handler'      => array( $this, 'term_get' ),
		) );

		$term_props = array(
			'name'        => $s::str(),
			'slug'        => $s::str(),
			'description' => $s::str(),
			'parent'      => $s::int(),
			'meta'        => $s::map( 'Term meta {key: value}; null deletes.' ),
			'acf'         => $s::map( 'ACF field values for the term. Requires ACF.' ),
			'yoast'       => $s::map( 'Yoast term SEO fields: title, description, focus_keyphrase, canonical, noindex (default|index|noindex), og_title, og_description, og_image, twitter_title, twitter_description, twitter_image. Requires Yoast SEO.' ),
		);

		$r->register( 'term_create', array(
			'category'     => 'taxonomies',
			'description'  => 'Create a term in any taxonomy.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'taxonomy' => $s::str() ), $term_props ), array( 'taxonomy', 'name' ) ),
			'handler'      => array( $this, 'term_create' ),
		) );

		$r->register( 'term_update', array(
			'category'     => 'taxonomies',
			'description'  => 'Update a term\'s name, slug, description, parent, meta, ACF or Yoast fields.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'id' => $s::int() ), $term_props ), array( 'id' ) ),
			'handler'      => array( $this, 'term_update' ),
		) );

		$r->register( 'term_delete', array(
			'category'     => 'taxonomies',
			'description'  => 'Delete a term. Posts keep existing; they just lose the term.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'      => $s::int(),
				'dry_run' => $s::dry_run(),
			), array( 'id' ) ),
			'handler'      => array( $this, 'term_delete' ),
		) );

		$r->register( 'post_terms_set', array(
			'category'     => 'taxonomies',
			'description'  => 'Set (or append / remove) a post\'s terms in one taxonomy. Terms can be IDs or names; unknown names are created when adding.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'post_id'  => $s::int(),
				'taxonomy' => $s::str(),
				'terms'    => $s::arr( array( 'type' => array( 'integer', 'string' ) ) ),
				'mode'     => $s::enum( array( 'replace', 'append', 'remove' ), '', 'replace' ),
			), array( 'post_id', 'taxonomy', 'terms' ) ),
			'handler'      => array( $this, 'post_terms_set' ),
		) );
	}

	public function taxonomies_list( array $args ) {
		$internal = Site_Manager_Helpers::bool( $args, 'include_internal' );
		$out      = array();
		foreach ( get_taxonomies( $internal ? array() : array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$out[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
				'public'       => (bool) $tax->public,
				'builtin'      => (bool) $tax->_builtin,
				'object_types' => $tax->object_type,
				'term_count'   => (int) wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) ),
			);
		}
		return $out;
	}

	public function terms_list( array $args ) {
		$taxonomy = sanitize_key( (string) Site_Manager_Helpers::arg( $args, 'taxonomy', '' ) );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) );
		}
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 100, 500 );
		$q        = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => Site_Manager_Helpers::bool( $args, 'hide_empty' ),
			'orderby'    => Site_Manager_Helpers::arg( $args, 'orderby', 'name' ),
			'order'      => Site_Manager_Helpers::arg( $args, 'order', 'ASC' ),
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
		);
		if ( ! empty( $args['search'] ) ) {
			$q['search'] = (string) $args['search'];
		}
		if ( isset( $args['parent'] ) ) {
			$q['parent'] = (int) $args['parent'];
		}
		$terms = get_terms( $q );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$count_q = $q;
		unset( $count_q['number'], $count_q['offset'] );
		$total = wp_count_terms( $count_q );

		$items = array_map( function ( $t ) {
			return Site_Manager_Helpers::term_detail( $t, false );
		}, $terms );
		return Site_Manager_Helpers::paged( $items, is_wp_error( $total ) ? count( $items ) : (int) $total, $page, $per_page );
	}

	private function resolve_term( array $args ) {
		if ( ! empty( $args['id'] ) ) {
			$term = get_term( (int) $args['id'] );
		} elseif ( ! empty( $args['taxonomy'] ) && ! empty( $args['slug'] ) ) {
			$term = get_term_by( 'slug', (string) $args['slug'], (string) $args['taxonomy'] );
		} else {
			return new WP_Error( 'missing_id', 'Pass id, or taxonomy + slug.' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}
		return $term;
	}

	public function term_get( array $args ) {
		$term = $this->resolve_term( $args );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$out = Site_Manager_Helpers::term_detail( $term );
		if ( class_exists( 'Site_Manager_Tools_ACF' ) && Site_Manager_Tools_ACF::is_active() ) {
			$out['acf'] = Site_Manager_Tools_ACF::values_for( 'term_' . $term->term_id );
		}
		if ( class_exists( 'Site_Manager_Tools_Yoast' ) && Site_Manager_Tools_Yoast::is_active() ) {
			$out['yoast'] = Site_Manager_Tools_Yoast::term_values( $term );
		}
		return $out;
	}

	private function apply_term_extras( WP_Term $term, array $args ) {
		$report = array();
		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			$report['meta'] = Site_Manager_Helpers::apply_meta( 'term', $term->term_id, $args['meta'] );
		}
		if ( ! empty( $args['acf'] ) && is_array( $args['acf'] ) && class_exists( 'Site_Manager_Tools_ACF' ) && Site_Manager_Tools_ACF::is_active() ) {
			$report['acf'] = Site_Manager_Tools_ACF::update_values( 'term_' . $term->term_id, $args['acf'] );
		}
		if ( ! empty( $args['yoast'] ) && is_array( $args['yoast'] ) && class_exists( 'Site_Manager_Tools_Yoast' ) && Site_Manager_Tools_Yoast::is_active() ) {
			$report['yoast'] = Site_Manager_Tools_Yoast::update_term_values( $term, $args['yoast'] );
		}
		return $report;
	}

	public function term_create( array $args ) {
		$taxonomy = sanitize_key( (string) $args['taxonomy'] );
		$result   = wp_insert_term( wp_slash( (string) $args['name'] ), $taxonomy, wp_slash( array_filter( array(
			'slug'        => Site_Manager_Helpers::arg( $args, 'slug' ),
			'description' => Site_Manager_Helpers::arg( $args, 'description' ),
			'parent'      => (int) Site_Manager_Helpers::arg( $args, 'parent', 0 ),
		), function ( $v ) {
			return $v !== null;
		} ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term = get_term( $result['term_id'], $taxonomy );
		return array( 'term' => Site_Manager_Helpers::term_detail( $term ), 'applied' => $this->apply_term_extras( $term, $args ) );
	}

	public function term_update( array $args ) {
		$term = $this->resolve_term( $args );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$fields = array_intersect_key( $args, array_flip( array( 'name', 'slug', 'description', 'parent' ) ) );
		if ( $fields ) {
			$result = wp_update_term( $term->term_id, $term->taxonomy, wp_slash( $fields ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$term = get_term( $term->term_id, $term->taxonomy );
		}
		return array( 'term' => Site_Manager_Helpers::term_detail( $term ), 'applied' => $this->apply_term_extras( $term, $args ) );
	}

	public function term_delete( array $args ) {
		$term = $this->resolve_term( $args );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$summary = Site_Manager_Helpers::term_detail( $term, false );
		if ( Site_Manager_Helpers::bool( $args, 'dry_run' ) ) {
			return array( 'dry_run' => true, 'would_delete' => $summary );
		}
		$result = wp_delete_term( $term->term_id, $term->taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'delete_failed', 'Term could not be deleted (it may be the default term for its taxonomy).' );
		}
		return array( 'deleted' => $summary );
	}

	public function post_terms_set( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'post_id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$taxonomy = sanitize_key( (string) $args['taxonomy'] );
		$terms    = Site_Manager_Helpers::normalize_terms( (array) $args['terms'] );
		$mode     = Site_Manager_Helpers::arg( $args, 'mode', 'replace' );

		$result = $mode === 'remove'
			? wp_remove_object_terms( $post->ID, $terms, $taxonomy )
			: wp_set_object_terms( $post->ID, $terms, $taxonomy, $mode === 'append' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$current = Site_Manager_Helpers::post_terms( $post->ID );
		return array( 'post_id' => $post->ID, 'taxonomy' => $taxonomy, 'terms' => isset( $current->$taxonomy ) ? $current->$taxonomy : array() );
	}
}

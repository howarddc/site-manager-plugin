<?php
/**
 * MCP tools: content of every post type (posts, pages, custom post types,
 * reusable blocks, templates, navigation), post meta, revisions and blocks.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Content {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'content', __( 'Content', 'site-manager' ), __( 'Posts, pages and custom post types; custom fields (post meta); revisions; blocks.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$write_props = array(
			'title'             => $s::str(),
			'content'           => $s::str( 'HTML or Gutenberg block markup.' ),
			'excerpt'           => $s::str(),
			'status'            => $s::str( 'publish, draft, pending, private, future, or any registered status.' ),
			'slug'              => $s::str(),
			'date'              => $s::str( 'Local site time, "YYYY-MM-DD HH:MM:SS". Combine with status=future to schedule.' ),
			'author'            => $s::int( 'Author user ID.' ),
			'parent'            => $s::int( 'Parent post ID (hierarchical types).' ),
			'menu_order'        => $s::int(),
			'comment_status'    => $s::enum( array( 'open', 'closed' ) ),
			'ping_status'       => $s::enum( array( 'open', 'closed' ) ),
			'password'          => $s::str( 'Post password; empty string removes it.' ),
			'template'          => $s::str( 'Page template file or block template slug; empty string for default.' ),
			'featured_image_id' => $s::int( 'Attachment ID for the featured image; 0 removes it.' ),
			'terms'             => $s::map( 'Taxonomy terms: {"category": [3, "News"], "post_tag": ["ai"]}. IDs or names/slugs; unknown names are created. Replaces existing terms unless terms_append is true.' ),
			'terms_append'      => $s::bool( 'Add terms instead of replacing.', false ),
			'meta'              => $s::map( 'Custom fields / post meta: {"key": value}. Arrays/objects are stored serialized. null deletes the key.' ),
			'acf'               => $s::map( 'ACF field values by field name or key: {"subtitle": "…", "gallery": [12, 13]}. Requires ACF.' ),
			'yoast'             => $s::map( 'Yoast SEO fields: title, description, focus_keyphrase, canonical, noindex, nofollow, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, cornerstone, primary_terms {taxonomy: term_id}. Requires Yoast SEO.' ),
		);

		$r->register( 'post_types_list', array(
			'category'     => 'content',
			'description'  => 'List registered post types (built-in and custom) with labels, supported features, taxonomies, hierarchy and post counts.',
			'input_schema' => $s::obj( array(
				'include_internal' => $s::bool( 'Include non-public internal types (revisions, templates, global styles, etc).', false ),
			) ),
			'handler'      => array( $this, 'post_types_list' ),
		) );

		$r->register( 'post_list', array(
			'category'     => 'content',
			'description'  => 'Query posts of any post type with filters for status, search, author, parent, taxonomy terms, meta values and dates. Returns summaries; use post_get for full details.',
			'input_schema' => $s::obj( array(
				'post_type'  => $s::any( 'Post type slug or array of slugs. Default "any" (all public types).' ),
				'status'     => $s::any( 'Status or array of statuses. Default "any" (excludes trash).' ),
				'search'     => $s::str( 'Keyword search over title, excerpt and content.' ),
				'author'     => $s::int(),
				'parent'     => $s::int( 'Parent post ID; 0 for top-level only.' ),
				'include'    => $s::arr( 'integer', 'Only these IDs.' ),
				'slug'       => $s::str( 'Exact slug match.' ),
				'tax_query'  => $s::arr( 'object', 'WP_Query tax_query clauses: [{"taxonomy":"category","terms":[3],"field":"term_id","operator":"IN"}].' ),
				'meta_query' => $s::arr( 'object', 'WP_Query meta_query clauses: [{"key":"price","value":10,"compare":">","type":"NUMERIC"}].' ),
				'after'      => $s::str( 'Published on/after this date (any strtotime format).' ),
				'before'     => $s::str( 'Published before this date.' ),
				'orderby'    => $s::str( 'date, modified, title, menu_order, ID, rand, meta_value, meta_value_num, …', array( 'default' => 'date' ) ),
				'order'      => $s::enum( array( 'ASC', 'DESC' ), '', 'DESC' ),
				'meta_key'   => $s::str( 'Meta key for orderby=meta_value / meta_value_num.' ),
				'page'       => $s::page(),
				'per_page'   => $s::per_page( 20, 100 ),
			) ),
			'handler'      => array( $this, 'post_list' ),
		) );

		$r->register( 'post_get', array(
			'category'     => 'content',
			'description'  => 'Get one post of any type with content, terms, featured image, custom fields (meta), ACF values and Yoast SEO data. Look up by id, by url, or by post_type + slug.',
			'input_schema' => $s::obj( array(
				'id'                   => $s::int(),
				'url'                  => $s::str( 'A front-end URL on this site.' ),
				'post_type'            => $s::str( 'With slug.' ),
				'slug'                 => $s::str(),
				'include_content'      => $s::bool( '', true ),
				'include_meta'         => $s::bool( '', true ),
				'include_private_meta' => $s::bool( 'Include underscore-prefixed (protected) meta keys.', false ),
			) ),
			'handler'      => array( $this, 'post_get' ),
		) );

		$r->register( 'post_create', array(
			'category'     => 'content',
			'description'  => 'Create a post of any type (post, page, or custom post type) and in the same call set its terms, custom fields, ACF fields, Yoast SEO fields, featured image and template. Defaults to status=draft.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array(
				'post_type' => $s::str( 'Default "post".', array( 'default' => 'post' ) ),
			), $write_props ), array( 'title' ) ),
			'handler'      => array( $this, 'post_create' ),
		) );

		$r->register( 'post_update', array(
			'category'     => 'content',
			'description'  => 'Update any post. Only the fields you pass are changed. Can also set terms, custom fields, ACF fields, Yoast SEO fields, featured image and template.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'id' => $s::int() ), $write_props ), array( 'id' ) ),
			'handler'      => array( $this, 'post_update' ),
		) );

		$r->register( 'post_delete', array(
			'category'     => 'content',
			'description'  => 'Move a post to the trash, or permanently delete it with force=true.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'      => $s::int(),
				'force'   => $s::bool( 'Skip the trash and delete permanently.', false ),
				'dry_run' => $s::dry_run(),
			), array( 'id' ) ),
			'handler'      => array( $this, 'post_delete' ),
		) );

		$r->register( 'post_restore', array(
			'category'     => 'content',
			'description'  => 'Restore a trashed post.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'post_restore' ),
		) );

		$r->register( 'post_duplicate', array(
			'category'     => 'content',
			'description'  => 'Duplicate a post (content, terms, meta, featured image) as a new draft.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'     => $s::int(),
				'title'  => $s::str( 'Title for the copy (default: original title + " (copy)").' ),
				'status' => $s::str( '', array( 'default' => 'draft' ) ),
			), array( 'id' ) ),
			'handler'      => array( $this, 'post_duplicate' ),
		) );

		$r->register( 'posts_bulk_update', array(
			'category'     => 'content',
			'description'  => 'Apply the same change to many posts: status, author, parent, comment_status, terms (replace or append), or meta.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'ids'            => $s::arr( 'integer' ),
				'status'         => $s::str(),
				'author'         => $s::int(),
				'parent'         => $s::int(),
				'comment_status' => $s::enum( array( 'open', 'closed' ) ),
				'terms'          => $write_props['terms'],
				'terms_append'   => $write_props['terms_append'],
				'meta'           => $write_props['meta'],
				'dry_run'        => $s::dry_run(),
			), array( 'ids' ) ),
			'handler'      => array( $this, 'posts_bulk_update' ),
		) );

		$r->register( 'content_search_replace', array(
			'category'     => 'content',
			'description'  => 'Find and replace text in post titles, content and excerpts across post types. Runs as a dry run by default and reports matches; pass dry_run=false to apply. Does not touch meta or options (use db tools for serialized data).',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'search'    => $s::str( 'Text (or regex pattern when regex=true) to find.' ),
				'replace'   => $s::str(),
				'regex'     => $s::bool( 'Treat search as a PCRE pattern including delimiters, e.g. "/foo(\\d+)/i".', false ),
				'post_type' => $s::any( 'Post type or array. Default: all public types.' ),
				'fields'    => $s::arr( array( 'type' => 'string', 'enum' => array( 'title', 'content', 'excerpt' ) ), 'Default ["content"].' ),
				'status'    => $s::any( 'Default "any".' ),
				'limit'     => $s::int( 'Max posts to change.', array( 'default' => 200 ) ),
				'dry_run'   => $s::bool( '', true ),
			), array( 'search', 'replace' ) ),
			'handler'      => array( $this, 'content_search_replace' ),
		) );

		$r->register( 'post_meta_get', array(
			'category'     => 'content',
			'description'  => 'Read custom fields (post meta) for a post — all keys, or one key. Includes protected underscore keys when asked.',
			'input_schema' => $s::obj( array(
				'id'              => $s::int(),
				'key'             => $s::str( 'Single key; omit for all.' ),
				'include_private' => $s::bool( '', false ),
			), array( 'id' ) ),
			'handler'      => array( $this, 'post_meta_get' ),
		) );

		$r->register( 'post_meta_update', array(
			'category'     => 'content',
			'description'  => 'Set custom fields (post meta) on a post. Pass a {key: value} map; null deletes a key. For multi-value keys use add=true to add a value instead of replacing.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'   => $s::int(),
				'meta' => $s::map(),
				'add'  => $s::bool( 'Add values as additional rows instead of replacing.', false ),
			), array( 'id', 'meta' ) ),
			'handler'      => array( $this, 'post_meta_update' ),
		) );

		$r->register( 'post_meta_delete', array(
			'category'     => 'content',
			'description'  => 'Delete a meta key from a post (all values, or only rows matching value).',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'    => $s::int(),
				'key'   => $s::str(),
				'value' => $s::any( 'Only delete rows with this value.' ),
			), array( 'id', 'key' ) ),
			'handler'      => array( $this, 'post_meta_delete' ),
		) );

		$r->register( 'meta_keys_list', array(
			'category'     => 'content',
			'description'  => 'Discover custom fields: distinct meta keys in use for a post type (with usage counts and a sample value), plus keys formally registered with register_meta().',
			'input_schema' => $s::obj( array(
				'post_type'       => $s::str( 'Default "post".' ),
				'include_private' => $s::bool( '', false ),
				'search'          => $s::str( 'Substring filter on key name.' ),
			) ),
			'handler'      => array( $this, 'meta_keys_list' ),
		) );

		$r->register( 'revisions_list', array(
			'category'     => 'content',
			'description'  => 'List revisions of a post (newest first).',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'revisions_list' ),
		) );

		$r->register( 'revision_get', array(
			'category'     => 'content',
			'description'  => 'Get a revision\'s full title, content and excerpt.',
			'input_schema' => $s::obj( array( 'revision_id' => $s::int() ), array( 'revision_id' ) ),
			'handler'      => array( $this, 'revision_get' ),
		) );

		$r->register( 'revision_restore', array(
			'category'     => 'content',
			'description'  => 'Restore a post to a previous revision.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'revision_id' => $s::int() ), array( 'revision_id' ) ),
			'handler'      => array( $this, 'revision_restore' ),
		) );

		$r->register( 'post_blocks_get', array(
			'category'     => 'content',
			'description'  => 'Parse a post\'s content into a Gutenberg block tree (blockName, attrs, innerHTML, innerBlocks) — useful for precise edits.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'post_blocks_get' ),
		) );

		$r->register( 'post_blocks_update', array(
			'category'     => 'content',
			'description'  => 'Replace a post\'s content with a block tree (same shape post_blocks_get returns). Serialized with WordPress\'s own serializer.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'     => $s::int(),
				'blocks' => $s::arr( 'object' ),
			), array( 'id', 'blocks' ) ),
			'handler'      => array( $this, 'post_blocks_update' ),
		) );

		$r->register( 'block_types_list', array(
			'category'     => 'content',
			'description'  => 'List registered block types (core and plugin blocks) with title, category and optionally their attribute schemas.',
			'input_schema' => $s::obj( array(
				'search'             => $s::str( 'Filter by name or title.' ),
				'include_attributes' => $s::bool( '', false ),
			) ),
			'handler'      => array( $this, 'block_types_list' ),
		) );

		$r->register( 'block_patterns_list', array(
			'category'     => 'content',
			'description'  => 'List registered block patterns. Pass name to get one pattern\'s content.',
			'input_schema' => $s::obj( array(
				'name'   => $s::str(),
				'search' => $s::str(),
			) ),
			'handler'      => array( $this, 'block_patterns_list' ),
		) );
	}

	// ---------------------------------------------------------------

	public function post_types_list( array $args ) {
		$internal = Site_Manager_Helpers::bool( $args, 'include_internal' );
		$types    = get_post_types( $internal ? array() : array( 'show_ui' => true ), 'objects' );
		$out      = array();
		foreach ( $types as $pt ) {
			$counts = wp_count_posts( $pt->name );
			$out[]  = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'singular'     => $pt->labels->singular_name,
				'public'       => (bool) $pt->public,
				'hierarchical' => (bool) $pt->hierarchical,
				'builtin'      => (bool) $pt->_builtin,
				'supports'     => array_keys( get_all_post_type_supports( $pt->name ) ),
				'taxonomies'   => get_object_taxonomies( $pt->name ),
				'rest_base'    => $pt->show_in_rest ? ( $pt->rest_base ?: $pt->name ) : null,
				'has_archive'  => $pt->has_archive,
				'counts'       => array_filter( (array) $counts ),
			);
		}
		return $out;
	}

	private static function post_type_arg( $value, $default = 'any' ) {
		if ( $value === null || $value === '' ) {
			return $default;
		}
		return is_array( $value ) ? array_map( 'sanitize_key', $value ) : sanitize_key( $value );
	}

	public function post_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args );
		$q        = array(
			'post_type'           => self::post_type_arg( Site_Manager_Helpers::arg( $args, 'post_type' ) ),
			'post_status'         => Site_Manager_Helpers::arg( $args, 'status', 'any' ),
			'paged'               => $page,
			'posts_per_page'      => $per_page,
			'orderby'             => Site_Manager_Helpers::arg( $args, 'orderby', 'date' ),
			'order'               => strtoupper( Site_Manager_Helpers::arg( $args, 'order', 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
		);
		if ( ! empty( $args['search'] ) ) {
			$q['s'] = (string) $args['search'];
		}
		if ( ! empty( $args['author'] ) ) {
			$q['author'] = (int) $args['author'];
		}
		if ( isset( $args['parent'] ) ) {
			$q['post_parent'] = (int) $args['parent'];
		}
		if ( ! empty( $args['include'] ) ) {
			$q['post__in'] = array_map( 'intval', (array) $args['include'] );
		}
		if ( ! empty( $args['slug'] ) ) {
			$q['name'] = sanitize_title( $args['slug'] );
		}
		if ( ! empty( $args['tax_query'] ) ) {
			$q['tax_query'] = (array) $args['tax_query'];
		}
		if ( ! empty( $args['meta_query'] ) ) {
			$q['meta_query'] = (array) $args['meta_query'];
		}
		if ( ! empty( $args['meta_key'] ) ) {
			$q['meta_key'] = (string) $args['meta_key'];
		}
		$date = array();
		if ( ! empty( $args['after'] ) ) {
			$date['after'] = (string) $args['after'];
		}
		if ( ! empty( $args['before'] ) ) {
			$date['before'] = (string) $args['before'];
		}
		if ( $date ) {
			$date['inclusive']  = true;
			$q['date_query']    = array( $date );
		}

		$query = new WP_Query( $q );
		return Site_Manager_Helpers::paged(
			array_map( array( 'Site_Manager_Helpers', 'post_summary' ), $query->posts ),
			$query->found_posts,
			$page,
			$per_page
		);
	}

	private function resolve_post( array $args ) {
		if ( ! empty( $args['id'] ) ) {
			return Site_Manager_Helpers::require_post( $args['id'] );
		}
		if ( ! empty( $args['url'] ) ) {
			$id = url_to_postid( (string) $args['url'] );
			if ( ! $id && trailingslashit( (string) $args['url'] ) === trailingslashit( home_url() ) ) {
				$id = (int) get_option( 'page_on_front' );
			}
			return $id ? get_post( $id ) : new WP_Error( 'not_found', 'No post found for that URL.' );
		}
		if ( ! empty( $args['slug'] ) ) {
			$posts = get_posts( array(
				'name'        => sanitize_title( $args['slug'] ),
				'post_type'   => self::post_type_arg( Site_Manager_Helpers::arg( $args, 'post_type' ) ),
				'post_status' => 'any',
				'numberposts' => 1,
			) );
			return $posts ? $posts[0] : new WP_Error( 'not_found', 'No post found with that slug.' );
		}
		return new WP_Error( 'missing_id', 'Pass id, url, or slug.' );
	}

	public function post_get( array $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return Site_Manager_Helpers::post_detail( $post, array(
			'include_content'      => Site_Manager_Helpers::bool( $args, 'include_content', true ),
			'include_meta'         => Site_Manager_Helpers::bool( $args, 'include_meta', true ),
			'include_private_meta' => Site_Manager_Helpers::bool( $args, 'include_private_meta', false ),
		) );
	}

	public function post_create( array $args ) {
		$args['post_type'] = sanitize_key( Site_Manager_Helpers::arg( $args, 'post_type', 'post' ) );
		if ( ! post_type_exists( $args['post_type'] ) ) {
			return new WP_Error( 'invalid_post_type', sprintf( 'Post type "%s" is not registered.', $args['post_type'] ) );
		}
		$postarr = Site_Manager_Helpers::postarr_from_args( $args );
		if ( ! isset( $postarr['post_status'] ) ) {
			$postarr['post_status'] = 'draft';
		}
		if ( ! isset( $postarr['post_author'] ) ) {
			$postarr['post_author'] = get_current_user_id();
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$report = Site_Manager_Helpers::apply_post_extras( $id, $args );
		return array(
			'post'    => Site_Manager_Helpers::post_summary( $id ),
			'applied' => $report,
		);
	}

	public function post_update( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		unset( $args['post_type'] );
		$postarr = Site_Manager_Helpers::postarr_from_args( $args );
		if ( $postarr ) {
			$postarr['ID'] = $post->ID;
			$result        = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$report = Site_Manager_Helpers::apply_post_extras( $post->ID, $args );
		return array(
			'post'    => Site_Manager_Helpers::post_summary( $post->ID ),
			'applied' => $report,
		);
	}

	public function post_delete( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$force   = Site_Manager_Helpers::bool( $args, 'force' ) || ! EMPTY_TRASH_DAYS;
		$summary = Site_Manager_Helpers::post_summary( $post );
		if ( Site_Manager_Helpers::bool( $args, 'dry_run' ) ) {
			return array( 'dry_run' => true, 'would' => $force ? 'delete permanently' : 'trash', 'post' => $summary );
		}
		$result = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
		if ( ! $result ) {
			return new WP_Error( 'delete_failed', 'WordPress refused to delete the post.' );
		}
		return array( 'deleted' => $force, 'trashed' => ! $force, 'post' => $summary );
	}

	public function post_restore( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( $post->post_status !== 'trash' ) {
			return new WP_Error( 'not_trashed', 'Post is not in the trash.' );
		}
		// Core restores to draft by default; restore the pre-trash status instead.
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		wp_untrash_post( $post->ID );
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		return Site_Manager_Helpers::post_summary( $post->ID );
	}

	public function post_duplicate( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$new_id = wp_insert_post( wp_slash( array(
			'post_type'      => $post->post_type,
			'post_title'     => Site_Manager_Helpers::arg( $args, 'title', $post->post_title . ' (copy)' ),
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => Site_Manager_Helpers::arg( $args, 'status', 'draft' ),
			'post_parent'    => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'post_password'  => $post->post_password,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_author'    => get_current_user_id(),
		) ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$ids = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && $ids ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $ids ), $tax );
			}
		}
		$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		return Site_Manager_Helpers::post_summary( $new_id );
	}

	public function posts_bulk_update( array $args ) {
		$ids     = array_map( 'intval', (array) Site_Manager_Helpers::arg( $args, 'ids', array() ) );
		$dry     = Site_Manager_Helpers::bool( $args, 'dry_run' );
		$postarr = Site_Manager_Helpers::postarr_from_args( array_intersect_key( $args, array_flip( array( 'status', 'author', 'parent', 'comment_status' ) ) ) );
		$extras  = array_intersect_key( $args, array_flip( array( 'terms', 'terms_append', 'meta' ) ) );
		$results = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				$results[] = array( 'id' => $id, 'error' => 'not found' );
				continue;
			}
			if ( $dry ) {
				$results[] = array( 'id' => $id, 'title' => $post->post_title, 'would_update' => array_keys( $postarr + $extras ) );
				continue;
			}
			if ( $postarr ) {
				$r = wp_update_post( wp_slash( array( 'ID' => $id ) + $postarr ), true );
				if ( is_wp_error( $r ) ) {
					$results[] = array( 'id' => $id, 'error' => $r->get_error_message() );
					continue;
				}
			}
			$applied   = $extras ? Site_Manager_Helpers::apply_post_extras( $id, $extras ) : array();
			$results[] = array( 'id' => $id, 'ok' => true, 'applied' => $applied );
		}
		return array( 'dry_run' => $dry, 'count' => count( $ids ), 'results' => $results );
	}

	public function content_search_replace( array $args ) {
		$search  = (string) Site_Manager_Helpers::arg( $args, 'search', '' );
		$replace = (string) Site_Manager_Helpers::arg( $args, 'replace', '' );
		$regex   = Site_Manager_Helpers::bool( $args, 'regex' );
		$dry     = Site_Manager_Helpers::bool( $args, 'dry_run', true );
		$fields  = (array) Site_Manager_Helpers::arg( $args, 'fields', array( 'content' ) );
		$limit   = max( 1, (int) Site_Manager_Helpers::arg( $args, 'limit', 200 ) );
		if ( $search === '' ) {
			return new WP_Error( 'empty_search', 'search must not be empty.' );
		}
		if ( $regex && @preg_match( $search, '' ) === false ) {
			return new WP_Error( 'bad_regex', 'Invalid regular expression: ' . $search );
		}

		$column = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' );
		$fields = array_values( array_intersect( array_keys( $column ), $fields ) );
		if ( ! $fields ) {
			return new WP_Error( 'no_fields', 'fields must include title, content and/or excerpt.' );
		}

		$ids = get_posts( array(
			'post_type'        => self::post_type_arg( Site_Manager_Helpers::arg( $args, 'post_type' ) ),
			'post_status'      => Site_Manager_Helpers::arg( $args, 'status', 'any' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		) );
		if ( ! $regex && $ids ) {
			// Narrow to posts containing the literal string in a target column.
			global $wpdb;
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where  = implode( ' OR ', array_map( function ( $f ) use ( $column ) {
				return $column[ $f ] . ' LIKE %s';
			}, $fields ) );
			$params = array_fill( 0, count( $fields ), $like );
			$hits   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE {$where}", $params ) ) );
			$ids    = array_values( array_intersect( array_map( 'intval', $ids ), $hits ) );
		}

		$changes = array();
		foreach ( $ids as $id ) {
			if ( count( $changes ) >= $limit ) {
				break;
			}
			$post   = get_post( $id );
			$update = array();
			$counts = array();
			foreach ( $fields as $f ) {
				$old = $post->{$column[ $f ]};
				$n   = 0;
				$new = $regex ? preg_replace( $search, $replace, $old, -1, $n ) : str_replace( $search, $replace, $old, $n );
				if ( $n > 0 && $new !== null ) {
					$update[ $column[ $f ] ] = $new;
					$counts[ $f ]            = $n;
				}
			}
			if ( ! $update ) {
				continue;
			}
			if ( ! $dry ) {
				wp_update_post( wp_slash( array( 'ID' => $id ) + $update ) );
			}
			$changes[] = array( 'id' => $id, 'type' => $post->post_type, 'title' => $post->post_title, 'replacements' => $counts );
		}
		return array(
			'dry_run'        => $dry,
			'posts_affected' => count( $changes ),
			'changes'        => $changes,
		);
	}

	public function post_meta_get( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! empty( $args['key'] ) ) {
			$values = array_map( 'maybe_unserialize', get_post_meta( $post->ID, (string) $args['key'], false ) );
			return array( 'key' => $args['key'], 'exists' => metadata_exists( 'post', $post->ID, $args['key'] ), 'values' => $values );
		}
		return Site_Manager_Helpers::meta_for( 'post', $post->ID, Site_Manager_Helpers::bool( $args, 'include_private' ) );
	}

	public function post_meta_update( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$meta = (array) Site_Manager_Helpers::arg( $args, 'meta', array() );
		if ( Site_Manager_Helpers::bool( $args, 'add' ) ) {
			foreach ( $meta as $key => $value ) {
				add_post_meta( $post->ID, (string) $key, wp_slash( $value ) );
			}
			return array( 'added' => array_keys( $meta ) );
		}
		return Site_Manager_Helpers::apply_meta( 'post', $post->ID, $meta );
	}

	public function post_meta_delete( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$value = array_key_exists( 'value', $args ) ? wp_slash( $args['value'] ) : '';
		$ok    = delete_post_meta( $post->ID, (string) $args['key'], $value );
		return array( 'deleted' => (bool) $ok );
	}

	public function meta_keys_list( array $args ) {
		global $wpdb;
		$post_type = sanitize_key( Site_Manager_Helpers::arg( $args, 'post_type', 'post' ) );
		$private   = Site_Manager_Helpers::bool( $args, 'include_private' );
		$search    = (string) Site_Manager_Helpers::arg( $args, 'search', '' );

		$sql    = "SELECT pm.meta_key, COUNT(*) AS uses, MAX(pm.meta_value) AS sample
			FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = %s";
		$params = array( $post_type );
		if ( ! $private ) {
			$sql .= " AND pm.meta_key NOT LIKE '\\_%'";
		}
		if ( $search !== '' ) {
			$sql     .= ' AND pm.meta_key LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql .= ' GROUP BY pm.meta_key ORDER BY uses DESC LIMIT 500';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$keys = array();
		foreach ( (array) $rows as $row ) {
			$sample = maybe_unserialize( $row['sample'] );
			if ( is_string( $sample ) && strlen( $sample ) > 120 ) {
				$sample = substr( $sample, 0, 120 ) . '…';
			}
			$keys[] = array( 'key' => $row['meta_key'], 'uses' => (int) $row['uses'], 'sample' => $sample );
		}

		$registered = array();
		foreach ( get_registered_meta_keys( 'post', $post_type ) + get_registered_meta_keys( 'post', '' ) as $key => $def ) {
			$registered[ $key ] = array(
				'type'         => $def['type'],
				'single'       => (bool) $def['single'],
				'description'  => $def['description'],
				'show_in_rest' => (bool) $def['show_in_rest'],
			);
		}

		return array( 'post_type' => $post_type, 'keys' => $keys, 'registered' => (object) $registered );
	}

	public function revisions_list( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$out = array();
		foreach ( wp_get_post_revisions( $post->ID ) as $rev ) {
			$out[] = array(
				'revision_id' => $rev->ID,
				'date'        => $rev->post_date,
				'author'      => (int) $rev->post_author,
				'title'       => $rev->post_title,
				'autosave'    => wp_is_post_autosave( $rev ) ? true : false,
				'length'      => strlen( $rev->post_content ),
			);
		}
		return $out;
	}

	public function revision_get( array $args ) {
		$rev = wp_get_post_revision( (int) Site_Manager_Helpers::arg( $args, 'revision_id' ) );
		if ( ! $rev ) {
			return new WP_Error( 'not_found', 'Revision not found.' );
		}
		return array(
			'revision_id' => $rev->ID,
			'post_id'     => (int) $rev->post_parent,
			'date'        => $rev->post_date,
			'title'       => $rev->post_title,
			'excerpt'     => $rev->post_excerpt,
			'content'     => $rev->post_content,
		);
	}

	public function revision_restore( array $args ) {
		$result = wp_restore_post_revision( (int) Site_Manager_Helpers::arg( $args, 'revision_id' ) );
		if ( ! $result || is_wp_error( $result ) ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'restore_failed', 'Could not restore that revision.' );
		}
		return Site_Manager_Helpers::post_summary( $result );
	}

	public function post_blocks_get( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$strip = function ( $blocks ) use ( &$strip ) {
			$out = array();
			foreach ( $blocks as $b ) {
				// Drop whitespace-only freeform blocks between real blocks.
				if ( $b['blockName'] === null && trim( $b['innerHTML'] ) === '' ) {
					continue;
				}
				$out[] = array(
					'blockName'    => $b['blockName'],
					'attrs'        => (object) $b['attrs'],
					'innerHTML'    => $b['innerHTML'],
					'innerContent' => $b['innerContent'],
					'innerBlocks'  => $strip( $b['innerBlocks'] ),
				);
			}
			return $out;
		};
		return array(
			'id'         => $post->ID,
			'has_blocks' => has_blocks( $post->post_content ),
			'blocks'     => $strip( parse_blocks( $post->post_content ) ),
		);
	}

	public function post_blocks_update( array $args ) {
		$post = Site_Manager_Helpers::require_post( Site_Manager_Helpers::arg( $args, 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$normalize = function ( $blocks ) use ( &$normalize ) {
			$out = array();
			foreach ( (array) $blocks as $b ) {
				$b     = (array) $b;
				$inner = $normalize( isset( $b['innerBlocks'] ) ? $b['innerBlocks'] : array() );
				$html  = isset( $b['innerHTML'] ) ? (string) $b['innerHTML'] : '';
				if ( isset( $b['innerContent'] ) && is_array( $b['innerContent'] ) ) {
					$content = $b['innerContent'];
				} else {
					// Without innerContent, place inner blocks after the HTML.
					$content = array( $html );
					foreach ( $inner as $unused ) {
						$content[] = null;
					}
				}
				$out[] = array(
					'blockName'    => isset( $b['blockName'] ) ? $b['blockName'] : null,
					'attrs'        => isset( $b['attrs'] ) ? (array) $b['attrs'] : array(),
					'innerHTML'    => $html,
					'innerContent' => $content,
					'innerBlocks'  => $inner,
				);
			}
			return $out;
		};
		// Same separator the block editor uses between top-level blocks.
		$content = implode( "\n\n", array_map( 'serialize_block', $normalize( $args['blocks'] ) ) );
		$result  = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'post' => Site_Manager_Helpers::post_summary( $post->ID ), 'content_length' => strlen( $content ) );
	}

	public function block_types_list( array $args ) {
		$search = strtolower( (string) Site_Manager_Helpers::arg( $args, 'search', '' ) );
		$attrs  = Site_Manager_Helpers::bool( $args, 'include_attributes' );
		$out    = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( $search !== '' && strpos( strtolower( $name . ' ' . $type->title ), $search ) === false ) {
				continue;
			}
			$row = array(
				'name'     => $name,
				'title'    => $type->title,
				'category' => $type->category,
				'parent'   => $type->parent,
				'dynamic'  => $type->is_dynamic(),
			);
			if ( $attrs ) {
				$row['attributes'] = $type->attributes;
				$row['supports']   = $type->supports;
			}
			$out[] = $row;
		}
		return $out;
	}

	public function block_patterns_list( array $args ) {
		$registry = WP_Block_Patterns_Registry::get_instance();
		if ( ! empty( $args['name'] ) ) {
			$pattern = $registry->get_registered( (string) $args['name'] );
			return $pattern ? $pattern : new WP_Error( 'not_found', 'Pattern not found.' );
		}
		$search = strtolower( (string) Site_Manager_Helpers::arg( $args, 'search', '' ) );
		$out    = array();
		foreach ( $registry->get_all_registered() as $p ) {
			if ( $search !== '' && strpos( strtolower( $p['name'] . ' ' . $p['title'] ), $search ) === false ) {
				continue;
			}
			$out[] = array(
				'name'       => $p['name'],
				'title'      => $p['title'],
				'categories' => isset( $p['categories'] ) ? $p['categories'] : array(),
			);
		}
		return $out;
	}
}

<?php
/**
 * MCP tools: Yoast SEO.
 *
 * Post SEO fields live in post meta (_yoast_wpseo_*); term SEO fields live
 * in the wpseo_taxonomy_meta option. After every write the Yoast indexable
 * is rebuilt so the change shows up in the front-end head immediately.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Yoast {

	/** Friendly name → post meta key (without the _yoast_wpseo_ prefix). */
	const POST_FIELDS = array(
		'title'               => 'title',
		'description'         => 'metadesc',
		'focus_keyphrase'     => 'focuskw',
		'canonical'           => 'canonical',
		'noindex'             => 'meta-robots-noindex',
		'nofollow'            => 'meta-robots-nofollow',
		'robots_advanced'     => 'meta-robots-adv',
		'breadcrumb_title'    => 'bctitle',
		'og_title'            => 'opengraph-title',
		'og_description'      => 'opengraph-description',
		'og_image'            => 'opengraph-image',
		'og_image_id'         => 'opengraph-image-id',
		'twitter_title'       => 'twitter-title',
		'twitter_description' => 'twitter-description',
		'twitter_image'       => 'twitter-image',
		'twitter_image_id'    => 'twitter-image-id',
		'cornerstone'         => 'is_cornerstone',
		'schema_page_type'    => 'schema_page_type',
		'schema_article_type' => 'schema_article_type',
	);

	/** Friendly name → wpseo_taxonomy_meta key. */
	const TERM_FIELDS = array(
		'title'               => 'wpseo_title',
		'description'         => 'wpseo_desc',
		'focus_keyphrase'     => 'wpseo_focuskw',
		'canonical'           => 'wpseo_canonical',
		'noindex'             => 'wpseo_noindex',
		'breadcrumb_title'    => 'wpseo_bctitle',
		'og_title'            => 'wpseo_opengraph-title',
		'og_description'      => 'wpseo_opengraph-description',
		'og_image'            => 'wpseo_opengraph-image',
		'og_image_id'         => 'wpseo_opengraph-image-id',
		'twitter_title'       => 'wpseo_twitter-title',
		'twitter_description' => 'wpseo_twitter-description',
		'twitter_image'       => 'wpseo_twitter-image',
		'twitter_image_id'    => 'wpseo_twitter-image-id',
		'cornerstone'         => 'wpseo_is_cornerstone',
	);

	/** Settings option groups exposed for read/write. */
	const SETTINGS_OPTIONS = array( 'wpseo', 'wpseo_titles', 'wpseo_social' );

	public static function is_active() {
		return defined( 'WPSEO_VERSION' );
	}

	public static function version() {
		return WPSEO_VERSION . ( defined( 'WPSEO_PREMIUM_VERSION' ) ? ' (Premium)' : '' );
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'yoast', __( 'Yoast SEO', 'site-manager' ), __( 'SEO titles, meta descriptions, focus keyphrases, robots, social metadata, Yoast settings.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'Yoast SEO is active: use yoast_post_get / yoast_post_update for SEO titles, meta descriptions, focus keyphrases, canonicals and robots settings.';
			return $lines;
		} );

		$post_fields = array(
			'title'               => $s::str( 'SEO title template, e.g. "%%title%% %%sep%% %%sitename%%".' ),
			'description'         => $s::str( 'Meta description.' ),
			'focus_keyphrase'     => $s::str(),
			'canonical'           => $s::str( 'Canonical URL.' ),
			'noindex'             => $s::enum( array( 'default', 'noindex', 'index' ) ),
			'nofollow'            => $s::bool( 'true = nofollow.' ),
			'robots_advanced'     => $s::str( 'Comma list: noimageindex, noarchive, nosnippet.' ),
			'breadcrumb_title'    => $s::str(),
			'og_title'            => $s::str(),
			'og_description'      => $s::str(),
			'og_image'            => $s::str( 'Image URL.' ),
			'og_image_id'         => $s::int(),
			'twitter_title'       => $s::str(),
			'twitter_description' => $s::str(),
			'twitter_image'       => $s::str(),
			'twitter_image_id'    => $s::int(),
			'cornerstone'         => $s::bool(),
			'schema_page_type'    => $s::str( 'e.g. WebPage, AboutPage, ContactPage, FAQPage.' ),
			'schema_article_type' => $s::str( 'e.g. Article, BlogPosting, NewsArticle, None.' ),
			'primary_terms'       => $s::map( 'Primary term per taxonomy: {"category": 12}.' ),
		);

		$r->register( 'yoast_post_get', array(
			'category'     => 'yoast',
			'description'  => 'Get Yoast SEO data for a post: stored SEO fields, SEO and readability scores, primary terms, and the resolved title/description/canonical/robots that Yoast outputs in the page head.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'post_get' ),
		) );

		$r->register( 'yoast_post_update', array(
			'category'     => 'yoast',
			'description'  => 'Set Yoast SEO fields on a post (title, meta description, focus keyphrase, canonical, robots, social, schema, primary terms). Empty string clears a field.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'id' => $s::int() ), $post_fields ), array( 'id' ) ),
			'handler'      => array( $this, 'post_update' ),
		) );

		$r->register( 'yoast_posts_audit', array(
			'category'     => 'yoast',
			'description'  => 'Find published posts with SEO gaps: missing meta description, missing focus keyphrase, noindex set, or low SEO score. Good for bulk SEO work.',
			'input_schema' => $s::obj( array(
				'post_type' => $s::str( 'Default "any" public type.' ),
				'issue'     => $s::enum( array( 'missing_description', 'missing_focus_keyphrase', 'missing_title', 'noindex', 'low_score' ), '', 'missing_description' ),
				'page'      => $s::page(),
				'per_page'  => $s::per_page( 50, 200 ),
			) ),
			'handler'      => array( $this, 'posts_audit' ),
		) );

		$r->register( 'yoast_term_get', array(
			'category'     => 'yoast',
			'description'  => 'Get Yoast SEO fields for a category, tag or custom taxonomy term.',
			'input_schema' => $s::obj( array( 'id' => $s::int( 'Term ID.' ) ), array( 'id' ) ),
			'handler'      => array( $this, 'term_get' ),
		) );

		$r->register( 'yoast_term_update', array(
			'category'     => 'yoast',
			'description'  => 'Set Yoast SEO fields on a term. noindex takes default | index | noindex.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'id' => $s::int( 'Term ID.' ) ), array_intersect_key( $post_fields, self::TERM_FIELDS ) ), array( 'id' ) ),
			'handler'      => array( $this, 'term_update' ),
		) );

		$r->register( 'yoast_settings_get', array(
			'category'     => 'yoast',
			'description'  => 'Read Yoast settings. wpseo = features and site basics; wpseo_titles = title/description templates per post type, taxonomy and archive, separator, schema defaults, noindex defaults; wpseo_social = social profiles and default images.',
			'input_schema' => $s::obj( array(
				'option' => $s::enum( self::SETTINGS_OPTIONS, '', 'wpseo_titles' ),
			) ),
			'handler'      => array( $this, 'settings_get' ),
		) );

		$r->register( 'yoast_settings_update', array(
			'category'     => 'yoast',
			'description'  => 'Update Yoast settings keys in one option group, e.g. option=wpseo_titles values={"title-post": "%%title%% %%sep%% %%sitename%%", "metadesc-page": "%%excerpt%%"}. Only existing keys are accepted.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'option' => $s::enum( self::SETTINGS_OPTIONS ),
				'values' => $s::map(),
			), array( 'option', 'values' ) ),
			'handler'      => array( $this, 'settings_update' ),
		) );

		$r->register( 'yoast_redirects_list', array(
			'category'     => 'yoast',
			'description'  => 'List Yoast Premium redirects.',
			'handler'      => array( $this, 'redirects_list' ),
		) );
	}

	// ---------------------------------------------------------------
	// Shared helpers
	// ---------------------------------------------------------------

	public static function post_values( $post_id ) {
		$out = array();
		foreach ( self::POST_FIELDS as $name => $key ) {
			$value = get_post_meta( $post_id, '_yoast_wpseo_' . $key, true );
			if ( $value === '' ) {
				continue;
			}
			if ( $name === 'noindex' ) {
				$value = array( '0' => 'default', '1' => 'noindex', '2' => 'index' )[ (string) $value ] ?? $value;
			} elseif ( in_array( $name, array( 'nofollow', 'cornerstone' ), true ) ) {
				$value = (bool) $value;
			}
			$out[ $name ] = $value;
		}
		$primary = array();
		foreach ( get_object_taxonomies( get_post_type( $post_id ) ) as $tax ) {
			$id = (int) get_post_meta( $post_id, '_yoast_wpseo_primary_' . $tax, true );
			if ( $id ) {
				$primary[ $tax ] = $id;
			}
		}
		if ( $primary ) {
			$out['primary_terms'] = $primary;
		}
		$score = get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );
		$read  = get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
		$out['seo_score']         = $score !== '' ? (int) $score : null;
		$out['readability_score'] = $read !== '' ? (int) $read : null;
		return (object) $out;
	}

	public static function update_post_values( $post_id, array $values ) {
		$done = array();
		foreach ( $values as $name => $value ) {
			if ( $name === 'primary_terms' ) {
				foreach ( (array) $value as $tax => $term_id ) {
					update_post_meta( $post_id, '_yoast_wpseo_primary_' . sanitize_key( $tax ), (int) $term_id );
				}
				$done[] = $name;
				continue;
			}
			if ( ! isset( self::POST_FIELDS[ $name ] ) ) {
				continue;
			}
			$key = '_yoast_wpseo_' . self::POST_FIELDS[ $name ];
			if ( $name === 'noindex' ) {
				$value = array( 'default' => '0', 'noindex' => '1', 'index' => '2' )[ (string) $value ] ?? (string) $value;
			} elseif ( in_array( $name, array( 'nofollow', 'cornerstone' ), true ) ) {
				$value = $value ? '1' : '';
			}
			if ( $value === '' || $value === null ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( (string) $value ) );
			}
			$done[] = $name;
		}
		self::rebuild_indexable( $post_id, 'post' );
		return $done;
	}

	public static function term_values( WP_Term $term ) {
		$meta = get_option( 'wpseo_taxonomy_meta', array() );
		$raw  = isset( $meta[ $term->taxonomy ][ $term->term_id ] ) ? (array) $meta[ $term->taxonomy ][ $term->term_id ] : array();
		$out  = array();
		foreach ( self::TERM_FIELDS as $name => $key ) {
			if ( isset( $raw[ $key ] ) && $raw[ $key ] !== '' ) {
				$out[ $name ] = $raw[ $key ];
			}
		}
		return (object) $out;
	}

	public static function update_term_values( WP_Term $term, array $values ) {
		$new = array();
		foreach ( $values as $name => $value ) {
			if ( isset( self::TERM_FIELDS[ $name ] ) ) {
				if ( $name === 'cornerstone' ) {
					$value = $value ? '1' : '';
				}
				$new[ self::TERM_FIELDS[ $name ] ] = (string) $value;
			}
		}
		if ( class_exists( 'WPSEO_Taxonomy_Meta' ) && method_exists( 'WPSEO_Taxonomy_Meta', 'set_values' ) ) {
			// Validates and merges with existing values.
			WPSEO_Taxonomy_Meta::set_values( $term->term_id, $term->taxonomy, $new );
		} else {
			$meta = get_option( 'wpseo_taxonomy_meta', array() );
			$meta[ $term->taxonomy ][ $term->term_id ] = array_filter( array_merge(
				isset( $meta[ $term->taxonomy ][ $term->term_id ] ) ? (array) $meta[ $term->taxonomy ][ $term->term_id ] : array(),
				$new
			), 'strlen' );
			update_option( 'wpseo_taxonomy_meta', $meta );
		}
		self::rebuild_indexable( $term->term_id, 'term' );
		return array_keys( array_intersect_key( $values, self::TERM_FIELDS ) );
	}

	/**
	 * Rebuild Yoast's indexable so the head output reflects new meta at once.
	 */
	private static function rebuild_indexable( $object_id, $type ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return;
		}
		try {
			$repo      = YoastSEO()->classes->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
			$builder   = YoastSEO()->classes->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
			$indexable = $repo->find_by_id_and_type( (int) $object_id, $type, false );
			$builder->build_for_id_and_type( (int) $object_id, $type, $indexable );
		} catch ( Throwable $e ) {
			// Yoast rebuilds on the next save anyway; not worth failing the write.
		}
	}

	private static function resolved_head( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return null;
		}
		try {
			$meta = YoastSEO()->meta->for_post( $post_id );
			if ( ! $meta ) {
				return null;
			}
			return array(
				'title'       => $meta->title,
				'description' => $meta->description,
				'canonical'   => $meta->canonical,
				'robots'      => $meta->robots,
				'og_title'    => $meta->open_graph_title,
				'og_image'    => $meta->open_graph_images ? array_values( (array) $meta->open_graph_images )[0]['url'] ?? null : null,
			);
		} catch ( Throwable $e ) {
			return null;
		}
	}

	// ---------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------

	public function post_get( array $args ) {
		$post = Site_Manager_Helpers::require_post( (int) $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return array(
			'post'     => Site_Manager_Helpers::post_summary( $post ),
			'fields'   => self::post_values( $post->ID ),
			'resolved' => self::resolved_head( $post->ID ),
		);
	}

	public function post_update( array $args ) {
		$post = Site_Manager_Helpers::require_post( (int) $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		unset( $args['id'] );
		$done = self::update_post_values( $post->ID, $args );
		return array(
			'updated'  => $done,
			'fields'   => self::post_values( $post->ID ),
			'resolved' => self::resolved_head( $post->ID ),
		);
	}

	public function posts_audit( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 50, 200 );
		$issue    = Site_Manager_Helpers::arg( $args, 'issue', 'missing_description' );
		$missing  = function ( $key ) {
			return array(
				'relation' => 'OR',
				array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
				array( 'key' => $key, 'value' => '' ),
			);
		};
		$meta_query = array(
			'missing_description'     => $missing( '_yoast_wpseo_metadesc' ),
			'missing_focus_keyphrase' => $missing( '_yoast_wpseo_focuskw' ),
			'missing_title'           => $missing( '_yoast_wpseo_title' ),
			'noindex'                 => array( array( 'key' => '_yoast_wpseo_meta-robots-noindex', 'value' => '1' ) ),
			'low_score'               => array( array( 'key' => '_yoast_wpseo_linkdex', 'value' => 41, 'compare' => '<', 'type' => 'NUMERIC' ) ),
		);
		$query = new WP_Query( array(
			'post_type'      => Site_Manager_Helpers::arg( $args, 'post_type', 'any' ),
			'post_status'    => 'publish',
			'meta_query'     => isset( $meta_query[ $issue ] ) ? $meta_query[ $issue ] : array(),
			'paged'          => $page,
			'posts_per_page' => $per_page,
		) );
		$items = array_map( function ( $p ) {
			$row          = Site_Manager_Helpers::post_summary( $p );
			$row['yoast'] = self::post_values( $p->ID );
			return $row;
		}, $query->posts );
		return array( 'issue' => $issue ) + Site_Manager_Helpers::paged( $items, $query->found_posts, $page, $per_page );
	}

	public function term_get( array $args ) {
		$term = get_term( (int) $args['id'] );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}
		return array( 'term' => Site_Manager_Helpers::term_detail( $term, false ), 'fields' => self::term_values( $term ) );
	}

	public function term_update( array $args ) {
		$term = get_term( (int) $args['id'] );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}
		unset( $args['id'] );
		$done = self::update_term_values( $term, $args );
		return array( 'updated' => $done, 'fields' => self::term_values( get_term( $term->term_id ) ) );
	}

	public function settings_get( array $args ) {
		$option = Site_Manager_Helpers::arg( $args, 'option', 'wpseo_titles' );
		if ( ! in_array( $option, self::SETTINGS_OPTIONS, true ) ) {
			return new WP_Error( 'invalid_option', 'Unknown Yoast settings group.' );
		}
		return array( 'option' => $option, 'values' => (object) get_option( $option, array() ) );
	}

	public function settings_update( array $args ) {
		$option = (string) $args['option'];
		if ( ! in_array( $option, self::SETTINGS_OPTIONS, true ) ) {
			return new WP_Error( 'invalid_option', 'Unknown Yoast settings group.' );
		}
		$current  = (array) get_option( $option, array() );
		$accepted = array();
		$rejected = array();
		foreach ( (array) $args['values'] as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				$rejected[] = $key;
				continue;
			}
			if ( class_exists( 'WPSEO_Options' ) && method_exists( 'WPSEO_Options', 'set' ) ) {
				WPSEO_Options::set( $key, $value, $option );
			} else {
				$current[ $key ] = $value;
			}
			$accepted[] = $key;
		}
		if ( ! class_exists( 'WPSEO_Options' ) || ! method_exists( 'WPSEO_Options', 'set' ) ) {
			update_option( $option, $current );
		}
		return array( 'updated' => $accepted, 'unknown_keys' => $rejected );
	}

	public function redirects_list() {
		$redirects = get_option( 'wpseo-premium-redirects-base', null );
		if ( $redirects === null ) {
			return new WP_Error( 'not_supported', 'Redirects require Yoast SEO Premium.' );
		}
		$out = array();
		foreach ( (array) $redirects as $origin => $r ) {
			$out[] = array( 'origin' => $origin, 'url' => $r['url'], 'type' => (int) $r['type'], 'format' => isset( $r['format'] ) ? $r['format'] : 'plain' );
		}
		return $out;
	}
}

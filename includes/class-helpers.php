<?php
/**
 * Shared formatting and write helpers for tool handlers.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Helpers {

	/** Meta keys that are noise for every caller. */
	const HIDDEN_META = array( '_edit_lock', '_edit_last', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	// ---------------------------------------------------------------
	// Argument helpers
	// ---------------------------------------------------------------

	public static function arg( array $args, $key, $default = null ) {
		return array_key_exists( $key, $args ) && $args[ $key ] !== null ? $args[ $key ] : $default;
	}

	public static function per_page( array $args, $default = 20, $max = 100 ) {
		return max( 1, min( $max, (int) self::arg( $args, 'per_page', $default ) ) );
	}

	public static function page( array $args ) {
		return max( 1, (int) self::arg( $args, 'page', 1 ) );
	}

	public static function bool( array $args, $key, $default = false ) {
		if ( ! array_key_exists( $key, $args ) || $args[ $key ] === null ) {
			return $default;
		}
		return filter_var( $args[ $key ], FILTER_VALIDATE_BOOLEAN );
	}

	/** Load wp-admin includes that tools commonly need outside wp-admin. */
	public static function load_admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	// ---------------------------------------------------------------
	// Posts
	// ---------------------------------------------------------------

	public static function require_post( $post_id, $post_type = null ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post ) {
			return new WP_Error( 'not_found', sprintf( 'Post %d not found.', (int) $post_id ) );
		}
		if ( $post_type && $post->post_type !== $post_type ) {
			return new WP_Error( 'wrong_type', sprintf( 'Post %d is a %s, not a %s.', $post->ID, $post->post_type, $post_type ) );
		}
		return $post;
	}

	public static function post_summary( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		return array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'author'   => (int) $post->post_author,
			'parent'   => (int) $post->post_parent,
			'link'     => get_permalink( $post ),
		);
	}

	/**
	 * Full post representation.
	 *
	 * @param array $opts include_content, include_meta, include_private_meta, include_acf, include_seo.
	 */
	public static function post_detail( $post, array $opts = array() ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		$opts = wp_parse_args( $opts, array(
			'include_content'      => true,
			'include_meta'         => true,
			'include_private_meta' => false,
			'include_acf'          => true,
			'include_seo'          => true,
		) );

		$out = self::post_summary( $post );
		$out['menu_order']     = (int) $post->menu_order;
		$out['excerpt']        = $post->post_excerpt;
		$out['comment_status'] = $post->comment_status;
		$out['ping_status']    = $post->ping_status;
		$out['password']       = $post->post_password !== '' ? '(set)' : '';
		$out['mime_type']      = $post->post_mime_type;
		$out['template']       = get_page_template_slug( $post );
		$out['featured_image'] = self::featured_image( $post->ID );
		$out['terms']          = self::post_terms( $post->ID );
		$out['edit_link']      = get_edit_post_link( $post->ID, 'raw' );
		if ( $opts['include_content'] ) {
			$out['content'] = $post->post_content;
		}
		if ( $opts['include_meta'] ) {
			$out['meta'] = self::meta_for( 'post', $post->ID, $opts['include_private_meta'] );
		}
		if ( $opts['include_acf'] && class_exists( 'Site_Manager_Tools_ACF' ) && Site_Manager_Tools_ACF::is_active() ) {
			$out['acf'] = Site_Manager_Tools_ACF::values_for( $post->ID );
		}
		if ( $opts['include_seo'] && class_exists( 'Site_Manager_Tools_Yoast' ) && Site_Manager_Tools_Yoast::is_active() ) {
			$out['yoast'] = Site_Manager_Tools_Yoast::post_values( $post->ID );
		}
		return $out;
	}

	public static function featured_image( $post_id ) {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return null;
		}
		return array(
			'id'  => $thumb_id,
			'url' => wp_get_attachment_url( $thumb_id ),
			'alt' => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
		);
	}

	public static function post_terms( $post_id ) {
		$post = get_post( $post_id );
		$out  = array();
		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $tax ) {
			$terms = wp_get_object_terms( $post->ID, $tax );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$out[ $tax ] = array_map( function ( $t ) {
				return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
			}, $terms );
		}
		return (object) $out;
	}

	/**
	 * All meta for an object, unserialized, single values collapsed.
	 *
	 * @param string $type post|term|user|comment
	 */
	public static function meta_for( $type, $object_id, $include_private = false ) {
		$raw = (array) get_metadata( $type, $object_id );
		$out = array();
		foreach ( $raw as $key => $values ) {
			if ( in_array( $key, self::HIDDEN_META, true ) ) {
				continue;
			}
			if ( ! $include_private && is_protected_meta( $key, $type ) ) {
				continue;
			}
			// ACF stores each value next to a "_{key}" → field_… reference.
			// Those values are reported under "acf" instead.
			if ( ! $include_private && isset( $raw[ '_' . $key ][0] ) && strpos( (string) $raw[ '_' . $key ][0], 'field_' ) === 0 ) {
				continue;
			}
			$values      = array_map( 'maybe_unserialize', (array) $values );
			$out[ $key ] = count( $values ) === 1 ? $values[0] : $values;
		}
		ksort( $out );
		return (object) $out;
	}

	/**
	 * Apply a { key: value } meta map. A null value deletes the key.
	 *
	 * @return array Keys written / deleted.
	 */
	public static function apply_meta( $type, $object_id, array $meta ) {
		$done = array( 'updated' => array(), 'deleted' => array() );
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( $value === null ) {
				delete_metadata( $type, $object_id, $key );
				$done['deleted'][] = $key;
			} else {
				update_metadata( $type, $object_id, $key, wp_slash( $value ) );
				$done['updated'][] = $key;
			}
		}
		return $done;
	}

	/**
	 * Map a create/update tool's arguments onto wp_insert_post fields.
	 */
	public static function postarr_from_args( array $args ) {
		$map = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'date'           => 'post_date',
			'author'         => 'post_author',
			'parent'         => 'post_parent',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
			'password'       => 'post_password',
			'post_type'      => 'post_type',
			'mime_type'      => 'post_mime_type',
		);
		$postarr = array();
		foreach ( $map as $arg => $field ) {
			if ( array_key_exists( $arg, $args ) && $args[ $arg ] !== null ) {
				$postarr[ $field ] = $args[ $arg ];
			}
		}
		if ( isset( $postarr['post_date'] ) ) {
			$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
			$postarr['edit_date']     = true;
		}
		return $postarr;
	}

	/**
	 * Apply the non-core parts of a post write: terms, meta, featured
	 * image, page template, ACF fields and Yoast fields.
	 *
	 * @return array Report of what was applied, including warnings.
	 */
	public static function apply_post_extras( $post_id, array $args ) {
		$report = array();

		if ( ! empty( $args['terms'] ) && is_array( $args['terms'] ) ) {
			$append = self::bool( $args, 'terms_append', false );
			foreach ( $args['terms'] as $taxonomy => $terms ) {
				$result = wp_set_object_terms( $post_id, self::normalize_terms( (array) $terms ), $taxonomy, $append );
				if ( is_wp_error( $result ) ) {
					$report['warnings'][] = "terms[{$taxonomy}]: " . $result->get_error_message();
				} else {
					$report['terms'][ $taxonomy ] = array_map( 'intval', $result );
				}
			}
		}

		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			$report['meta'] = self::apply_meta( 'post', $post_id, $args['meta'] );
		}

		if ( array_key_exists( 'featured_image_id', $args ) && $args['featured_image_id'] !== null ) {
			$thumb = (int) $args['featured_image_id'];
			if ( $thumb ) {
				set_post_thumbnail( $post_id, $thumb );
			} else {
				delete_post_thumbnail( $post_id );
			}
			$report['featured_image_id'] = $thumb;
		}

		if ( isset( $args['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $args['template'] ) );
			$report['template'] = $args['template'];
		}

		if ( ! empty( $args['acf'] ) && is_array( $args['acf'] ) ) {
			if ( class_exists( 'Site_Manager_Tools_ACF' ) && Site_Manager_Tools_ACF::is_active() ) {
				$report['acf'] = Site_Manager_Tools_ACF::update_values( $post_id, $args['acf'] );
			} else {
				$report['warnings'][] = 'acf: Advanced Custom Fields is not active; values ignored.';
			}
		}

		if ( ! empty( $args['yoast'] ) && is_array( $args['yoast'] ) ) {
			if ( class_exists( 'Site_Manager_Tools_Yoast' ) && Site_Manager_Tools_Yoast::is_active() ) {
				$report['yoast'] = Site_Manager_Tools_Yoast::update_post_values( $post_id, $args['yoast'] );
			} else {
				$report['warnings'][] = 'yoast: Yoast SEO is not active; values ignored.';
			}
		}

		return $report;
	}

	/**
	 * Numeric strings become term IDs; other strings are names/slugs that
	 * wp_set_object_terms() looks up (and creates when missing).
	 */
	public static function normalize_terms( array $terms ) {
		return array_map( function ( $t ) {
			return is_numeric( $t ) ? (int) $t : (string) $t;
		}, $terms );
	}

	// ---------------------------------------------------------------
	// Other objects
	// ---------------------------------------------------------------

	public static function term_detail( $term, $include_meta = true ) {
		$term = $term instanceof WP_Term ? $term : get_term( $term );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		$out = array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'link'        => get_term_link( $term ),
		);
		if ( is_wp_error( $out['link'] ) ) {
			$out['link'] = null;
		}
		if ( $include_meta ) {
			$out['meta'] = self::meta_for( 'term', $term->term_id, false );
		}
		return $out;
	}

	public static function user_summary( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( $user );
		if ( ! $user ) {
			return null;
		}
		return array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'roles'        => array_values( $user->roles ),
			'registered'   => $user->user_registered,
		);
	}

	public static function attachment_detail( $attachment ) {
		$post = get_post( $attachment );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $post->ID );
		$out  = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'filename'    => wp_basename( (string) get_attached_file( $post->ID ) ),
			'url'         => wp_get_attachment_url( $post->ID ),
			'mime_type'   => $post->post_mime_type,
			'alt'         => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'parent'      => (int) $post->post_parent,
			'date'        => $post->post_date,
			'filesize'    => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : null,
		);
		if ( isset( $meta['width'] ) ) {
			$out['width']  = (int) $meta['width'];
			$out['height'] = (int) $meta['height'];
			$sizes         = array();
			foreach ( isset( $meta['sizes'] ) ? (array) $meta['sizes'] : array() as $size => $info ) {
				$src            = wp_get_attachment_image_src( $post->ID, $size );
				$sizes[ $size ] = $src ? $src[0] : null;
			}
			$out['sizes'] = (object) $sizes;
		}
		return $out;
	}

	public static function comment_detail( $comment ) {
		$c = get_comment( $comment );
		if ( ! $c ) {
			return null;
		}
		return array(
			'id'           => (int) $c->comment_ID,
			'post_id'      => (int) $c->comment_post_ID,
			'parent'       => (int) $c->comment_parent,
			'author'       => $c->comment_author,
			'author_email' => $c->comment_author_email,
			'author_url'   => $c->comment_author_url,
			'user_id'      => (int) $c->user_id,
			'date'         => $c->comment_date,
			'content'      => $c->comment_content,
			'status'       => wp_get_comment_status( $c ),
			'type'         => $c->comment_type,
		);
	}

	/**
	 * Paginated list envelope.
	 */
	public static function paged( array $items, $total, $page, $per_page ) {
		return array(
			'items'       => array_values( $items ),
			'total'       => (int) $total,
			'page'        => (int) $page,
			'per_page'    => (int) $per_page,
			'total_pages' => $per_page ? (int) ceil( $total / $per_page ) : 1,
		);
	}
}

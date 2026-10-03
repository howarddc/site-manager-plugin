<?php
/**
 * MCP tools: media library.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Media {

	const MAX_BASE64_BYTES = 50 * MB_IN_BYTES;

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'media', __( 'Media', 'site-manager' ), __( 'Media library uploads, alt text, captions, image sizes.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'media_list', array(
			'category'     => 'media',
			'description'  => 'List media library items, filtered by MIME type, search term, attached post or date.',
			'input_schema' => $s::obj( array(
				'mime_type'   => $s::str( 'e.g. "image", "image/png", "application/pdf", "video".' ),
				'search'      => $s::str(),
				'parent'      => $s::int( 'Attached to this post ID (0 = unattached).' ),
				'missing_alt' => $s::bool( 'Only images with no alt text.', false ),
				'after'       => $s::str(),
				'before'      => $s::str(),
				'page'        => $s::page(),
				'per_page'    => $s::per_page( 20, 100 ),
			) ),
			'handler'      => array( $this, 'media_list' ),
		) );

		$r->register( 'media_get', array(
			'category'     => 'media',
			'description'  => 'Get a media item\'s URL, file name, dimensions, generated sizes, alt text, caption and description.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'media_get' ),
		) );

		$r->register( 'media_upload', array(
			'category'     => 'media',
			'description'  => 'Add a file to the media library from a public URL or base64 data. Optionally set title, alt text, caption, description, attach it to a post, or make it that post\'s featured image.',
			'writes'       => true,
			'open_world'   => true,
			'input_schema' => $s::obj( array(
				'url'          => $s::str( 'Public URL to download.' ),
				'data'         => $s::str( 'Base64-encoded file contents (alternative to url).' ),
				'filename'     => $s::str( 'File name including extension. Required with data.' ),
				'title'        => $s::str(),
				'alt'          => $s::str(),
				'caption'      => $s::str(),
				'description'  => $s::str(),
				'post_id'      => $s::int( 'Attach to this post.' ),
				'set_featured' => $s::bool( 'Make it post_id\'s featured image.', false ),
			) ),
			'handler'      => array( $this, 'media_upload' ),
		) );

		$r->register( 'media_update', array(
			'category'     => 'media',
			'description'  => 'Update a media item\'s title, alt text, caption, description or attached post.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'          => $s::int(),
				'title'       => $s::str(),
				'alt'         => $s::str(),
				'caption'     => $s::str(),
				'description' => $s::str(),
				'parent'      => $s::int(),
			), array( 'id' ) ),
			'handler'      => array( $this, 'media_update' ),
		) );

		$r->register( 'media_delete', array(
			'category'     => 'media',
			'description'  => 'Permanently delete a media item and its files.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'      => $s::int(),
				'dry_run' => $s::dry_run(),
			), array( 'id' ) ),
			'handler'      => array( $this, 'media_delete' ),
		) );

		$r->register( 'media_regenerate', array(
			'category'     => 'media',
			'description'  => 'Regenerate thumbnails / image sizes for one or more images.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'ids' => $s::arr( 'integer' ) ), array( 'ids' ) ),
			'handler'      => array( $this, 'media_regenerate' ),
		) );

		$r->register( 'image_sizes_list', array(
			'category'     => 'media',
			'description'  => 'List registered image sizes with dimensions and crop settings.',
			'handler'      => array( $this, 'image_sizes_list' ),
		) );
	}

	public function media_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args );
		$q        = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'paged'          => $page,
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['mime_type'] ) ) {
			$q['post_mime_type'] = (string) $args['mime_type'];
		}
		if ( ! empty( $args['search'] ) ) {
			$q['s'] = (string) $args['search'];
		}
		if ( isset( $args['parent'] ) ) {
			$q['post_parent'] = (int) $args['parent'];
		}
		if ( Site_Manager_Helpers::bool( $args, 'missing_alt' ) ) {
			$q['post_mime_type'] = 'image';
			$q['meta_query']     = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			);
		}
		$date = array_filter( array(
			'after'  => Site_Manager_Helpers::arg( $args, 'after' ),
			'before' => Site_Manager_Helpers::arg( $args, 'before' ),
		) );
		if ( $date ) {
			$q['date_query'] = array( $date );
		}
		$query = new WP_Query( $q );
		$items = array_map( function ( $p ) {
			$d = Site_Manager_Helpers::attachment_detail( $p );
			unset( $d['sizes'], $d['description'] );
			return $d;
		}, $query->posts );
		return Site_Manager_Helpers::paged( $items, $query->found_posts, $page, $per_page );
	}

	public function media_get( array $args ) {
		$detail = Site_Manager_Helpers::attachment_detail( (int) $args['id'] );
		return $detail ? $detail : new WP_Error( 'not_found', 'Attachment not found.' );
	}

	public function media_upload( array $args ) {
		Site_Manager_Helpers::load_admin_includes();
		$post_id = (int) Site_Manager_Helpers::arg( $args, 'post_id', 0 );

		if ( ! empty( $args['url'] ) ) {
			$url = esc_url_raw( (string) $args['url'] );
			$tmp = download_url( $url, 300 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$name = ! empty( $args['filename'] ) ? (string) $args['filename'] : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		} elseif ( ! empty( $args['data'] ) ) {
			if ( empty( $args['filename'] ) ) {
				return new WP_Error( 'missing_filename', 'filename is required with data.' );
			}
			$data = (string) $args['data'];
			if ( preg_match( '/^data:[^;]+;base64,/', $data ) ) {
				$data = substr( $data, strpos( $data, ',' ) + 1 );
			}
			$bytes = base64_decode( $data, true );
			if ( $bytes === false ) {
				return new WP_Error( 'bad_data', 'data is not valid base64.' );
			}
			if ( strlen( $bytes ) > self::MAX_BASE64_BYTES ) {
				return new WP_Error( 'too_large', 'File exceeds 50 MB; upload it by URL instead.' );
			}
			$tmp = wp_tempnam( (string) $args['filename'] );
			file_put_contents( $tmp, $bytes );
			$name = (string) $args['filename'];
		} else {
			return new WP_Error( 'missing_source', 'Pass url or data.' );
		}

		$name = sanitize_file_name( $name ?: 'upload' );
		$file = array( 'name' => $name, 'tmp_name' => $tmp );
		$id   = media_handle_sideload( $file, $post_id, isset( $args['title'] ) ? (string) $args['title'] : null, array_filter( array(
			'post_excerpt' => Site_Manager_Helpers::arg( $args, 'caption' ),
			'post_content' => Site_Manager_Helpers::arg( $args, 'description' ),
		), 'is_string' ) );
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( isset( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $args['alt'] ) ) );
		}
		if ( $post_id && Site_Manager_Helpers::bool( $args, 'set_featured' ) ) {
			set_post_thumbnail( $post_id, $id );
		}
		return Site_Manager_Helpers::attachment_detail( $id );
	}

	public function media_update( array $args ) {
		$post = Site_Manager_Helpers::require_post( (int) $args['id'], 'attachment' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$update = array_filter( array(
			'post_title'   => Site_Manager_Helpers::arg( $args, 'title' ),
			'post_excerpt' => Site_Manager_Helpers::arg( $args, 'caption' ),
			'post_content' => Site_Manager_Helpers::arg( $args, 'description' ),
			'post_parent'  => Site_Manager_Helpers::arg( $args, 'parent' ),
		), function ( $v ) {
			return $v !== null;
		} );
		if ( $update ) {
			$result = wp_update_post( wp_slash( array( 'ID' => $post->ID ) + $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $args['alt'] ) ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $args['alt'] ) ) );
		}
		return Site_Manager_Helpers::attachment_detail( $post->ID );
	}

	public function media_delete( array $args ) {
		$post = Site_Manager_Helpers::require_post( (int) $args['id'], 'attachment' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$detail = Site_Manager_Helpers::attachment_detail( $post );
		if ( Site_Manager_Helpers::bool( $args, 'dry_run' ) ) {
			return array( 'dry_run' => true, 'would_delete' => $detail );
		}
		if ( ! wp_delete_attachment( $post->ID, true ) ) {
			return new WP_Error( 'delete_failed', 'Attachment could not be deleted.' );
		}
		return array( 'deleted' => $detail );
	}

	public function media_regenerate( array $args ) {
		Site_Manager_Helpers::load_admin_includes();
		$out = array();
		foreach ( (array) $args['ids'] as $id ) {
			$id   = (int) $id;
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				$out[] = array( 'id' => $id, 'error' => 'file missing' );
				continue;
			}
			$meta = wp_generate_attachment_metadata( $id, $file );
			if ( is_wp_error( $meta ) || empty( $meta ) ) {
				$out[] = array( 'id' => $id, 'error' => is_wp_error( $meta ) ? $meta->get_error_message() : 'not an image' );
				continue;
			}
			wp_update_attachment_metadata( $id, $meta );
			$out[] = array( 'id' => $id, 'sizes' => array_keys( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) );
		}
		return $out;
	}

	public function image_sizes_list() {
		return wp_get_registered_image_subsizes();
	}
}

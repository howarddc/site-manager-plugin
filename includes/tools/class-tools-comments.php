<?php
/**
 * MCP tools: comments and moderation.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Comments {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'comments', __( 'Comments', 'site-manager' ), __( 'Read, reply to and moderate comments.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'comments_list', array(
			'category'     => 'comments',
			'description'  => 'List comments by status (hold = pending, approve, spam, trash, all), post, type or search term. Includes counts per status.',
			'input_schema' => $s::obj( array(
				'status'   => $s::enum( array( 'all', 'hold', 'approve', 'spam', 'trash' ), '', 'all' ),
				'post_id'  => $s::int(),
				'type'     => $s::str( 'comment, pingback, trackback, or a custom type (e.g. review).' ),
				'search'   => $s::str(),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 20, 100 ),
			) ),
			'handler'      => array( $this, 'comments_list' ),
		) );

		$r->register( 'comment_get', array(
			'category'     => 'comments',
			'description'  => 'Get a single comment with its meta.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'comment_get' ),
		) );

		$r->register( 'comment_create', array(
			'category'     => 'comments',
			'description'  => 'Post a comment or reply as the connected admin. Approved immediately.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'post_id' => $s::int(),
				'content' => $s::str(),
				'parent'  => $s::int( 'Comment ID to reply to.' ),
			), array( 'post_id', 'content' ) ),
			'handler'      => array( $this, 'comment_create' ),
		) );

		$r->register( 'comment_update', array(
			'category'     => 'comments',
			'description'  => 'Edit a comment\'s content or author fields, or moderate it by setting status (approve, hold, spam, trash).',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'           => $s::int(),
				'content'      => $s::str(),
				'status'       => $s::enum( array( 'approve', 'hold', 'spam', 'trash' ) ),
				'author'       => $s::str(),
				'author_email' => $s::str(),
				'author_url'   => $s::str(),
			), array( 'id' ) ),
			'handler'      => array( $this, 'comment_update' ),
		) );

		$r->register( 'comments_moderate', array(
			'category'     => 'comments',
			'description'  => 'Set the status of many comments at once (approve, hold, spam, trash).',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'ids'    => $s::arr( 'integer' ),
				'status' => $s::enum( array( 'approve', 'hold', 'spam', 'trash' ) ),
			), array( 'ids', 'status' ) ),
			'handler'      => array( $this, 'comments_moderate' ),
		) );

		$r->register( 'comment_delete', array(
			'category'     => 'comments',
			'description'  => 'Permanently delete a comment (use comment_update status=trash to trash it instead).',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'comment_delete' ),
		) );
	}

	public function comments_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args );
		$q        = array(
			'status'  => Site_Manager_Helpers::arg( $args, 'status', 'all' ),
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);
		if ( ! empty( $args['post_id'] ) ) {
			$q['post_id'] = (int) $args['post_id'];
		}
		if ( ! empty( $args['type'] ) ) {
			$q['type'] = (string) $args['type'];
		}
		if ( ! empty( $args['search'] ) ) {
			$q['search'] = (string) $args['search'];
		}
		$query  = new WP_Comment_Query();
		$items  = $query->query( $q );
		$q['count'] = true;
		unset( $q['number'], $q['offset'] );
		$total  = ( new WP_Comment_Query() )->query( $q );
		$result = Site_Manager_Helpers::paged( array_map( array( 'Site_Manager_Helpers', 'comment_detail' ), $items ), $total, $page, $per_page );
		$result['counts'] = wp_count_comments( isset( $q['post_id'] ) ? $q['post_id'] : 0 );
		return $result;
	}

	public function comment_get( array $args ) {
		$detail = Site_Manager_Helpers::comment_detail( (int) $args['id'] );
		if ( ! $detail ) {
			return new WP_Error( 'not_found', 'Comment not found.' );
		}
		$detail['meta'] = Site_Manager_Helpers::meta_for( 'comment', $detail['id'], true );
		return $detail;
	}

	public function comment_create( array $args ) {
		$user = wp_get_current_user();
		if ( ! get_post( (int) $args['post_id'] ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		$id = wp_insert_comment( wp_slash( array(
			'comment_post_ID'      => (int) $args['post_id'],
			'comment_parent'       => (int) Site_Manager_Helpers::arg( $args, 'parent', 0 ),
			'comment_content'      => (string) $args['content'],
			'user_id'              => $user->ID,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_url,
			'comment_approved'     => 1,
		) ) );
		return $id ? Site_Manager_Helpers::comment_detail( $id ) : new WP_Error( 'insert_failed', 'Comment could not be created.' );
	}

	public function comment_update( array $args ) {
		$comment = get_comment( (int) $args['id'] );
		if ( ! $comment ) {
			return new WP_Error( 'not_found', 'Comment not found.' );
		}
		$fields = array_filter( array(
			'comment_content'      => Site_Manager_Helpers::arg( $args, 'content' ),
			'comment_author'       => Site_Manager_Helpers::arg( $args, 'author' ),
			'comment_author_email' => Site_Manager_Helpers::arg( $args, 'author_email' ),
			'comment_author_url'   => Site_Manager_Helpers::arg( $args, 'author_url' ),
		), function ( $v ) {
			return $v !== null;
		} );
		if ( $fields ) {
			$result = wp_update_comment( wp_slash( array( 'comment_ID' => $comment->comment_ID ) + $fields ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( ! empty( $args['status'] ) ) {
			wp_set_comment_status( $comment->comment_ID, (string) $args['status'] );
		}
		return Site_Manager_Helpers::comment_detail( $comment->comment_ID );
	}

	public function comments_moderate( array $args ) {
		$status = (string) $args['status'];
		$out    = array();
		foreach ( (array) $args['ids'] as $id ) {
			$out[ (int) $id ] = (bool) wp_set_comment_status( (int) $id, $status );
		}
		return array( 'status' => $status, 'results' => $out );
	}

	public function comment_delete( array $args ) {
		$detail = Site_Manager_Helpers::comment_detail( (int) $args['id'] );
		if ( ! $detail ) {
			return new WP_Error( 'not_found', 'Comment not found.' );
		}
		return wp_delete_comment( $detail['id'], true ) ? array( 'deleted' => $detail ) : new WP_Error( 'delete_failed', 'Comment could not be deleted.' );
	}
}

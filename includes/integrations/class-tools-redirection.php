<?php
/**
 * MCP tools: Redirection (by John Godley).
 *
 * Uses the plugin's own models (Red_Item, Red_Group, Red_404_Log,
 * Red_Redirect_Log) so validation, caching and flushing match the admin UI.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Redirection {

	public static function is_active() {
		return class_exists( 'Red_Item' ) && class_exists( 'Red_Group' );
	}

	public static function version() {
		return defined( 'REDIRECTION_VERSION' ) ? REDIRECTION_VERSION : true;
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'redirection', __( 'Redirection', 'site-manager' ), __( 'Redirects, redirect groups, 404 log and redirect log.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'The Redirection plugin is active: manage 301/302/307/308/410 redirects with redirection_*; check redirection_404s for broken URLs that need redirects (grouped by URL), and redirection_test_url to verify a redirect works.';
			return $lines;
		} );

		$redirect_props = array(
			'source'                => $s::str( 'Source path, e.g. "/old-page" (or a regex when regex=true, e.g. "^/blog/(.*)").' ),
			'target'                => $s::str( 'Target URL or path, e.g. "/new-page" or "https://example.com/$1".' ),
			'code'                  => $s::int( 'HTTP code: 301 (default), 302, 303, 304, 307, 308, or 410/404 for an error response.', array( 'enum' => array( 301, 302, 303, 304, 307, 308, 400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504 ) ) ),
			'regex'                 => $s::bool( 'Treat source as a regular expression.' ),
			'ignore_case'           => $s::bool(),
			'ignore_trailing_slash' => $s::bool(),
			'query'                 => $s::enum( array( 'exact', 'ignore', 'pass' ), 'Query-string handling: exact match, ignore, or ignore and pass to target.' ),
			'title'                 => $s::str( 'Optional note.' ),
			'group_id'              => $s::int( 'Redirect group (default: first group, "Redirections").' ),
			'enabled'               => $s::bool(),
		);

		$r->register( 'redirection_setup', array(
			'category'     => 'redirection',
			'description'  => 'Report whether Redirection\'s database is installed and up to date. With run=true, performs the first-time install or pending database upgrade (same as the setup wizard in Tools → Redirection).',
			'input_schema' => $s::obj( array(
				'run' => $s::bool( 'Install / upgrade now.', false ),
			) ),
			'writes'       => true,
			'handler'      => array( $this, 'setup' ),
		) );

		$r->register( 'redirection_list', array(
			'category'     => 'redirection',
			'description'  => 'List redirects with source, target, code, hit count and last access. Filter by source URL, target, title, group or status.',
			'input_schema' => $s::obj( array(
				'source'   => $s::str( 'Source URL contains.' ),
				'target'   => $s::str( 'Target contains.' ),
				'title'    => $s::str(),
				'group_id' => $s::int(),
				'status'   => $s::enum( array( 'enabled', 'disabled' ) ),
				'orderby'  => $s::enum( array( 'source', 'last_count', 'last_access', 'position' ) ),
				'direction' => $s::enum( array( 'asc', 'desc' ), '', 'desc' ),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 50, 200 ),
			) ),
			'handler'      => array( $this, 'redirects_list' ),
		) );

		$r->register( 'redirection_create', array(
			'category'     => 'redirection',
			'description'  => 'Create a redirect. For a 410 Gone (or other error) pass code=410 and no target.',
			'writes'       => true,
			'input_schema' => $s::obj( $redirect_props, array( 'source' ) ),
			'handler'      => array( $this, 'redirect_create' ),
		) );

		$r->register( 'redirection_bulk_create', array(
			'category'     => 'redirection',
			'description'  => 'Create many redirects at once, e.g. after a site migration: [{"source":"/a","target":"/b"},…]. Each item accepts the same fields as redirection_create.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'redirects' => $s::arr( 'object' ) ), array( 'redirects' ) ),
			'handler'      => array( $this, 'redirect_bulk_create' ),
		) );

		$r->register( 'redirection_update', array(
			'category'     => 'redirection',
			'description'  => 'Update a redirect. Only the fields you pass change; enabled=false disables it.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'id' => $s::int() ), $redirect_props ), array( 'id' ) ),
			'handler'      => array( $this, 'redirect_update' ),
		) );

		$r->register( 'redirection_delete', array(
			'category'     => 'redirection',
			'description'  => 'Delete redirects by ID.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'ids' => $s::arr( 'integer' ) ), array( 'ids' ) ),
			'handler'      => array( $this, 'redirect_delete' ),
		) );

		$r->register( 'redirection_groups', array(
			'category'     => 'redirection',
			'description'  => 'List redirect groups, or create one by passing name.',
			'input_schema' => $s::obj( array(
				'name' => $s::str( 'Create a group with this name.' ),
			) ),
			'handler'      => array( $this, 'groups' ),
		) );

		$r->register( 'redirection_404s', array(
			'category'     => 'redirection',
			'description'  => 'Read the 404 log. By default grouped by URL with hit counts (most frequent first) — the best list of URLs that need redirects. Set grouped=false for individual hits with referrer and user agent.',
			'input_schema' => $s::obj( array(
				'grouped'  => $s::bool( '', true ),
				'url'      => $s::str( 'URL contains.' ),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 50, 200 ),
			) ),
			'handler'      => array( $this, 'log_404s' ),
		) );

		$r->register( 'redirection_404s_clear', array(
			'category'     => 'redirection',
			'description'  => 'Delete 404 log entries — all of them, or only those whose URL contains url.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'url' => $s::str() ) ),
			'handler'      => array( $this, 'log_404s_clear' ),
		) );

		$r->register( 'redirection_log', array(
			'category'     => 'redirection',
			'description'  => 'Read the redirect log (redirects that fired), newest first.',
			'input_schema' => $s::obj( array(
				'url'      => $s::str( 'URL contains.' ),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 50, 200 ),
			) ),
			'handler'      => array( $this, 'redirect_log' ),
		) );

		$r->register( 'redirection_test_url', array(
			'category'     => 'redirection',
			'description'  => 'Request a URL on this site without following redirects and report the status code and Location header — verifies a redirect actually works.',
			'input_schema' => $s::obj( array( 'url' => $s::str( 'Path ("/old-page") or full URL on this site.' ) ), array( 'url' ) ),
			'handler'      => array( $this, 'test_url' ),
		) );
	}

	/** Database status from Redirection's own checker (null on versions without it). */
	private static function db_status() {
		if ( ! class_exists( '\\Redirection\\Database\\Status' ) ) {
			return null;
		}
		$status = new \Redirection\Database\Status();
		return array(
			'needs_install' => $status->needs_installing(),
			'needs_update'  => $status->needs_updating(),
		);
	}

	/** WP_Error when the plugin's tables aren't ready, true otherwise. */
	private static function ready() {
		$status = self::db_status();
		if ( $status && ( $status['needs_install'] || $status['needs_update'] ) ) {
			return new WP_Error( 'redirection_not_ready', 'Redirection\'s database needs ' . ( $status['needs_install'] ? 'installing' : 'upgrading' ) . '. Run redirection_setup with run=true (or finish setup in Tools → Redirection).' );
		}
		return true;
	}

	public function setup( array $args ) {
		$before = self::db_status();
		if ( $before === null ) {
			return new WP_Error( 'not_supported', 'This Redirection version has no database status API; use Tools → Redirection.' );
		}
		if ( ! Site_Manager_Helpers::bool( $args, 'run' ) || ( ! $before['needs_install'] && ! $before['needs_update'] ) ) {
			return array( 'ready' => ! $before['needs_install'] && ! $before['needs_update'] ) + $before;
		}
		// Drive the staged installer the same way the setup wizard does.
		$last = null;
		for ( $i = 0; $i < 50; $i++ ) {
			$response = rest_do_request( new WP_REST_Request( 'POST', '/redirection/v1/plugin/data' ) );
			$last     = $response->get_data();
			if ( $response->is_error() || ! empty( $last['result'] ) && $last['result'] === 'error' ) {
				return new WP_Error( 'setup_failed', 'Redirection setup failed.', array( 'details' => $last ) );
			}
			if ( empty( $last['inProgress'] ) ) {
				break;
			}
		}
		if ( $before['needs_install'] ) {
			rest_do_request( new WP_REST_Request( 'POST', '/redirection/v1/plugin/finish' ) );
		}
		$after = self::db_status();
		return array( 'ready' => ! $after['needs_install'] && ! $after['needs_update'], 'status' => $last, 'groups' => $this->groups( array() ) );
	}

	private static function default_group() {
		$groups = Red_Group::get_all();
		return $groups ? (int) $groups[0]['id'] : 1;
	}

	/**
	 * Convert friendly args into Red_Item details, starting from $base
	 * (an existing redirect's to_json()) for updates.
	 */
	private static function details( array $args, array $base = array() ) {
		$d = $base ? array(
			'url'         => $base['url'],
			'match_data'  => $base['match_data'],
			'action_code' => $base['action_code'],
			'action_type' => $base['action_type'],
			'action_data' => $base['action_data'],
			'match_type'  => $base['match_type'],
			'title'       => $base['title'],
			'regex'       => $base['regex'] ? 1 : 0,
			'group_id'    => $base['group_id'],
			'position'    => $base['position'],
		) : array(
			'match_type'  => 'url',
			'action_type' => 'url',
			'action_code' => 301,
			'action_data' => array( 'url' => '' ),
			'group_id'    => self::default_group(),
			'regex'       => 0,
			'match_data'  => array(),
		);
		if ( isset( $args['source'] ) ) {
			$d['url'] = (string) $args['source'];
		}
		if ( isset( $args['target'] ) ) {
			$d['action_data'] = array( 'url' => (string) $args['target'] );
		}
		if ( isset( $args['code'] ) ) {
			$code             = (int) $args['code'];
			$d['action_code'] = $code;
			$d['action_type'] = $code >= 400 ? 'error' : 'url';
		}
		if ( isset( $args['title'] ) ) {
			$d['title'] = (string) $args['title'];
		}
		if ( isset( $args['group_id'] ) ) {
			$d['group_id'] = (int) $args['group_id'];
		}

		$source = isset( $d['match_data']['source'] ) ? (array) $d['match_data']['source'] : array();
		if ( isset( $args['regex'] ) ) {
			$d['regex']           = Site_Manager_Helpers::bool( $args, 'regex' ) ? 1 : 0;
			$source['flag_regex'] = (bool) $d['regex'];
		}
		if ( isset( $args['ignore_case'] ) ) {
			$source['flag_case'] = Site_Manager_Helpers::bool( $args, 'ignore_case' );
		}
		if ( isset( $args['ignore_trailing_slash'] ) ) {
			$source['flag_trailing'] = Site_Manager_Helpers::bool( $args, 'ignore_trailing_slash' );
		}
		if ( isset( $args['query'] ) ) {
			$source['flag_query'] = (string) $args['query'];
		}
		$d['match_data']           = is_array( $d['match_data'] ) ? $d['match_data'] : array();
		$d['match_data']['source'] = $source;
		if ( isset( $args['enabled'] ) ) {
			$d['status'] = Site_Manager_Helpers::bool( $args, 'enabled' ) ? 'enabled' : 'disabled';
		}
		return $d;
	}

	private static function redirect_out( $item ) {
		$json = $item instanceof Red_Item ? $item->to_json() : (array) $item;
		$data = isset( $json['action_data'] ) ? $json['action_data'] : null;
		return array(
			'id'          => (int) $json['id'],
			'source'      => $json['url'],
			'target'      => is_array( $data ) && isset( $data['url'] ) ? $data['url'] : $data,
			'code'        => (int) $json['action_code'],
			'action_type' => $json['action_type'],
			'match_type'  => $json['match_type'],
			'regex'       => (bool) $json['regex'],
			'flags'       => isset( $json['match_data']['source'] ) ? $json['match_data']['source'] : null,
			'title'       => $json['title'],
			'group_id'    => (int) $json['group_id'],
			'enabled'     => (bool) $json['enabled'],
			'hits'        => (int) $json['hits'],
			'last_access' => $json['last_access'],
		);
	}

	public function redirects_list( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 50, 200 );
		$filter   = array();
		foreach ( array( 'source' => 'url', 'target' => 'target', 'title' => 'title', 'group_id' => 'group', 'status' => 'status' ) as $arg => $key ) {
			if ( isset( $args[ $arg ] ) && $args[ $arg ] !== '' ) {
				$filter[ $key ] = $args[ $arg ];
			}
		}
		$result = Red_Item::get_filtered( array(
			'filterBy'  => $filter,
			'orderby'   => Site_Manager_Helpers::arg( $args, 'orderby', 'id' ),
			'direction' => Site_Manager_Helpers::arg( $args, 'direction', 'desc' ),
			'page'      => $page - 1,
			'per_page'  => $per_page,
		) );
		return Site_Manager_Helpers::paged( array_map( array( __CLASS__, 'redirect_out' ), $result['items'] ), $result['total'], $page, $per_page );
	}

	public function redirect_create( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$item = Red_Item::create( self::details( $args ) );
		return is_wp_error( $item ) ? $item : self::redirect_out( $item );
	}

	public function redirect_bulk_create( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$results = array();
		foreach ( (array) $args['redirects'] as $row ) {
			$row  = (array) $row;
			$item = Red_Item::create( self::details( $row ) );
			$results[] = is_wp_error( $item )
				? array( 'source' => isset( $row['source'] ) ? $row['source'] : null, 'error' => $item->get_error_message() )
				: array( 'source' => $row['source'], 'id' => $item->get_id() );
		}
		return array( 'created' => count( array_filter( $results, function ( $r ) {
			return isset( $r['id'] );
		} ) ), 'results' => $results );
	}

	public function redirect_update( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$item = Red_Item::get_by_id( (int) $args['id'] );
		if ( ! $item ) {
			return new WP_Error( 'not_found', 'Redirect not found.' );
		}
		$details = self::details( $args, $item->to_json() );
		unset( $details['status'] );
		$result = $item->update( $details );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$item = Red_Item::get_by_id( (int) $args['id'] );
		if ( isset( $args['enabled'] ) ) {
			Site_Manager_Helpers::bool( $args, 'enabled' ) ? $item->enable() : $item->disable();
			$item = Red_Item::get_by_id( (int) $args['id'] );
		}
		return self::redirect_out( $item );
	}

	public function redirect_delete( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$deleted = array();
		foreach ( (array) $args['ids'] as $id ) {
			$item = Red_Item::get_by_id( (int) $id );
			if ( $item ) {
				$item->delete();
				$deleted[] = (int) $id;
			}
		}
		return array( 'deleted' => $deleted );
	}

	public function groups( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! empty( $args['name'] ) ) {
			$group = Red_Group::create( sanitize_text_field( $args['name'] ), 1 );
			if ( ! $group ) {
				return new WP_Error( 'create_failed', 'Group could not be created.' );
			}
		}
		return array_map( function ( $g ) {
			return array(
				'id'        => (int) $g['id'],
				'name'      => $g['name'],
				'redirects' => (int) $g['redirects'],
				'enabled'   => (bool) $g['enabled'],
				'module'    => (int) $g['module_id'] === 1 ? 'WordPress' : ( (int) $g['module_id'] === 2 ? 'Apache' : 'Nginx' ),
			);
		}, Red_Group::get_all() );
	}

	private static function log_params( array $args ) {
		$params = array(
			'page'      => Site_Manager_Helpers::page( $args ) - 1,
			'per_page'  => Site_Manager_Helpers::per_page( $args, 50, 200 ),
			'direction' => 'desc',
		);
		if ( ! empty( $args['url'] ) ) {
			$params['filterBy'] = array( 'url' => (string) $args['url'] );
		}
		return $params;
	}

	public function log_404s( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$params = self::log_params( $args );
		$result = Site_Manager_Helpers::bool( $args, 'grouped', true )
			? Red_404_Log::get_grouped( 'url', $params )
			: Red_404_Log::get_filtered( $params );
		$items  = array_map( function ( $row ) {
			$row = (array) $row;
			unset( $row['id'] );
			return $row;
		}, (array) $result['items'] );
		return Site_Manager_Helpers::paged( $items, (int) $result['total'], $params['page'] + 1, $params['per_page'] );
	}

	public function log_404s_clear( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$params = ! empty( $args['url'] ) ? array( 'filterBy' => array( 'url' => (string) $args['url'] ) ) : array();
		return array( 'deleted' => (int) Red_404_Log::delete_all( $params ) );
	}

	public function redirect_log( array $args ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$params = self::log_params( $args );
		$result = Red_Redirect_Log::get_filtered( $params );
		return Site_Manager_Helpers::paged( (array) $result['items'], (int) $result['total'], $params['page'] + 1, $params['per_page'] );
	}

	public function test_url( array $args ) {
		$url = (string) $args['url'];
		if ( strpos( $url, '/' ) === 0 ) {
			$url = home_url( $url );
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			return new WP_Error( 'external', 'Only URLs on this site can be tested.' );
		}
		$response = wp_remote_head( $url, array( 'redirection' => 0, 'timeout' => 15, 'sslverify' => false ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		return array(
			'url'           => $url,
			'status'        => $code,
			'location'      => wp_remote_retrieve_header( $response, 'location' ) ?: null,
			'redirected_by' => wp_remote_retrieve_header( $response, 'x-redirect-by' ) ?: null,
		);
	}
}

<?php
/**
 * MCP server REST endpoint.
 *
 * Speaks the MCP Streamable HTTP transport (JSON-RPC 2.0 over HTTP POST,
 * JSON responses, no SSE stream).
 *
 * Endpoint: POST /wp-json/site-manager/v1/mcp
 *
 * Auth, in order:
 *   1. OAuth 2.1 Bearer token issued by Site_Manager_OAuth.
 *   2. WordPress Application Password (HTTP Basic), handled by core.
 *
 * Only administrators (manage_options) may connect. The capability is
 * re-checked on every request, so demoting a user immediately cuts off
 * every token they hold.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Server {

	const NAMESPACE_V1     = 'site-manager/v1';
	const ROUTE            = '/mcp';
	const PROTOCOL_VERSION = '2025-06-18';
	const SERVER_NAME      = 'site-manager';
	const CAPABILITY       = 'manage_options';

	const SUPPORTED_PROTOCOLS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/** @var array|null Token row of the authenticated OAuth request. */
	private static $token = null;

	/** @var bool Whether a Bearer token was presented but rejected. */
	private static $bad_token = false;

	public function __construct() {
		add_filter( 'determine_current_user', array( $this, 'authenticate_bearer' ), 30 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public static function endpoint_url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Canonical resource identifier (RFC 8707 / RFC 9728) — advertised in the
	 * protected-resource metadata.
	 */
	public static function resource_identifier() {
		return untrailingslashit( self::endpoint_url() );
	}

	/** Label for the current caller, used in the activity log. */
	public static function client_label() {
		if ( self::$token ) {
			$client = Site_Manager_OAuth_Store::get_client( self::$token['client_id'] );
			return $client ? 'oauth:' . $client['client_name'] : 'oauth';
		}
		$uuid = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		if ( $uuid ) {
			$item = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
			return 'app-password:' . ( $item ? $item['name'] : $uuid );
		}
		return 'app-password';
	}

	public static function authorization_header() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return (string) $_SERVER[ $key ];
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $k => $v ) {
				if ( strcasecmp( $k, 'Authorization' ) === 0 ) {
					return (string) $v;
				}
			}
		}
		return '';
	}

	public static function is_mcp_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( strpos( $uri, '/' . self::NAMESPACE_V1 . self::ROUTE ) !== false ) {
			return true;
		}
		// Plain permalinks: ?rest_route=/site-manager/v1/mcp
		return isset( $_GET['rest_route'] ) && strpos( (string) $_GET['rest_route'], '/' . self::NAMESPACE_V1 . self::ROUTE ) === 0;
	}

	/**
	 * Resolve an OAuth Bearer token to a user. Scoped to the MCP route so
	 * tokens can't be replayed against the rest of the REST API.
	 */
	public function authenticate_bearer( $user_id ) {
		if ( $user_id || ! self::is_mcp_request() ) {
			return $user_id;
		}
		$auth = self::authorization_header();
		if ( stripos( $auth, 'Bearer ' ) !== 0 ) {
			return $user_id;
		}
		$row = Site_Manager_OAuth_Store::find_token( Site_Manager_OAuth_Store::TYPE_ACCESS, trim( substr( $auth, 7 ) ) );
		if ( ! $row ) {
			self::$bad_token = true;
			return $user_id;
		}
		self::$token = $row;
		Site_Manager_OAuth_Store::touch( $row['id'] );
		return (int) $row['user_id'];
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE_V1, self::ROUTE, array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_post' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
		) );
	}

	public function permission_check() {
		if ( self::$bad_token ) {
			return $this->unauthorized( 'invalid_token', 'The access token is invalid or expired.' );
		}
		if ( ! is_user_logged_in() ) {
			return $this->unauthorized( 'unauthorized', 'Authentication required.' );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error( 'forbidden', 'Site Manager is available to administrators only.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * 401 with a WWW-Authenticate challenge pointing at our protected-resource
	 * metadata so MCP clients can discover the authorization server.
	 */
	private function unauthorized( $code, $message ) {
		$challenge = sprintf(
			'Bearer realm="site-manager", resource_metadata="%s"',
			esc_url_raw( Site_Manager_OAuth_Discovery::protected_resource_url() )
		);
		add_filter( 'rest_post_dispatch', function ( $result ) use ( $challenge ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$result->header( 'WWW-Authenticate', $challenge );
			}
			return $result;
		} );
		return new WP_Error( $code, $message, array( 'status' => 401 ) );
	}

	/**
	 * GET: we don't offer a server-initiated SSE stream, which the spec
	 * signals with 405. Plain browser GETs get a small info document.
	 */
	public function handle_get( WP_REST_Request $request ) {
		$accept = (string) $request->get_header( 'accept' );
		if ( stripos( $accept, 'text/event-stream' ) !== false ) {
			return new WP_REST_Response( null, 405 );
		}
		return rest_ensure_response( array(
			'server'          => self::SERVER_NAME,
			'version'         => SITE_MANAGER_VERSION,
			'protocolVersion' => self::PROTOCOL_VERSION,
			'transport'       => 'streamable-http',
			'tools'           => count( Site_Manager_Registry::instance()->available() ),
		) );
	}

	/** Stateless server: no sessions to terminate. */
	public function handle_delete() {
		return new WP_REST_Response( null, 405 );
	}

	public function handle_post( WP_REST_Request $request ) {
		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return $this->rpc_error( null, -32700, 'Parse error: invalid JSON.' );
		}

		// JSON-RPC batch.
		if ( $body && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			$responses = array();
			foreach ( $body as $message ) {
				$response = is_array( $message ) ? $this->dispatch( $message ) : $this->rpc_payload( null, -32600, 'Invalid request.' );
				if ( $response !== null ) {
					$responses[] = $response;
				}
			}
			return $responses ? rest_ensure_response( $responses ) : new WP_REST_Response( null, 202 );
		}

		$response = $this->dispatch( $body );
		return $response === null ? new WP_REST_Response( null, 202 ) : rest_ensure_response( $response );
	}

	/**
	 * Handle one JSON-RPC message. Returns the response payload, or null for
	 * notifications and client responses.
	 */
	private function dispatch( array $msg ) {
		$is_notification = ! array_key_exists( 'id', $msg );
		$id              = $is_notification ? null : $msg['id'];
		$method          = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params          = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( $method === '' ) {
			return $is_notification ? null : $this->rpc_payload( $id, -32600, 'Invalid request.' );
		}
		if ( $is_notification ) {
			return null;
		}

		try {
			switch ( $method ) {
				case 'initialize':
					$result = $this->method_initialize( $params );
					break;
				case 'ping':
					$result = new stdClass();
					break;
				case 'tools/list':
					$result = $this->method_tools_list();
					break;
				case 'tools/call':
					$result = $this->method_tools_call( $params );
					break;
				case 'resources/list':
					$result = array( 'resources' => array() );
					break;
				case 'resources/templates/list':
					$result = array( 'resourceTemplates' => array() );
					break;
				case 'prompts/list':
					$result = array( 'prompts' => array() );
					break;
				default:
					return $this->rpc_payload( $id, -32601, sprintf( 'Method not found: %s', $method ) );
			}
		} catch ( Throwable $e ) {
			return $this->rpc_payload( $id, -32603, 'Internal error: ' . $e->getMessage() );
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private function method_initialize( array $params ) {
		$client = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$proto  = in_array( $client, self::SUPPORTED_PROTOCOLS, true ) ? $client : self::PROTOCOL_VERSION;

		return array(
			'protocolVersion' => $proto,
			'capabilities'    => array(
				'tools' => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => self::SERVER_NAME,
				'title'   => 'Site Manager — ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'version' => SITE_MANAGER_VERSION,
			),
			'instructions'    => $this->instructions(),
		);
	}

	private function instructions() {
		$lines = array(
			sprintf( 'You are managing the WordPress site "%s" (%s) as administrator.', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url() ),
			'Start with site_info to learn the WordPress version, active theme, plugins, post types and taxonomies.',
			'The post_* tools work for every post type, including pages, custom post types, reusable blocks, templates and navigation menus. Use post_types_list and meta_keys_list to discover custom post types and custom fields.',
			'post_create / post_update accept terms, meta, acf and yoast objects so one call can set everything on a post.',
			'Prefer Gutenberg block markup for content on block-editor sites; use post_blocks_get to inspect existing structure.',
			'rest_request can call any REST route on the site (including routes added by other plugins) as the connected admin; use rest_routes_list to discover them.',
			'Destructive tools accept dry_run where noted. Confirm with the user before deleting content, changing users or roles, or activating/deactivating plugins and themes.',
		);
		/**
		 * Lines of guidance sent to the client at initialize. Integrations
		 * append a line describing their tools.
		 *
		 * @param string[] $lines
		 */
		$lines = apply_filters( 'site_manager_instructions', $lines );
		return implode( "\n", $lines );
	}

	private function method_tools_list() {
		$tools = array();
		foreach ( Site_Manager_Registry::instance()->available() as $name => $def ) {
			$schema = $def['input_schema'];
			// An empty PHP array encodes as `[]`; JSON Schema needs `{}`.
			if ( empty( $schema['properties'] ) ) {
				$schema['properties'] = new stdClass();
			}
			$tools[] = array(
				'name'        => $name,
				'description' => $def['description'],
				'inputSchema' => $schema,
				'annotations' => array(
					'readOnlyHint'    => ! $def['writes'],
					'destructiveHint' => (bool) $def['destructive'],
					'idempotentHint'  => ! $def['writes'],
					'openWorldHint'   => (bool) $def['open_world'],
				),
			);
		}
		return array( 'tools' => $tools );
	}

	private function method_tools_call( array $params ) {
		$name     = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args     = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$registry = Site_Manager_Registry::instance();
		$tool     = $registry->get( $name );

		if ( ! $tool ) {
			return $this->tool_error( sprintf( 'Unknown tool: %s', $name ) );
		}
		if ( ! $registry->is_available( $name ) ) {
			return $this->tool_error( sprintf( 'Tool "%s" is disabled in Settings → Site Manager.', $name ) );
		}
		if ( ! current_user_can( $tool['capability'] ) ) {
			return $this->tool_error( sprintf( 'Capability "%s" required for tool "%s".', $tool['capability'], $name ) );
		}

		$started = microtime( true );
		try {
			$result = call_user_func( $tool['handler'], $args );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'exception', $e->getMessage() . ' (' . wp_basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
		}
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		$is_error = is_wp_error( $result );
		if ( $tool['writes'] || $is_error || Site_Manager_Settings::get( 'log_reads' ) ) {
			Site_Manager_Log::record(
				$name,
				$args,
				$tool['writes'],
				$is_error ? 'error' : 'ok',
				$is_error ? $result->get_error_message() : null,
				$ms,
				self::client_label()
			);
		}

		if ( $tool['writes'] ) {
			Site_Manager_Activity::log( array(
				'category'    => 'mcp',
				'action'      => $is_error ? 'tool_failed' : 'tool_run',
				'severity'    => $tool['gate'] ? 'critical' : ( $tool['destructive'] ? 'warning' : 'info' ),
				'object_type' => 'mcp_tool',
				'object_id'   => $name,
				'object_name' => $name,
				'message'     => sprintf( 'MCP tool %s %s via %s.', $name, $is_error ? 'failed' : 'ran', self::client_label() ),
				'details'     => array(
					'arguments' => Site_Manager_Log::redact( $args ),
					'error'     => $is_error ? $result->get_error_message() : null,
				),
			) );
		}

		if ( $is_error ) {
			$message = $result->get_error_message();
			$data    = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['details'] ) ) {
				$message .= "\n" . wp_json_encode( $data['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			}
			return $this->tool_error( $message );
		}
		return $this->tool_success( $result );
	}

	private function tool_success( $payload ) {
		$text = is_string( $payload ) ? $payload : wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => (string) $text ),
			),
			'isError' => false,
		);
	}

	private function tool_error( $message ) {
		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => 'Error: ' . $message ),
			),
			'isError' => true,
		);
	}

	private function rpc_payload( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	private function rpc_error( $id, $code, $message ) {
		return rest_ensure_response( $this->rpc_payload( $id, $code, $message ) );
	}
}

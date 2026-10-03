<?php
/**
 * OAuth discovery — serves the .well-known metadata documents so MCP
 * clients can find our authorization endpoints from the server URL alone.
 *
 *   /.well-known/oauth-authorization-server   (RFC 8414)
 *   /.well-known/oauth-protected-resource     (RFC 9728)
 *
 * Both are also answered with any path suffix (e.g.
 * /.well-known/oauth-protected-resource/wp-json/site-manager/v1/mcp), since
 * clients differ in which form they request.
 *
 * Note: when WordPress lives in a subdirectory, requests to the domain-root
 * /.well-known/ never reach WordPress. Clients fall back to the
 * WWW-Authenticate resource_metadata URL, which points inside the install.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_OAuth_Discovery {

	const AS_PATH = '.well-known/oauth-authorization-server';
	const PR_PATH = '.well-known/oauth-protected-resource';

	public function __construct() {
		add_action( 'parse_request', array( $this, 'maybe_handle' ), 1 );
	}

	public static function protected_resource_url() {
		return home_url( '/' . self::PR_PATH );
	}

	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	public static function endpoint( $name ) {
		return rest_url( Site_Manager_Server::NAMESPACE_V1 . '/oauth/' . $name );
	}

	public function maybe_handle() {
		$path      = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( $home_path !== '' && strpos( $path, $home_path . '/' ) === 0 ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		}

		if ( $path === self::AS_PATH || strpos( $path, self::AS_PATH . '/' ) === 0 ) {
			$this->emit_json( array(
				'issuer'                                => self::issuer(),
				'authorization_endpoint'                => self::endpoint( 'authorize' ),
				'token_endpoint'                        => self::endpoint( 'token' ),
				'registration_endpoint'                 => self::endpoint( 'register' ),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'token_endpoint_auth_methods_supported' => array( 'none' ),
				'scopes_supported'                      => array( Site_Manager_OAuth::SCOPE ),
			) );
		}

		if ( $path === self::PR_PATH || strpos( $path, self::PR_PATH . '/' ) === 0 ) {
			$this->emit_json( array(
				'resource'                 => Site_Manager_Server::resource_identifier(),
				'authorization_servers'    => array( self::issuer() ),
				'bearer_methods_supported' => array( 'header' ),
				'scopes_supported'         => array( Site_Manager_OAuth::SCOPE ),
				'resource_name'            => 'Site Manager — ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			) );
		}
	}

	private function emit_json( array $body ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version' );
		echo wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
		exit;
	}
}

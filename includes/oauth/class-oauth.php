<?php
/**
 * OAuth 2.1 authorization server with Dynamic Client Registration.
 *
 *   POST /wp-json/site-manager/v1/oauth/register   (RFC 7591, public clients)
 *   GET  /wp-json/site-manager/v1/oauth/authorize  (consent screen)
 *   POST /wp-json/site-manager/v1/oauth/authorize  (consent submit)
 *   POST /wp-json/site-manager/v1/oauth/token      (authorization_code, refresh_token)
 *
 * Security model:
 *  - Public clients only; PKCE S256 required.
 *  - Only administrators can approve a client.
 *  - Codes: single use, 5-minute TTL, bound to client + redirect_uri + challenge.
 *  - Access tokens: 24 hours. Refresh tokens: 30 days, rotated on every use.
 *  - Token values are stored as sha256 hashes.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_OAuth {

	const SCOPE             = 'site-manager';
	const CODE_TTL          = 300;
	const ACCESS_TOKEN_TTL  = DAY_IN_SECONDS;
	const REFRESH_TOKEN_TTL = 30 * DAY_IN_SECONDS;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this, 'emit_cors_headers' ), 15 );

		// The authorize screen needs the browser's cookie session, which WP
		// REST rejects without an X-WP-Nonce header. Intercept the URL before
		// REST routing and serve it as a plain request instead.
		add_action( 'parse_request', array( $this, 'maybe_intercept_authorize' ), 5 );
	}

	private static function is_our_path( $suffix ) {
		$route = '/' . Site_Manager_Server::NAMESPACE_V1 . $suffix;
		if ( isset( $_GET['rest_route'] ) ) {
			return untrailingslashit( (string) $_GET['rest_route'] ) === $route;
		}
		$path = untrailingslashit( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ) );
		return substr( $path, -strlen( $route ) ) === $route && strpos( $path, '/' . rest_get_url_prefix() . '/' ) !== false;
	}

	public function maybe_intercept_authorize() {
		if ( ! self::is_our_path( '/oauth/authorize' ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( $method === 'OPTIONS' ) {
			status_header( 204 );
			exit;
		}

		$params = wp_unslash( $method === 'POST' ? $_POST : $_GET );
		if ( $method === 'POST' ) {
			$this->handle_authorize_post( $params );
		} else {
			$this->handle_authorize_get( $params );
		}
		exit;
	}

	public function emit_cors_headers() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( strpos( $uri, Site_Manager_Server::NAMESPACE_V1 ) === false ) {
			return;
		}
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version, Mcp-Session-Id' );
		header( 'Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id' );
		header( 'Access-Control-Max-Age: 86400' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
			status_header( 204 );
			exit;
		}
	}

	public function register_routes() {
		$ns = Site_Manager_Server::NAMESPACE_V1;
		register_rest_route( $ns, '/oauth/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_register' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/oauth/token', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_token' ),
			'permission_callback' => '__return_true',
		) );
	}

	// ---------------------------------------------------------------
	// /oauth/register
	// ---------------------------------------------------------------

	public function handle_register( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! $body ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) || ! $body ) {
			return $this->oauth_error( 'invalid_client_metadata', 'Request body must be JSON or form-encoded.', 400 );
		}

		$uris = array();
		foreach ( (array) ( isset( $body['redirect_uris'] ) ? $body['redirect_uris'] : array() ) as $u ) {
			$u      = trim( (string) $u );
			$scheme = strtolower( (string) wp_parse_url( $u, PHP_URL_SCHEME ) );
			$host   = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
			// https always; http only for loopback; custom schemes for native apps.
			if ( $scheme === 'https'
				|| ( $scheme === 'http' && in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) )
				|| ( $scheme !== '' && $scheme !== 'http' && $scheme !== 'javascript' && $scheme !== 'data' ) ) {
				$uris[] = $u;
			}
		}
		if ( ! $uris ) {
			return $this->oauth_error( 'invalid_redirect_uri', 'At least one valid redirect_uri is required.', 400 );
		}

		$name   = isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'MCP client';
		$client = Site_Manager_OAuth_Store::create_client( $name, $uris );
		if ( ! $client ) {
			return $this->oauth_error( 'server_error', 'Failed to register client.', 500 );
		}

		return new WP_REST_Response( array(
			'client_id'                  => $client['client_id'],
			'client_id_issued_at'        => time(),
			'client_name'                => $client['client_name'],
			'redirect_uris'              => $client['redirect_uris'],
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'scope'                      => self::SCOPE,
		), 201 );
	}

	// ---------------------------------------------------------------
	// /oauth/authorize
	// ---------------------------------------------------------------

	private function handle_authorize_get( array $raw ) {
		$params = $this->validate_authorize_params( $raw );
		if ( is_wp_error( $params ) ) {
			$this->html_error_page( $params->get_error_message() );
		}
		if ( ! is_user_logged_in() ) {
			$self = set_url_scheme( ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
			wp_safe_redirect( wp_login_url( $self ) );
			exit;
		}
		if ( ! current_user_can( Site_Manager_Server::CAPABILITY ) ) {
			$this->html_error_page( 'Only administrators can connect applications to Site Manager. You are signed in as a user without administrator access.' );
		}
		$this->render_consent_page( $params );
	}

	private function handle_authorize_post( array $raw ) {
		if ( ! is_user_logged_in() || ! current_user_can( Site_Manager_Server::CAPABILITY ) ) {
			$this->html_error_page( 'Only administrators can connect applications to Site Manager.' );
		}
		if ( ! isset( $raw['_wpnonce'] ) || ! wp_verify_nonce( $raw['_wpnonce'], 'site_manager_oauth_consent' ) ) {
			$this->html_error_page( 'This authorization request has expired. Start the connection again from your MCP client.' );
		}

		$params = $this->validate_authorize_params( $raw );
		if ( is_wp_error( $params ) ) {
			$this->html_error_page( $params->get_error_message() );
		}

		// wp_redirect, not wp_safe_redirect: the callback is on the client's
		// host by design. Safety comes from matching redirect_uri against the
		// client's registered URIs in validate_authorize_params().
		if ( ( isset( $raw['decision'] ) ? $raw['decision'] : '' ) !== 'approve' ) {
			wp_redirect( $this->build_redirect( $params['redirect_uri'], array(
				'error'             => 'access_denied',
				'error_description' => 'The administrator declined the request.',
				'state'             => $params['state'],
			) ) );
			exit;
		}

		$code = Site_Manager_OAuth_Store::issue_token(
			Site_Manager_OAuth_Store::TYPE_CODE,
			$params['client_id'],
			get_current_user_id(),
			self::SCOPE,
			self::CODE_TTL,
			array(
				'code_challenge' => $params['code_challenge'],
				'redirect_uri'   => $params['redirect_uri'],
				'resource'       => $params['resource'],
			)
		);

		wp_redirect( $this->build_redirect( $params['redirect_uri'], array(
			'code'  => $code,
			'state' => $params['state'],
			'iss'   => Site_Manager_OAuth_Discovery::issuer(),
		) ) );
		exit;
	}

	// ---------------------------------------------------------------
	// /oauth/token
	// ---------------------------------------------------------------

	public function handle_token( WP_REST_Request $request ) {
		$params = $request->get_body_params();
		if ( ! $params ) {
			$params = $request->get_json_params();
		}
		$params = is_array( $params ) ? $params : array();
		$grant  = isset( $params['grant_type'] ) ? (string) $params['grant_type'] : '';

		if ( $grant === 'authorization_code' ) {
			return $this->token_from_code( $params );
		}
		if ( $grant === 'refresh_token' ) {
			return $this->token_from_refresh( $params );
		}
		return $this->oauth_error( 'unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.', 400 );
	}

	private function token_from_code( array $params ) {
		foreach ( array( 'code', 'redirect_uri', 'client_id', 'code_verifier' ) as $required ) {
			if ( empty( $params[ $required ] ) ) {
				return $this->oauth_error( 'invalid_request', "Missing parameter: {$required}", 400 );
			}
		}
		$client = Site_Manager_OAuth_Store::get_client( $params['client_id'] );
		if ( ! $client ) {
			return $this->oauth_error( 'invalid_client', 'Unknown client_id.', 401 );
		}
		$code = Site_Manager_OAuth_Store::find_token( Site_Manager_OAuth_Store::TYPE_CODE, $params['code'] );
		if ( ! $code || $code['client_id'] !== $client['client_id'] || $code['redirect_uri'] !== $params['redirect_uri'] ) {
			return $this->oauth_error( 'invalid_grant', 'Authorization code is invalid, expired, or already used.', 400 );
		}

		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $params['code_verifier'], true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( (string) $code['code_challenge'], $challenge ) ) {
			return $this->oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		Site_Manager_OAuth_Store::revoke_token_by_id( $code['id'] );

		// Re-check the approving user is still an admin.
		if ( ! user_can( (int) $code['user_id'], Site_Manager_Server::CAPABILITY ) ) {
			return $this->oauth_error( 'invalid_grant', 'The approving user is no longer an administrator.', 400 );
		}

		return $this->issue_pair( $client['client_id'], (int) $code['user_id'], (string) $code['resource'] );
	}

	private function token_from_refresh( array $params ) {
		foreach ( array( 'refresh_token', 'client_id' ) as $required ) {
			if ( empty( $params[ $required ] ) ) {
				return $this->oauth_error( 'invalid_request', "Missing parameter: {$required}", 400 );
			}
		}
		$row = Site_Manager_OAuth_Store::find_token( Site_Manager_OAuth_Store::TYPE_REFRESH, $params['refresh_token'] );
		if ( ! $row || $row['client_id'] !== $params['client_id'] ) {
			return $this->oauth_error( 'invalid_grant', 'Refresh token is invalid or expired.', 400 );
		}
		Site_Manager_OAuth_Store::revoke_token_by_id( $row['id'] );

		if ( ! user_can( (int) $row['user_id'], Site_Manager_Server::CAPABILITY ) ) {
			return $this->oauth_error( 'invalid_grant', 'The connected user is no longer an administrator.', 400 );
		}

		return $this->issue_pair( $row['client_id'], (int) $row['user_id'], (string) $row['resource'] );
	}

	private function issue_pair( $client_id, $user_id, $resource ) {
		$extra   = array( 'resource' => $resource );
		$access  = Site_Manager_OAuth_Store::issue_token( Site_Manager_OAuth_Store::TYPE_ACCESS, $client_id, $user_id, self::SCOPE, self::ACCESS_TOKEN_TTL, $extra );
		$refresh = Site_Manager_OAuth_Store::issue_token( Site_Manager_OAuth_Store::TYPE_REFRESH, $client_id, $user_id, self::SCOPE, self::REFRESH_TOKEN_TTL, $extra );

		$response = new WP_REST_Response( array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refresh,
			'scope'         => self::SCOPE,
		), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	private function validate_authorize_params( array $raw ) {
		$get = function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
		};

		if ( $get( 'response_type' ) !== 'code' ) {
			return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.' );
		}
		if ( $get( 'code_challenge_method' ) !== 'S256' || $get( 'code_challenge' ) === '' ) {
			return new WP_Error( 'invalid_request', 'A PKCE S256 code_challenge is required.' );
		}
		$client = Site_Manager_OAuth_Store::get_client( $get( 'client_id' ) );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', 'Unknown client. Remove and re-add the connector in your MCP client.' );
		}
		if ( ! in_array( $get( 'redirect_uri' ), $client['redirect_uris'], true ) ) {
			return new WP_Error( 'invalid_redirect_uri', 'redirect_uri is not registered for this client.' );
		}

		return array(
			'client'         => $client,
			'client_id'      => $client['client_id'],
			'redirect_uri'   => $get( 'redirect_uri' ),
			'code_challenge' => $get( 'code_challenge' ),
			'state'          => $get( 'state' ),
			'scope'          => $get( 'scope' ) !== '' ? $get( 'scope' ) : self::SCOPE,
			'resource'       => $get( 'resource' ),
		);
	}

	private function build_redirect( $base, array $params ) {
		$params = array_filter( $params, function ( $v ) {
			return $v !== '' && $v !== null;
		} );
		return $base . ( strpos( $base, '?' ) === false ? '?' : '&' ) . http_build_query( $params );
	}

	private function page_head( $title ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title ); ?></title>
<style>
	body { background: #f0f0f1; color: #3c434a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; font-size: 14px; line-height: 1.5; margin: 0; padding: 8% 16px 40px; }
	.box { max-width: 420px; margin: 0 auto; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 3px rgba(0,0,0,.04); padding: 26px 24px; }
	h1 { font-size: 20px; font-weight: 400; margin: 0 0 16px; color: #1d2327; }
	p { margin: 0 0 14px; }
	.client { border-left: 4px solid #2271b1; background: #f6f7f7; padding: 10px 12px; margin: 0 0 16px; }
	.client strong { display: block; color: #1d2327; }
	.actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }
	.button { display: inline-block; font-size: 13px; line-height: 2.15384615; min-height: 30px; padding: 0 12px; border-radius: 3px; border: 1px solid #2271b1; background: #f6f7f7; color: #2271b1; cursor: pointer; }
	.button-primary { background: #2271b1; color: #fff; }
	.meta { color: #646970; font-size: 12px; margin-top: 18px; }
	.meta a { color: #2271b1; }
</style>
</head><body><div class="box">
		<?php
	}

	private function render_consent_page( array $params ) {
		$user = wp_get_current_user();
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$this->page_head( 'Connect ' . $params['client']['client_name'] . ' — ' . $site );
		?>
	<h1>Connect to Site Manager</h1>
	<div class="client">
		<strong><?php echo esc_html( $params['client']['client_name'] ); ?></strong>
		is requesting administrator access to <?php echo esc_html( $site ); ?>.
	</div>
	<p>If you approve, this application can read and change content, users, settings, themes and plugins on this site, acting as <strong><?php echo esc_html( $user->user_login ); ?></strong>.</p>
	<p>You can disconnect it at any time from <em>Settings → Site Manager → Connections</em>.</p>
	<form method="post" action="<?php echo esc_url( Site_Manager_OAuth_Discovery::endpoint( 'authorize' ) ); ?>">
		<?php wp_nonce_field( 'site_manager_oauth_consent', '_wpnonce', false ); ?>
		<?php foreach ( array( 'client_id', 'redirect_uri', 'code_challenge', 'state', 'scope', 'resource' ) as $field ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $params[ $field ] ); ?>" />
		<?php endforeach; ?>
		<input type="hidden" name="response_type" value="code" />
		<input type="hidden" name="code_challenge_method" value="S256" />
		<div class="actions">
			<button type="submit" name="decision" value="deny" class="button">Deny</button>
			<button type="submit" name="decision" value="approve" class="button button-primary">Approve</button>
		</div>
	</form>
	<p class="meta">Redirects to <?php echo esc_html( wp_parse_url( $params['redirect_uri'], PHP_URL_HOST ) ?: $params['redirect_uri'] ); ?> &middot; <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">Not you?</a></p>
</div></body></html>
		<?php
		exit;
	}

	private function html_error_page( $message ) {
		$this->page_head( 'Authorization error' );
		echo '<h1>Authorization error</h1><p>' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}

	private function oauth_error( $code, $description, $status ) {
		$response = new WP_REST_Response( array(
			'error'             => $code,
			'error_description' => $description,
		), $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}

<?php
/**
 * OAuth client + token storage.
 *
 * Two custom tables:
 *   {prefix}site_manager_oauth_clients — clients registered via DCR
 *   {prefix}site_manager_oauth_tokens  — auth codes, access and refresh
 *                                        tokens, stored as sha256 hashes
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_OAuth_Store {

	const TYPE_CODE    = 'code';
	const TYPE_ACCESS  = 'access';
	const TYPE_REFRESH = 'refresh';

	public static function clients_table() {
		global $wpdb;
		return $wpdb->prefix . 'site_manager_oauth_clients';
	}

	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'site_manager_oauth_tokens';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$clients = self::clients_table();
		$tokens  = self::tokens_table();

		dbDelta( "CREATE TABLE {$clients} (
			client_id varchar(64) NOT NULL,
			client_name varchar(255) NOT NULL DEFAULT '',
			redirect_uris longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (client_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(16) NOT NULL,
			token_hash varchar(64) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			code_challenge varchar(128) DEFAULT NULL,
			redirect_uri text DEFAULT NULL,
			scope varchar(255) NOT NULL DEFAULT '',
			resource text DEFAULT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			last_used_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY type_expires (type, expires_at),
			KEY client_user (client_id, user_id)
		) {$charset};" );
	}

	// ---------------------------------------------------------------
	// Clients
	// ---------------------------------------------------------------

	public static function create_client( $client_name, array $redirect_uris ) {
		global $wpdb;
		$client_id = wp_generate_password( 32, false, false );
		$ok        = $wpdb->insert( self::clients_table(), array(
			'client_id'     => $client_id,
			'client_name'   => $client_name,
			'redirect_uris' => wp_json_encode( array_values( $redirect_uris ) ),
			'created_at'    => current_time( 'mysql', true ),
		) );
		return $ok === false ? null : self::get_client( $client_id );
	}

	public static function get_client( $client_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::clients_table() . ' WHERE client_id = %s', $client_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['redirect_uris'] = json_decode( $row['redirect_uris'], true ) ?: array();
		return $row;
	}

	// ---------------------------------------------------------------
	// Tokens
	// ---------------------------------------------------------------

	/**
	 * Generate a random token and store its hash.
	 *
	 * @return string The raw token (only available here).
	 */
	public static function issue_token( $type, $client_id, $user_id, $scope, $ttl_seconds, array $extra = array() ) {
		global $wpdb;
		$raw = wp_generate_password( 48, false, false );
		$wpdb->insert( self::tokens_table(), array(
			'type'           => $type,
			'token_hash'     => hash( 'sha256', $raw ),
			'client_id'      => $client_id,
			'user_id'        => (int) $user_id,
			'code_challenge' => isset( $extra['code_challenge'] ) ? (string) $extra['code_challenge'] : null,
			'redirect_uri'   => isset( $extra['redirect_uri'] ) ? (string) $extra['redirect_uri'] : null,
			'scope'          => (string) $scope,
			'resource'       => isset( $extra['resource'] ) ? (string) $extra['resource'] : null,
			'created_at'     => current_time( 'mysql', true ),
			'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + (int) $ttl_seconds ),
		) );
		return $raw;
	}

	/**
	 * Look up a live (unexpired, unrevoked) token by its raw value.
	 */
	public static function find_token( $type, $raw_token ) {
		global $wpdb;
		if ( $raw_token === '' ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::tokens_table() . ' WHERE type = %s AND token_hash = %s',
			$type,
			hash( 'sha256', (string) $raw_token )
		), ARRAY_A );
		if ( ! $row || ! empty( $row['revoked_at'] ) || strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		return $row;
	}

	/** Record use, at most once a minute per token. */
	public static function touch( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::tokens_table() . ' SET last_used_at = %s WHERE id = %d AND ( last_used_at IS NULL OR last_used_at < %s )',
			current_time( 'mysql', true ),
			(int) $id,
			gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS )
		) );
	}

	public static function revoke_token_by_id( $id ) {
		global $wpdb;
		$wpdb->update( self::tokens_table(), array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
	}

	/** Revoke every token a client holds for a user (or all users when $user_id is null). */
	public static function revoke_client( $client_id, $user_id = null ) {
		global $wpdb;
		$sql    = 'UPDATE ' . self::tokens_table() . ' SET revoked_at = %s WHERE client_id = %s AND revoked_at IS NULL';
		$params = array( current_time( 'mysql', true ), $client_id );
		if ( $user_id !== null ) {
			$sql     .= ' AND user_id = %d';
			$params[] = (int) $user_id;
		}
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	public static function revoke_all() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tokens_table() . ' SET revoked_at = %s WHERE revoked_at IS NULL', current_time( 'mysql', true ) ) );
	}

	/**
	 * Active connections: one row per client + user that holds a live
	 * refresh or access token.
	 */
	public static function connections() {
		global $wpdb;
		$tokens  = self::tokens_table();
		$clients = self::clients_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT t.client_id, t.user_id, c.client_name,
				MIN(t.created_at) AS connected_at,
				MAX(t.last_used_at) AS last_used_at,
				MAX(t.expires_at) AS expires_at
			FROM {$tokens} t
			LEFT JOIN {$clients} c ON c.client_id = t.client_id
			WHERE t.type IN ('access','refresh') AND t.revoked_at IS NULL AND t.expires_at > %s
			GROUP BY t.client_id, t.user_id, c.client_name
			ORDER BY last_used_at DESC",
			gmdate( 'Y-m-d H:i:s' )
		), ARRAY_A ) ?: array();
	}

	public static function purge_expired() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::tokens_table() . ' WHERE expires_at < %s OR revoked_at < %s', $cutoff, $cutoff ) );
		// Drop clients that never completed authorization or have no tokens left.
		$wpdb->query( $wpdb->prepare(
			'DELETE c FROM ' . self::clients_table() . ' c LEFT JOIN ' . self::tokens_table() . ' t ON t.client_id = c.client_id WHERE t.id IS NULL AND c.created_at < %s',
			$cutoff
		) );
	}
}

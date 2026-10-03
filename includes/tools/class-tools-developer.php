<?php
/**
 * MCP tools for developers: the internal REST API (which reaches every
 * plugin that exposes REST routes), the database, files, hooks and PHP.
 *
 * Database writes, file writes and PHP execution are gated behind
 * settings that are off by default.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Developer {

	const MAX_FILE_READ = 1 * MB_IN_BYTES;

	/** Files that are never readable or writable through these tools. */
	const BLOCKED_FILES = '/(^|\/)(wp-config\.php|wp-config-sample\.php|\.env(\..*)?|\.htpasswd|auth\.json)$/i';

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'developer', __( 'Developer', 'site-manager' ), __( 'Internal REST API passthrough, database queries, theme/plugin files, hooks, PHP execution.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$r->register( 'rest_routes_list', array(
			'category'     => 'developer',
			'description'  => 'List REST API namespaces, or the routes and methods within one namespace (e.g. "wp/v2", "wc/v3", "gf/v2", "elementor/v1"). Use with rest_request to reach any plugin that has a REST API.',
			'input_schema' => $s::obj( array(
				'namespace' => $s::str( 'Omit to list namespaces only.' ),
			) ),
			'handler'      => array( $this, 'rest_routes_list' ),
		) );

		$r->register( 'rest_request', array(
			'category'     => 'developer',
			'description'  => 'Call any WordPress REST API route internally, as the connected administrator — no extra auth needed. Covers core endpoints (/wp/v2/widgets, /wp/v2/templates, /wp/v2/settings…) and every plugin\'s routes (WooCommerce /wc/v3, Gravity Forms /gf/v2, etc).',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'method' => $s::enum( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), '', 'GET' ),
				'route'  => $s::str( 'Route path, e.g. "/wp/v2/posts/12" or "/wc/v3/orders".' ),
				'query'  => $s::map( 'Query-string parameters.' ),
				'body'   => $s::map( 'JSON body parameters.' ),
				'embed'  => $s::bool( 'Include _embedded data.', false ),
			), array( 'route' ) ),
			'handler'      => array( $this, 'rest_request' ),
		) );

		$r->register( 'db_tables_list', array(
			'category'     => 'developer',
			'description'  => 'List database tables with row counts, sizes and engine. Optionally show one table\'s columns and indexes.',
			'input_schema' => $s::obj( array(
				'describe' => $s::str( 'Table name to describe ({prefix} placeholder allowed).' ),
			) ),
			'handler'      => array( $this, 'db_tables_list' ),
		) );

		$r->register( 'db_query', array(
			'category'     => 'developer',
			'description'  => 'Run a read-only SQL query (SELECT, SHOW, DESCRIBE, EXPLAIN) in a read-only transaction. Use {prefix} for the table prefix, e.g. "SELECT * FROM {prefix}posts LIMIT 5".',
			'input_schema' => $s::obj( array(
				'sql'      => $s::str(),
				'max_rows' => $s::int( '', array( 'default' => 200, 'maximum' => 5000 ) ),
			), array( 'sql' ) ),
			'handler'      => array( $this, 'db_query' ),
		) );

		$r->register( 'db_execute', array(
			'category'     => 'developer',
			'description'  => 'Run a write SQL statement (INSERT, UPDATE, DELETE, REPLACE, ALTER, CREATE, DROP, TRUNCATE). {prefix} is replaced with the table prefix. Take care with serialized PHP values. Confirm with the user before running.',
			'writes'       => true,
			'destructive'  => true,
			'gate'         => 'allow_db_write',
			'input_schema' => $s::obj( array( 'sql' => $s::str() ), array( 'sql' ) ),
			'handler'      => array( $this, 'db_execute' ),
		) );

		$r->register( 'file_list', array(
			'category'     => 'developer',
			'description'  => 'List files and folders under a path relative to the WordPress root (e.g. "wp-content/themes/my-theme").',
			'input_schema' => $s::obj( array(
				'path'      => $s::str( 'Relative to the WordPress root. Default "wp-content".' ),
				'recursive' => $s::bool( 'Recurse (up to 2000 entries).', false ),
			) ),
			'handler'      => array( $this, 'file_list' ),
		) );

		$r->register( 'file_read', array(
			'category'     => 'developer',
			'description'  => 'Read a text file under the WordPress root (theme templates, plugin code, .htaccess, logs). wp-config.php and .env files are never readable. Max 1 MB.',
			'input_schema' => $s::obj( array(
				'path'       => $s::str( 'Relative to the WordPress root.' ),
				'start_line' => $s::int( '1-based first line.' ),
				'end_line'   => $s::int( 'Last line (inclusive).' ),
			), array( 'path' ) ),
			'handler'      => array( $this, 'file_read' ),
		) );

		$r->register( 'file_write', array(
			'category'     => 'developer',
			'description'  => 'Create or overwrite a file inside wp-content (e.g. a child theme template or functions.php). PHP files are syntax-checked first when possible. A .bak copy of the previous version is kept.',
			'writes'       => true,
			'destructive'  => true,
			'gate'         => 'allow_file_write',
			'input_schema' => $s::obj( array(
				'path'    => $s::str( 'Relative to the WordPress root; must be inside wp-content.' ),
				'content' => $s::str(),
			), array( 'path', 'content' ) ),
			'handler'      => array( $this, 'file_write' ),
		) );

		$r->register( 'file_delete', array(
			'category'     => 'developer',
			'description'  => 'Delete a file inside wp-content.',
			'writes'       => true,
			'destructive'  => true,
			'gate'         => 'allow_file_write',
			'input_schema' => $s::obj( array( 'path' => $s::str() ), array( 'path' ) ),
			'handler'      => array( $this, 'file_delete' ),
		) );

		$r->register( 'hooks_list', array(
			'category'     => 'developer',
			'description'  => 'Show the callbacks attached to an action or filter (priority, function, file:line) — useful for debugging what a plugin or theme changes. Pass search instead to find hook names.',
			'input_schema' => $s::obj( array(
				'hook'   => $s::str( 'Exact hook name.' ),
				'search' => $s::str( 'Find registered hook names containing this text.' ),
			) ),
			'handler'      => array( $this, 'hooks_list' ),
		) );

		$r->register( 'shortcodes_list', array(
			'category'     => 'developer',
			'description'  => 'List registered shortcodes and the callbacks that render them.',
			'handler'      => array( $this, 'shortcodes_list' ),
		) );

		$r->register( 'php_execute', array(
			'category'     => 'developer',
			'description'  => 'Evaluate PHP code inside WordPress (no opening <?php tag). Echoed output and the return value are captured. Full server access — use only when no dedicated tool fits, and confirm with the user first.',
			'writes'       => true,
			'destructive'  => true,
			'gate'         => 'allow_php_exec',
			'input_schema' => $s::obj( array( 'code' => $s::str( 'e.g. "return get_option(\'blogname\');"' ) ), array( 'code' ) ),
			'handler'      => array( $this, 'php_execute' ),
		) );
	}

	// ---------------------------------------------------------------
	// REST
	// ---------------------------------------------------------------

	public function rest_routes_list( array $args ) {
		$server = rest_get_server();
		$ns     = (string) Site_Manager_Helpers::arg( $args, 'namespace', '' );
		if ( $ns === '' ) {
			return array( 'namespaces' => $server->get_namespaces() );
		}
		$out = array();
		foreach ( $server->get_routes( $ns ) as $route => $handlers ) {
			$methods = array();
			foreach ( $handlers as $h ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) $h['methods'] ) ) );
			}
			$out[] = array( 'route' => $route, 'methods' => array_values( array_unique( $methods ) ) );
		}
		return array( 'namespace' => $ns, 'routes' => $out );
	}

	public function rest_request( array $args ) {
		$method = strtoupper( (string) Site_Manager_Helpers::arg( $args, 'method', 'GET' ) );
		$route  = '/' . ltrim( (string) $args['route'], '/' );
		if ( strpos( $route, '/' . Site_Manager_Server::NAMESPACE_V1 ) === 0 ) {
			return new WP_Error( 'recursive', 'rest_request cannot call Site Manager\'s own routes.' );
		}
		// Allow a query string embedded in the route.
		$query = (array) Site_Manager_Helpers::arg( $args, 'query', array() );
		if ( strpos( $route, '?' ) !== false ) {
			list( $route, $qs ) = explode( '?', $route, 2 );
			parse_str( $qs, $parsed );
			$query = array_merge( $parsed, $query );
		}

		$request = new WP_REST_Request( $method, $route );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( ! empty( $args['body'] ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $args['body'] ) );
		}
		$response = rest_do_request( $request );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, Site_Manager_Helpers::bool( $args, 'embed' ) );

		$headers = array_intersect_key( $response->get_headers(), array_flip( array( 'X-WP-Total', 'X-WP-TotalPages', 'Allow', 'Location' ) ) );
		return array(
			'status'  => $response->get_status(),
			'headers' => (object) $headers,
			'data'    => $data,
		);
	}

	// ---------------------------------------------------------------
	// Database
	// ---------------------------------------------------------------

	private static function prefix_sql( $sql ) {
		global $wpdb;
		return str_replace( '{prefix}', $wpdb->prefix, trim( (string) $sql ) );
	}

	public function db_tables_list( array $args ) {
		global $wpdb;
		if ( ! empty( $args['describe'] ) ) {
			$table = preg_replace( '/[^A-Za-z0-9_$]/', '', self::prefix_sql( $args['describe'] ) );
			$cols  = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
			if ( ! $cols ) {
				return new WP_Error( 'not_found', 'Table not found: ' . $table );
			}
			return array(
				'table'   => $table,
				'columns' => $cols,
				'indexes' => $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ),
			);
		}
		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'    => $row['Name'],
				'rows'    => (int) $row['Rows'],
				'size'    => size_format( (int) $row['Data_length'] + (int) $row['Index_length'] ),
				'engine'  => $row['Engine'],
				'core'    => in_array( substr( $row['Name'], strlen( $wpdb->prefix ) ), $wpdb->tables( 'all', false ), true ),
			);
		}
		return array( 'prefix' => $wpdb->prefix, 'tables' => $out );
	}

	public function db_query( array $args ) {
		global $wpdb;
		$sql   = self::prefix_sql( $args['sql'] );
		$plain = strtoupper( ltrim( preg_replace( '#/\*.*?\*/|^\s*(--|\#)[^\n]*#ms', '', $sql ) ) );
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/', $plain ) ) {
			return new WP_Error( 'not_read_only', 'db_query only runs SELECT / SHOW / DESCRIBE / EXPLAIN. Use db_execute for writes.' );
		}
		if ( preg_match( '/\bINTO\s+(OUT|DUMP)FILE\b|\bFOR\s+UPDATE\b/i', $sql ) ) {
			return new WP_Error( 'not_read_only', 'That query writes files or takes locks.' );
		}
		$max = max( 1, min( 5000, (int) Site_Manager_Helpers::arg( $args, 'max_rows', 200 ) ) );

		$wpdb->query( 'START TRANSACTION READ ONLY' );
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$error = $wpdb->last_error;
		$wpdb->query( 'ROLLBACK' );

		if ( $error ) {
			return new WP_Error( 'sql_error', $error );
		}
		$total = count( (array) $rows );
		return array(
			'rows'      => array_slice( (array) $rows, 0, $max ),
			'row_count' => $total,
			'truncated' => $total > $max,
		);
	}

	public function db_execute( array $args ) {
		global $wpdb;
		$sql    = self::prefix_sql( $args['sql'] );
		$result = $wpdb->query( $sql );
		if ( $result === false ) {
			return new WP_Error( 'sql_error', $wpdb->last_error ?: 'Query failed.' );
		}
		wp_cache_flush();
		return array( 'rows_affected' => (int) $result, 'insert_id' => (int) $wpdb->insert_id );
	}

	// ---------------------------------------------------------------
	// Files
	// ---------------------------------------------------------------

	/**
	 * Resolve a path relative to ABSPATH, refusing anything that escapes
	 * the install (or wp-content, for writes) or names a blocked file.
	 */
	private static function resolve_path( $path, $for_write = false ) {
		$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		if ( $path === '' || strpos( $path, "\0" ) !== false ) {
			return new WP_Error( 'invalid_path', 'Invalid path.' );
		}
		// Resolve "." and ".." lexically, so a path can never climb above the
		// install root. (Not realpath(): symlinked plugin/theme folders inside
		// wp-content are legitimate and should stay reachable.)
		$parts = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( $seg === '' || $seg === '.' ) {
				continue;
			}
			if ( $seg === '..' ) {
				if ( ! $parts ) {
					return new WP_Error( 'outside_root', 'Path is outside the WordPress install.' );
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $seg;
		}
		$root    = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$content = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		$rel     = implode( '/', $parts );
		$full    = $root . '/' . $rel;

		// Installs like Bedrock keep wp-content outside ABSPATH; still let
		// "wp-content/…" address it.
		if ( strpos( $content . '/', $root . '/' ) !== 0 && ( $rel === 'wp-content' || strpos( $rel, 'wp-content/' ) === 0 ) ) {
			$full = $content . substr( $rel, strlen( 'wp-content' ) );
		}
		if ( $for_write && strpos( $full . '/', $content . '/' ) !== 0 ) {
			return new WP_Error( 'outside_root', 'Writes are limited to wp-content.' );
		}
		if ( preg_match( self::BLOCKED_FILES, $full ) ) {
			return new WP_Error( 'blocked', 'Access to that file is blocked.' );
		}
		if ( ! $for_write && ! file_exists( $full ) ) {
			return new WP_Error( 'not_found', 'Path not found: ' . $path );
		}
		return $full;
	}

	private static function relative( $real ) {
		$root    = trailingslashit( wp_normalize_path( ABSPATH ) );
		$content = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		if ( strpos( $real, $root ) === 0 ) {
			return substr( $real, strlen( $root ) );
		}
		return strpos( $real, $content ) === 0 ? 'wp-content' . substr( $real, strlen( $content ) ) : $real;
	}

	public function file_list( array $args ) {
		$dir = self::resolve_path( Site_Manager_Helpers::arg( $args, 'path', 'wp-content' ) );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'not_dir', 'Not a directory.' );
		}
		$out = array();
		if ( Site_Manager_Helpers::bool( $args, 'recursive' ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $f ) {
				if ( count( $out ) >= 2000 ) {
					$out[] = array( 'truncated' => true );
					break;
				}
				if ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', '.git', 'vendor' ), true ) ) {
					continue;
				}
				$out[] = array( 'path' => self::relative( wp_normalize_path( $f->getPathname() ) ), 'type' => $f->isDir() ? 'dir' : 'file', 'size' => $f->isDir() ? null : $f->getSize() );
			}
			return $out;
		}
		foreach ( new DirectoryIterator( $dir ) as $f ) {
			if ( $f->isDot() ) {
				continue;
			}
			$out[] = array(
				'name'     => $f->getFilename(),
				'type'     => $f->isDir() ? 'dir' : 'file',
				'size'     => $f->isDir() ? null : $f->getSize(),
				'modified' => gmdate( 'c', $f->getMTime() ),
				'writable' => $f->isWritable(),
			);
		}
		usort( $out, function ( $a, $b ) {
			return array( $a['type'], $a['name'] ) <=> array( $b['type'], $b['name'] );
		} );
		return array( 'path' => self::relative( $dir ), 'entries' => $out );
	}

	public function file_read( array $args ) {
		$file = self::resolve_path( $args['path'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'not_readable', 'Not a readable file.' );
		}
		if ( filesize( $file ) > self::MAX_FILE_READ && empty( $args['start_line'] ) ) {
			return new WP_Error( 'too_large', 'File is over 1 MB; pass start_line / end_line to read a range.' );
		}
		$content = file_get_contents( $file );
		if ( strpos( substr( $content, 0, 8000 ), "\0" ) !== false ) {
			return new WP_Error( 'binary', 'File appears to be binary.' );
		}
		if ( ! empty( $args['start_line'] ) || ! empty( $args['end_line'] ) ) {
			$lines   = explode( "\n", $content );
			$start   = max( 1, (int) Site_Manager_Helpers::arg( $args, 'start_line', 1 ) );
			$end     = (int) Site_Manager_Helpers::arg( $args, 'end_line', count( $lines ) );
			$content = implode( "\n", array_slice( $lines, $start - 1, max( 0, $end - $start + 1 ) ) );
		}
		return array( 'path' => self::relative( $file ), 'size' => filesize( $file ), 'content' => $content );
	}

	public function file_write( array $args ) {
		$file = self::resolve_path( $args['path'], true );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$content = (string) $args['content'];

		if ( substr( $file, -4 ) === '.php' ) {
			$lint = self::lint_php( $content );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
		}
		if ( ! is_dir( dirname( $file ) ) && ! wp_mkdir_p( dirname( $file ) ) ) {
			return new WP_Error( 'mkdir_failed', 'Could not create the directory.' );
		}
		$backup = null;
		if ( file_exists( $file ) ) {
			$backup = $file . '.bak';
			copy( $file, $backup );
		}
		if ( file_put_contents( $file, $content ) === false ) {
			return new WP_Error( 'write_failed', 'Could not write the file (check permissions).' );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true );
		}
		return array( 'path' => self::relative( $file ), 'bytes' => strlen( $content ), 'backup' => $backup ? self::relative( $backup ) : null );
	}

	/**
	 * Syntax-check PHP. Uses token_get_all with TOKEN_PARSE, which throws
	 * ParseError on invalid code without executing it.
	 */
	private static function lint_php( $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return new WP_Error( 'php_syntax', sprintf( 'PHP syntax error on line %d: %s. File not written.', $e->getLine(), $e->getMessage() ) );
		}
		return true;
	}

	public function file_delete( array $args ) {
		$file = self::resolve_path( $args['path'], true );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'not_file', 'Not a file (directories are not deleted).' );
		}
		if ( strpos( $file, wp_normalize_path( SITE_MANAGER_DIR ) ) === 0 ) {
			return new WP_Error( 'protected', 'Refusing to delete Site Manager\'s own files.' );
		}
		return unlink( $file ) ? array( 'deleted' => self::relative( $file ) ) : new WP_Error( 'delete_failed', 'Could not delete the file.' );
	}

	// ---------------------------------------------------------------
	// Hooks, shortcodes, PHP
	// ---------------------------------------------------------------

	private static function describe_callable( $cb ) {
		try {
			if ( is_string( $cb ) && strpos( $cb, '::' ) !== false ) {
				$cb = explode( '::', $cb, 2 );
			}
			if ( is_array( $cb ) ) {
				$class = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
				$name  = $class . ( is_object( $cb[0] ) ? '->' : '::' ) . $cb[1];
				$ref   = new ReflectionMethod( $class, $cb[1] );
			} elseif ( $cb instanceof Closure ) {
				$ref  = new ReflectionFunction( $cb );
				$name = 'Closure';
			} elseif ( is_object( $cb ) ) {
				$name = get_class( $cb ) . '->__invoke';
				$ref  = new ReflectionMethod( $cb, '__invoke' );
			} else {
				$name = (string) $cb;
				$ref  = new ReflectionFunction( $cb );
			}
			$file = $ref->getFileName();
			return array( 'callback' => $name, 'source' => $file ? self::relative( wp_normalize_path( $file ) ) . ':' . $ref->getStartLine() : 'internal' );
		} catch ( Throwable $e ) {
			return array( 'callback' => isset( $name ) ? $name : 'unknown', 'source' => null );
		}
	}

	public function hooks_list( array $args ) {
		global $wp_filter;
		if ( ! empty( $args['search'] ) ) {
			$needle = (string) $args['search'];
			$names  = array_values( array_filter( array_keys( $wp_filter ), function ( $h ) use ( $needle ) {
				return stripos( $h, $needle ) !== false;
			} ) );
			sort( $names );
			return array( 'hooks' => array_slice( $names, 0, 500 ) );
		}
		$hook = (string) Site_Manager_Helpers::arg( $args, 'hook', '' );
		if ( $hook === '' || ! isset( $wp_filter[ $hook ] ) ) {
			return new WP_Error( 'not_found', 'No callbacks registered on that hook (in this request).' );
		}
		$out = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$out[] = array( 'priority' => $priority, 'accepted_args' => $cb['accepted_args'] ) + self::describe_callable( $cb['function'] );
			}
		}
		return array( 'hook' => $hook, 'callbacks' => $out );
	}

	public function shortcodes_list() {
		global $shortcode_tags;
		$out = array();
		foreach ( $shortcode_tags as $tag => $cb ) {
			$out[] = array( 'tag' => $tag ) + self::describe_callable( $cb );
		}
		return $out;
	}

	public function php_execute( array $args ) {
		$code = trim( (string) $args['code'] );
		$code = preg_replace( '/^<\?(php)?\s*/i', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', $code );

		ob_start();
		try {
			$return = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- gated admin-only feature.
		} catch ( Throwable $e ) {
			ob_end_clean();
			return new WP_Error( 'php_error', get_class( $e ) . ': ' . $e->getMessage() . ' on line ' . $e->getLine() );
		}
		$output = ob_get_clean();
		return array(
			'output' => $output,
			'return' => $return,
		);
	}
}

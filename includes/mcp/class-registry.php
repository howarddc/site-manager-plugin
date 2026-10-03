<?php
/**
 * Tool registry for the MCP server.
 *
 * Tool classes register into categories during `init`. The server consults
 * the registry for tools/list and tools/call; the admin screen uses the
 * categories to let admins switch whole groups on and off.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Registry {

	private static $instance = null;
	private $tools           = array();
	private $categories      = array();

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function add_category( $slug, $label, $description = '' ) {
		if ( ! isset( $this->categories[ $slug ] ) ) {
			$this->categories[ $slug ] = array(
				'label'       => $label,
				'description' => $description,
			);
		}
	}

	/**
	 * Register a tool.
	 *
	 * @param string $name Tool name (snake_case, unique).
	 * @param array  $def {
	 *     @type string   $category     Category slug (see add_category()).
	 *     @type string   $description  What the tool does, written for the model.
	 *     @type array    $input_schema JSON Schema for arguments.
	 *     @type callable $handler      function( array $args ): mixed|WP_Error
	 *     @type bool     $writes       Mutates the site (always logged).
	 *     @type bool     $destructive  Deletes or overwrites data.
	 *     @type bool     $open_world   Reaches outside the site (email, remote downloads).
	 *     @type string   $gate         Settings key that must be enabled (see Site_Manager_Settings::gates()).
	 *     @type string   $capability   Required capability (default manage_options).
	 * }
	 */
	public function register( $name, array $def ) {
		$this->tools[ $name ] = wp_parse_args( $def, array(
			'category'     => 'other',
			'description'  => '',
			'input_schema' => Site_Manager_Schema::obj(),
			'handler'      => null,
			'writes'       => false,
			'destructive'  => false,
			'open_world'   => false,
			'gate'         => null,
			'capability'   => 'manage_options',
		) );
	}

	public function all() {
		return $this->tools;
	}

	public function get( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	public function categories() {
		return $this->categories;
	}

	/**
	 * Whether a tool is currently exposed: its category is enabled and any
	 * gate it declares is open.
	 */
	public function is_available( $name ) {
		$tool = $this->get( $name );
		if ( ! $tool ) {
			return false;
		}
		return Site_Manager_Settings::category_enabled( $tool['category'] ) && Site_Manager_Settings::gate_open( $tool['gate'] );
	}

	public function available() {
		$out = array();
		foreach ( $this->tools as $name => $tool ) {
			if ( $this->is_available( $name ) ) {
				$out[ $name ] = $tool;
			}
		}
		return $out;
	}

	public function by_category() {
		$out = array();
		foreach ( $this->tools as $name => $tool ) {
			$out[ $tool['category'] ][ $name ] = $tool;
		}
		return $out;
	}
}

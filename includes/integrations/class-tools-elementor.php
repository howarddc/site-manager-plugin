<?php
/**
 * MCP tools: Elementor.
 *
 * Elementor pages store their layout as a JSON element tree in the
 * `_elementor_data` meta, not in post_content. These tools read and write
 * that tree through Elementor's Document API so CSS, caches and revisions
 * stay consistent with edits made in the editor.
 *
 * Element shape: {"id":"a1b2c3d","elType":"container|section|column|widget",
 * "widgetType":"heading","settings":{…},"elements":[…]}.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Elementor {

	/** Settings keys that carry a widget's visible text, for outlines and search. */
	const TEXT_KEYS = array( 'title', 'editor', 'text', 'description', 'title_text', 'description_text', 'caption', 'button_text', 'html', 'testimonial_content', 'tab_title', 'tab_content', 'item_title', 'heading', 'content' );

	public static function is_active() {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );
	}

	public static function version() {
		return ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' ) . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ' (Pro ' . ELEMENTOR_PRO_VERSION . ')' : '' );
	}

	/** Whether a post is edited with Elementor (used by post_get). */
	public static function is_built_with( $post_id ) {
		return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'elementor', __( 'Elementor', 'site-manager' ), __( 'Elementor page layouts, widgets, templates and global kit settings.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'Elementor is active: pages built with Elementor (post_get shows page_builder="elementor") keep their content in an element tree, not post_content — editing post_content has no visible effect. Use elementor_document_get (format=outline first), then elementor_element_update / _insert / _delete for targeted edits, or elementor_text_replace for copy changes.';
			return $lines;
		} );

		$r->register( 'elementor_status', array(
			'category'     => 'elementor',
			'description'  => 'Elementor overview: version, Pro status, active kit, number of pages built with Elementor, template counts by type.',
			'handler'      => array( $this, 'status' ),
		) );

		$r->register( 'elementor_pages_list', array(
			'category'     => 'elementor',
			'description'  => 'List posts/pages built with Elementor.',
			'input_schema' => $s::obj( array(
				'post_type' => $s::str( 'Default: any.' ),
				'search'    => $s::str(),
				'page'      => $s::page(),
				'per_page'  => $s::per_page( 50, 100 ),
			) ),
			'handler'      => array( $this, 'pages_list' ),
		) );

		$r->register( 'elementor_document_get', array(
			'category'     => 'elementor',
			'description'  => 'Get an Elementor document\'s element tree. format=outline (default) gives a compact tree of element IDs, types and text snippets; format=full returns the complete JSON including every setting. Also returns page settings.',
			'input_schema' => $s::obj( array(
				'id'     => $s::int( 'Post ID.' ),
				'format' => $s::enum( array( 'outline', 'full' ), '', 'outline' ),
			), array( 'id' ) ),
			'handler'      => array( $this, 'document_get' ),
		) );

		$r->register( 'elementor_document_save', array(
			'category'     => 'elementor',
			'description'  => 'Replace a document\'s whole element tree (and optionally page settings), turning on Elementor for the post if needed. Missing element IDs are generated. Prefer the element_* tools for small edits.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'       => $s::int(),
				'elements' => $s::arr( 'object', 'Top-level elements (containers or sections).' ),
				'settings' => $s::map( 'Page settings to merge, e.g. {"hide_title":"yes"}.' ),
			), array( 'id', 'elements' ) ),
			'handler'      => array( $this, 'document_save' ),
		) );

		$r->register( 'elementor_element_get', array(
			'category'     => 'elementor',
			'description'  => 'Get one element (and its children) by element ID.',
			'input_schema' => $s::obj( array(
				'id'         => $s::int( 'Post ID.' ),
				'element_id' => $s::str(),
			), array( 'id', 'element_id' ) ),
			'handler'      => array( $this, 'element_get' ),
		) );

		$r->register( 'elementor_element_update', array(
			'category'     => 'elementor',
			'description'  => 'Change settings on one element without resending the page: settings are merged (null removes a key). E.g. {"title":"New heading"} on a heading widget, {"editor":"<p>…</p>"} on a text-editor widget, {"text":"Buy now","link":{"url":"/shop"}} on a button.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'         => $s::int( 'Post ID.' ),
				'element_id' => $s::str(),
				'settings'   => $s::map(),
			), array( 'id', 'element_id', 'settings' ) ),
			'handler'      => array( $this, 'element_update' ),
		) );

		$r->register( 'elementor_element_insert', array(
			'category'     => 'elementor',
			'description'  => 'Insert an element (with any children) into a container/section/column, or at the top level when parent_id is omitted. position is a 0-based index (default: end). Example widget: {"elType":"widget","widgetType":"heading","settings":{"title":"Hello","header_size":"h2"}}.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'        => $s::int( 'Post ID.' ),
				'parent_id' => $s::str( 'Parent element ID; omit for top level.' ),
				'position'  => $s::int(),
				'element'   => $s::map(),
			), array( 'id', 'element' ) ),
			'handler'      => array( $this, 'element_insert' ),
		) );

		$r->register( 'elementor_element_delete', array(
			'category'     => 'elementor',
			'description'  => 'Remove an element (and its children) from a document.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'         => $s::int( 'Post ID.' ),
				'element_id' => $s::str(),
			), array( 'id', 'element_id' ) ),
			'handler'      => array( $this, 'element_delete' ),
		) );

		$r->register( 'elementor_text_replace', array(
			'category'     => 'elementor',
			'description'  => 'Find and replace text inside Elementor widget settings (headings, text, buttons, links…) across one post or every Elementor document. Dry run by default.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'search'  => $s::str(),
				'replace' => $s::str(),
				'ids'     => $s::arr( 'integer', 'Limit to these posts (default: all Elementor documents, including templates).' ),
				'dry_run' => $s::bool( '', true ),
			), array( 'search', 'replace' ) ),
			'handler'      => array( $this, 'text_replace' ),
		) );

		$r->register( 'elementor_templates_list', array(
			'category'     => 'elementor',
			'description'  => 'List saved Elementor templates (pages, sections, containers, headers, footers, popups…) with type. Edit them with the elementor_document_* / element_* tools using their ID.',
			'input_schema' => $s::obj( array(
				'type' => $s::str( 'Template type, e.g. page, section, container, header, footer, single-post, popup, kit.' ),
			) ),
			'handler'      => array( $this, 'templates_list' ),
		) );

		$r->register( 'elementor_kit_get', array(
			'category'     => 'elementor',
			'description'  => 'Get the active kit\'s global settings: global colors, global fonts, typography, buttons, form fields, layout (content width, breakpoints), site identity and custom CSS.',
			'input_schema' => $s::obj( array(
				'keys' => $s::arr( 'string', 'Only these settings keys, e.g. ["system_colors","custom_colors","system_typography","container_width"].' ),
			) ),
			'handler'      => array( $this, 'kit_get' ),
		) );

		$r->register( 'elementor_kit_update', array(
			'category'     => 'elementor',
			'description'  => 'Merge settings into the active kit (global colors, fonts, layout…). Arrays such as system_colors are replaced whole, so pass the full list. Regenerates CSS.',
			'writes'       => true,
			'input_schema' => $s::obj( array( 'settings' => $s::map() ), array( 'settings' ) ),
			'handler'      => array( $this, 'kit_update' ),
		) );

		$r->register( 'elementor_widgets_list', array(
			'category'     => 'elementor',
			'description'  => 'List registered widget types (name, title, categories). Pass name to get one widget\'s control definitions (setting keys, types, defaults, options).',
			'input_schema' => $s::obj( array(
				'name'   => $s::str(),
				'search' => $s::str(),
			) ),
			'handler'      => array( $this, 'widgets_list' ),
		) );

		$r->register( 'elementor_css_regenerate', array(
			'category'     => 'elementor',
			'description'  => 'Clear Elementor\'s generated CSS and data caches (Tools → Regenerate CSS & Data). Fixes stale styling.',
			'writes'       => true,
			'handler'      => array( $this, 'css_regenerate' ),
		) );
	}

	// ---------------------------------------------------------------
	// Tree helpers
	// ---------------------------------------------------------------

	private static function plugin() {
		return \Elementor\Plugin::$instance;
	}

	private static function document( $post_id ) {
		if ( ! get_post( (int) $post_id ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		$doc = self::plugin()->documents->get( (int) $post_id, false );
		return $doc ? $doc : new WP_Error( 'not_supported', 'Elementor does not support this post type.' );
	}

	private static function elements( $post_id ) {
		$raw  = get_post_meta( (int) $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $data ) ? $data : array();
	}

	private static function new_id() {
		return class_exists( '\Elementor\Utils' ) ? \Elementor\Utils::generate_random_string() : substr( md5( wp_generate_uuid4() ), 0, 7 );
	}

	/** Fill in missing ids / settings / elements so Elementor accepts the tree. */
	private static function normalize( array $elements ) {
		$out = array();
		foreach ( $elements as $el ) {
			$el = (array) $el;
			if ( empty( $el['id'] ) ) {
				$el['id'] = self::new_id();
			}
			if ( empty( $el['elType'] ) ) {
				$el['elType'] = ! empty( $el['widgetType'] ) ? 'widget' : 'container';
			}
			$el['settings'] = isset( $el['settings'] ) ? (object) (array) $el['settings'] : new stdClass();
			$el['elements'] = self::normalize( isset( $el['elements'] ) ? (array) $el['elements'] : array() );
			if ( ! isset( $el['isInner'] ) ) {
				$el['isInner'] = false;
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Walk the tree, calling $fn( &$element, $parent_list, $index ). Stops when
	 * $fn returns true.
	 */
	private static function walk( array &$elements, callable $fn ) {
		foreach ( $elements as $i => &$el ) {
			if ( $fn( $el, $elements, $i ) === true ) {
				return true;
			}
			if ( ! empty( $el['elements'] ) && self::walk( $el['elements'], $fn ) ) {
				return true;
			}
		}
		return false;
	}

	private static function save_elements( $post_id, array $elements, array $settings = array() ) {
		$doc = self::document( $post_id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$doc->set_is_built_with_elementor( true );
		$data = array( 'elements' => json_decode( wp_json_encode( self::normalize( $elements ) ), true ) );
		if ( $settings ) {
			$current          = get_post_meta( (int) $post_id, '_elementor_page_settings', true );
			$data['settings'] = array_replace_recursive( is_array( $current ) ? $current : array(), $settings );
		}
		if ( ! $doc->save( $data ) ) {
			return new WP_Error( 'save_failed', 'Elementor refused to save the document.' );
		}
		return true;
	}

	private static function snippet( array $settings ) {
		foreach ( self::TEXT_KEYS as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $settings[ $key ] ) ) );
				return mb_strlen( $text ) > 80 ? mb_substr( $text, 0, 80 ) . '…' : $text;
			}
		}
		return null;
	}

	private static function outline( array $elements ) {
		$out = array();
		foreach ( $elements as $el ) {
			$settings = isset( $el['settings'] ) ? (array) $el['settings'] : array();
			$row      = array(
				'id'   => $el['id'],
				'type' => $el['elType'] === 'widget' ? $el['widgetType'] : $el['elType'],
			);
			$text = self::snippet( $settings );
			if ( $text !== null && $text !== '' ) {
				$row['text'] = $text;
			}
			if ( isset( $settings['link']['url'] ) && $settings['link']['url'] !== '' ) {
				$row['link'] = $settings['link']['url'];
			}
			if ( isset( $settings['image']['url'] ) ) {
				$row['image'] = $settings['image']['url'];
			}
			if ( ! empty( $el['elements'] ) ) {
				$row['children'] = self::outline( $el['elements'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	// ---------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------

	public function status() {
		global $wpdb;
		$built = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_edit_mode' AND pm.meta_value = 'builder' AND p.post_type NOT IN ('revision','elementor_library') AND p.post_status <> 'trash'" );
		$types = $wpdb->get_results( "SELECT pm.meta_value AS type, COUNT(*) AS count FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_template_type' AND p.post_type = 'elementor_library' AND p.post_status <> 'trash' GROUP BY pm.meta_value", ARRAY_A );
		return array(
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'pro'             => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : false,
			'active_kit_id'   => (int) self::plugin()->kits_manager->get_active_id(),
			'built_documents' => $built,
			'templates'       => (object) wp_list_pluck( (array) $types, 'count', 'type' ),
			'supported_types' => (array) get_option( 'elementor_cpt_support', array( 'post', 'page' ) ),
		);
	}

	public function pages_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 50 );
		$query    = new WP_Query( array(
			'post_type'      => Site_Manager_Helpers::arg( $args, 'post_type', 'any' ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			's'              => (string) Site_Manager_Helpers::arg( $args, 'search', '' ),
			'meta_key'       => '_elementor_edit_mode',
			'meta_value'     => 'builder',
			'paged'          => $page,
			'posts_per_page' => $per_page,
		) );
		return Site_Manager_Helpers::paged( array_map( array( 'Site_Manager_Helpers', 'post_summary' ), $query->posts ), $query->found_posts, $page, $per_page );
	}

	public function document_get( array $args ) {
		$id = (int) $args['id'];
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		$elements = self::elements( $id );
		$full     = Site_Manager_Helpers::arg( $args, 'format', 'outline' ) === 'full';
		$settings = get_post_meta( $id, '_elementor_page_settings', true );
		return array(
			'id'                => $id,
			'title'             => get_the_title( $id ),
			'built_with'        => self::is_built_with( $id ) ? 'elementor' : 'not elementor',
			'template_type'     => get_post_meta( $id, '_elementor_template_type', true ) ?: null,
			'elementor_version' => get_post_meta( $id, '_elementor_version', true ) ?: null,
			'page_settings'     => (object) ( is_array( $settings ) ? $settings : array() ),
			'elements'          => $full ? $elements : self::outline( $elements ),
		);
	}

	public function document_save( array $args ) {
		$result = self::save_elements( (int) $args['id'], (array) $args['elements'], isset( $args['settings'] ) ? (array) $args['settings'] : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->document_get( array( 'id' => (int) $args['id'] ) );
	}

	public function element_get( array $args ) {
		$elements = self::elements( (int) $args['id'] );
		$found    = null;
		self::walk( $elements, function ( &$el ) use ( $args, &$found ) {
			if ( $el['id'] === (string) $args['element_id'] ) {
				$found = $el;
				return true;
			}
		} );
		return $found ? $found : new WP_Error( 'not_found', 'Element not found in this document.' );
	}

	public function element_update( array $args ) {
		$id       = (int) $args['id'];
		$elements = self::elements( $id );
		$updated  = null;
		self::walk( $elements, function ( &$el ) use ( $args, &$updated ) {
			if ( $el['id'] !== (string) $args['element_id'] ) {
				return false;
			}
			$settings = isset( $el['settings'] ) ? (array) $el['settings'] : array();
			foreach ( (array) $args['settings'] as $key => $value ) {
				if ( $value === null ) {
					unset( $settings[ $key ] );
				} else {
					$settings[ $key ] = $value;
				}
			}
			$el['settings'] = $settings;
			$updated        = $el;
			return true;
		} );
		if ( ! $updated ) {
			return new WP_Error( 'not_found', 'Element not found in this document.' );
		}
		$result = self::save_elements( $id, $elements );
		return is_wp_error( $result ) ? $result : array( 'element' => $updated );
	}

	public function element_insert( array $args ) {
		$id       = (int) $args['id'];
		$elements = self::elements( $id );
		$new      = self::normalize( array( (array) $args['element'] ) );
		$new      = json_decode( wp_json_encode( $new ), true );
		$position = isset( $args['position'] ) ? max( 0, (int) $args['position'] ) : null;

		$insert = function ( array &$list ) use ( $new, $position ) {
			if ( $position === null || $position >= count( $list ) ) {
				$list[] = $new[0];
			} else {
				array_splice( $list, $position, 0, $new );
			}
		};

		if ( empty( $args['parent_id'] ) ) {
			$insert( $elements );
		} else {
			$found = self::walk( $elements, function ( &$el ) use ( $args, $insert ) {
				if ( $el['id'] !== (string) $args['parent_id'] ) {
					return false;
				}
				if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
					$el['elements'] = array();
				}
				$insert( $el['elements'] );
				return true;
			} );
			if ( ! $found ) {
				return new WP_Error( 'not_found', 'parent_id not found in this document.' );
			}
		}
		$result = self::save_elements( $id, $elements );
		return is_wp_error( $result ) ? $result : array( 'inserted' => $new[0]['id'], 'element' => $new[0] );
	}

	public function element_delete( array $args ) {
		$id       = (int) $args['id'];
		$elements = self::elements( $id );
		$target   = (string) $args['element_id'];
		$removed  = false;
		$prune    = function ( array $list ) use ( &$prune, $target, &$removed ) {
			$out = array();
			foreach ( $list as $el ) {
				if ( $el['id'] === $target ) {
					$removed = true;
					continue;
				}
				if ( ! empty( $el['elements'] ) ) {
					$el['elements'] = $prune( $el['elements'] );
				}
				$out[] = $el;
			}
			return $out;
		};
		$elements = $prune( $elements );
		if ( ! $removed ) {
			return new WP_Error( 'not_found', 'Element not found in this document.' );
		}
		$result = self::save_elements( $id, $elements );
		return is_wp_error( $result ) ? $result : array( 'deleted' => $target );
	}

	public function text_replace( array $args ) {
		global $wpdb;
		$search  = (string) $args['search'];
		$replace = (string) $args['replace'];
		$dry     = Site_Manager_Helpers::bool( $args, 'dry_run', true );
		if ( $search === '' ) {
			return new WP_Error( 'empty_search', 'search must not be empty.' );
		}
		$ids = ! empty( $args['ids'] ) ? array_map( 'intval', (array) $args['ids'] ) : array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_elementor_data' AND pm.meta_value LIKE %s AND p.post_type <> 'revision' AND p.post_status NOT IN ('trash','auto-draft')",
			'%' . $wpdb->esc_like( trim( wp_json_encode( $search ), '"' ) ) . '%'
		) ) );

		$changes = array();
		foreach ( $ids as $post_id ) {
			$elements = self::elements( $post_id );
			$count    = 0;
			$replace_in = function ( $value ) use ( &$replace_in, $search, $replace, &$count ) {
				if ( is_string( $value ) ) {
					$n      = 0;
					$value  = str_replace( $search, $replace, $value, $n );
					$count += $n;
					return $value;
				}
				if ( is_array( $value ) ) {
					foreach ( $value as $k => $v ) {
						$value[ $k ] = $replace_in( $v );
					}
				}
				return $value;
			};
			self::walk( $elements, function ( &$el ) use ( $replace_in ) {
				if ( isset( $el['settings'] ) ) {
					$el['settings'] = $replace_in( (array) $el['settings'] );
				}
				return false;
			} );
			if ( ! $count ) {
				continue;
			}
			if ( ! $dry ) {
				$result = self::save_elements( $post_id, $elements );
				if ( is_wp_error( $result ) ) {
					$changes[] = array( 'id' => $post_id, 'error' => $result->get_error_message() );
					continue;
				}
			}
			$changes[] = array( 'id' => $post_id, 'title' => get_the_title( $post_id ), 'replacements' => $count );
		}
		return array( 'dry_run' => $dry, 'documents_affected' => count( $changes ), 'changes' => $changes );
	}

	public function templates_list( array $args ) {
		$q = array(
			'post_type'      => 'elementor_library',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 200,
		);
		if ( ! empty( $args['type'] ) ) {
			$q['meta_key']   = '_elementor_template_type';
			$q['meta_value'] = (string) $args['type'];
		}
		return array_map( function ( $p ) {
			$row         = Site_Manager_Helpers::post_summary( $p );
			$row['type'] = get_post_meta( $p->ID, '_elementor_template_type', true );
			$conditions  = get_post_meta( $p->ID, '_elementor_conditions', true );
			if ( $conditions ) {
				$row['conditions'] = $conditions;
			}
			return $row;
		}, get_posts( $q ) );
	}

	public function kit_get( array $args ) {
		$kit_id   = (int) self::plugin()->kits_manager->get_active_id();
		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? $settings : array();
		if ( ! empty( $args['keys'] ) ) {
			$settings = array_intersect_key( $settings, array_flip( (array) $args['keys'] ) );
		}
		return array( 'kit_id' => $kit_id, 'settings' => (object) $settings );
	}

	public function kit_update( array $args ) {
		$kit_id = (int) self::plugin()->kits_manager->get_active_id();
		$kit    = self::document( $kit_id );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		$current = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$current = is_array( $current ) ? $current : array();
		// Shallow merge: list-type settings (colors, fonts) are replaced whole.
		$kit->save( array( 'settings' => array_merge( $current, (array) $args['settings'] ) ) );
		self::plugin()->files_manager->clear_cache();
		return $this->kit_get( array( 'keys' => array_keys( (array) $args['settings'] ) ) );
	}

	public function widgets_list( array $args ) {
		$widgets = self::plugin()->widgets_manager->get_widget_types();
		if ( ! empty( $args['name'] ) ) {
			$widget = isset( $widgets[ $args['name'] ] ) ? $widgets[ $args['name'] ] : null;
			if ( ! $widget ) {
				return new WP_Error( 'not_found', 'Widget type not found.' );
			}
			$controls = array();
			foreach ( $widget->get_controls() as $key => $control ) {
				if ( in_array( $control['type'], array( 'section', 'tab', 'tabs', 'heading', 'divider', 'raw_html' ), true ) ) {
					continue;
				}
				$row = array( 'type' => $control['type'], 'label' => isset( $control['label'] ) ? wp_strip_all_tags( (string) $control['label'] ) : '' );
				if ( isset( $control['default'] ) && $control['default'] !== '' && $control['default'] !== array() ) {
					$row['default'] = $control['default'];
				}
				if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
					$row['options'] = array_keys( $control['options'] );
				}
				$controls[ $key ] = $row;
			}
			return array( 'name' => $widget->get_name(), 'title' => $widget->get_title(), 'controls' => $controls );
		}
		$search = strtolower( (string) Site_Manager_Helpers::arg( $args, 'search', '' ) );
		$out    = array();
		foreach ( $widgets as $name => $widget ) {
			if ( $search !== '' && strpos( strtolower( $name . ' ' . $widget->get_title() ), $search ) === false ) {
				continue;
			}
			$out[] = array( 'name' => $name, 'title' => $widget->get_title(), 'categories' => $widget->get_categories() );
		}
		return $out;
	}

	public function css_regenerate() {
		self::plugin()->files_manager->clear_cache();
		return array( 'cleared' => true );
	}
}

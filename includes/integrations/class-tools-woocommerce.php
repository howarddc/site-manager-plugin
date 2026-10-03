<?php
/**
 * MCP tools: WooCommerce.
 *
 * Built on WooCommerce's CRUD objects (WC_Product, WC_Order, WC_Customer,
 * WC_Coupon), so it works with both legacy post storage and HPOS order
 * tables. Store settings, tax rates, shipping zones and webhooks remain
 * reachable through rest_request on /wc/v3/….
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_WooCommerce {

	/** Product props that map 1:1 onto WC_Product::set_{prop}(). */
	const PRODUCT_PROPS = array(
		'name', 'slug', 'status', 'featured', 'catalog_visibility', 'description', 'short_description',
		'sku', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to',
		'tax_status', 'tax_class', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders',
		'low_stock_amount', 'sold_individually', 'weight', 'length', 'width', 'height',
		'upsell_ids', 'cross_sell_ids', 'parent_id', 'reviews_allowed', 'purchase_note', 'menu_order',
		'virtual', 'downloadable', 'shipping_class_id', 'image_id', 'gallery_image_ids',
		'product_url', 'button_text', 'children',
	);

	/** Order statuses counted as revenue in reports. */
	const PAID_STATUSES = array( 'completed', 'processing', 'on-hold' );

	public static function is_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public static function version() {
		return defined( 'WC_VERSION' ) ? WC_VERSION : true;
	}

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'woocommerce', __( 'WooCommerce', 'site-manager' ), __( 'Products, variations, stock, orders, refunds, customers, coupons, sales reports.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		add_filter( 'site_manager_instructions', function ( $lines ) {
			$lines[] = 'WooCommerce is active: use the wc_* tools for products, orders, customers, coupons and sales. Product categories and tags are the product_cat / product_tag taxonomies (term tools). For store settings, tax rates, shipping zones, payment gateways and webhooks use rest_request on /wc/v3/….';
			return $lines;
		} );

		$address = $s::map( 'Address fields: first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone.' );

		$product_props = array(
			'name'               => $s::str(),
			'type'               => $s::enum( array( 'simple', 'variable', 'grouped', 'external' ), 'Product type (create only).', 'simple' ),
			'status'             => $s::enum( array( 'publish', 'draft', 'pending', 'private' ) ),
			'slug'               => $s::str(),
			'description'        => $s::str( 'HTML.' ),
			'short_description'  => $s::str( 'HTML.' ),
			'sku'                => $s::str(),
			'regular_price'      => $s::str( 'Decimal string, e.g. "19.99".' ),
			'sale_price'         => $s::str( 'Empty string removes the sale price.' ),
			'date_on_sale_from'  => $s::str( 'Date (YYYY-MM-DD).' ),
			'date_on_sale_to'    => $s::str(),
			'manage_stock'       => $s::bool(),
			'stock_quantity'     => $s::int(),
			'stock_status'       => $s::enum( array( 'instock', 'outofstock', 'onbackorder' ) ),
			'backorders'         => $s::enum( array( 'no', 'notify', 'yes' ) ),
			'low_stock_amount'   => $s::int(),
			'sold_individually'  => $s::bool(),
			'weight'             => $s::str(),
			'length'             => $s::str(),
			'width'              => $s::str(),
			'height'             => $s::str(),
			'tax_status'         => $s::enum( array( 'taxable', 'shipping', 'none' ) ),
			'tax_class'          => $s::str(),
			'shipping_class_id'  => $s::int(),
			'virtual'            => $s::bool(),
			'downloadable'       => $s::bool(),
			'featured'           => $s::bool(),
			'catalog_visibility' => $s::enum( array( 'visible', 'catalog', 'search', 'hidden' ) ),
			'reviews_allowed'    => $s::bool(),
			'purchase_note'      => $s::str(),
			'menu_order'         => $s::int(),
			'categories'         => $s::arr( array( 'type' => array( 'integer', 'string' ) ), 'product_cat IDs or names (names are created if missing). Replaces existing.' ),
			'tags'               => $s::arr( array( 'type' => array( 'integer', 'string' ) ), 'product_tag IDs or names. Replaces existing.' ),
			'image_id'           => $s::int( 'Main image attachment ID (upload with media_upload first).' ),
			'gallery_image_ids'  => $s::arr( 'integer' ),
			'attributes'         => $s::arr( 'object', 'Attributes: [{"name":"Size","options":["S","M","L"],"visible":true,"variation":true}]. Use a global attribute by its taxonomy name, e.g. "pa_color". Replaces existing attributes.' ),
			'default_attributes' => $s::map( 'Variable products: default selection, e.g. {"pa_color":"blue","size":"M"}.' ),
			'upsell_ids'         => $s::arr( 'integer' ),
			'cross_sell_ids'     => $s::arr( 'integer' ),
			'product_url'        => $s::str( 'External products: buy URL.' ),
			'button_text'        => $s::str( 'External products: button label.' ),
			'children'           => $s::arr( 'integer', 'Grouped products: child product IDs.' ),
			'meta'               => $s::map( 'Product meta {key: value}; null deletes.' ),
		);

		$r->register( 'wc_store_info', array(
			'category'     => 'woocommerce',
			'description'  => 'WooCommerce overview: version, currency, base location, tax and unit settings, store pages, enabled payment gateways, shipping zones, product counts and order counts by status.',
			'handler'      => array( $this, 'store_info' ),
		) );

		$r->register( 'wc_products_list', array(
			'category'     => 'woocommerce',
			'description'  => 'List products with price, SKU, stock and categories. Filter by search, status, type, category, tag, SKU, stock status or on-sale.',
			'input_schema' => $s::obj( array(
				'search'       => $s::str(),
				'status'       => $s::str( 'publish, draft, pending, private, or any (default).' ),
				'type'         => $s::enum( array( 'simple', 'variable', 'grouped', 'external' ) ),
				'category'     => $s::str( 'product_cat slug.' ),
				'tag'          => $s::str( 'product_tag slug.' ),
				'sku'          => $s::str( 'Partial SKU match.' ),
				'stock_status' => $s::enum( array( 'instock', 'outofstock', 'onbackorder' ) ),
				'on_sale'      => $s::bool(),
				'featured'     => $s::bool(),
				'orderby'      => $s::enum( array( 'date', 'title', 'price', 'popularity', 'rating', 'menu_order', 'id' ), '', 'date' ),
				'order'        => $s::enum( array( 'ASC', 'DESC' ), '', 'DESC' ),
				'page'         => $s::page(),
				'per_page'     => $s::per_page( 20, 100 ),
			) ),
			'handler'      => array( $this, 'products_list' ),
		) );

		$r->register( 'wc_product_get', array(
			'category'     => 'woocommerce',
			'description'  => 'Get a product (by id or sku) with full details: pricing, inventory, shipping, attributes, images, categories, linked products, and every variation of a variable product.',
			'input_schema' => $s::obj( array(
				'id'  => $s::int(),
				'sku' => $s::str(),
			) ),
			'handler'      => array( $this, 'product_get' ),
		) );

		$r->register( 'wc_product_create', array(
			'category'     => 'woocommerce',
			'description'  => 'Create a product (simple, variable, grouped or external). For variable products, set attributes with variation=true, then add variations with wc_variation_save.',
			'writes'       => true,
			'input_schema' => $s::obj( $product_props, array( 'name' ) ),
			'handler'      => array( $this, 'product_create' ),
		) );

		$r->register( 'wc_product_update', array(
			'category'     => 'woocommerce',
			'description'  => 'Update a product. Only the fields you pass change.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'id' => $s::int() ), array_diff_key( $product_props, array( 'type' => true ) ) ), array( 'id' ) ),
			'handler'      => array( $this, 'product_update' ),
		) );

		$r->register( 'wc_product_delete', array(
			'category'     => 'woocommerce',
			'description'  => 'Trash a product or variation, or delete it permanently with force=true (also deletes a variable product\'s variations).',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array(
				'id'    => $s::int(),
				'force' => $s::bool( '', false ),
			), array( 'id' ) ),
			'handler'      => array( $this, 'product_delete' ),
		) );

		$r->register( 'wc_variation_save', array(
			'category'     => 'woocommerce',
			'description'  => 'Create (omit variation_id) or update a variation of a variable product. attributes maps attribute name → option, e.g. {"pa_color":"Blue","Size":"M"}; omit an attribute to mean "any".',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'product_id'     => $s::int( 'Parent variable product.' ),
				'variation_id'   => $s::int( 'Omit to create.' ),
				'attributes'     => $s::map(),
				'status'         => $s::enum( array( 'publish', 'private' ), 'private = disabled.' ),
				'sku'            => $s::str(),
				'regular_price'  => $s::str(),
				'sale_price'     => $s::str(),
				'manage_stock'   => $s::bool(),
				'stock_quantity' => $s::int(),
				'stock_status'   => $s::enum( array( 'instock', 'outofstock', 'onbackorder' ) ),
				'weight'         => $s::str(),
				'length'         => $s::str(),
				'width'          => $s::str(),
				'height'         => $s::str(),
				'image_id'       => $s::int(),
				'description'    => $s::str(),
				'virtual'        => $s::bool(),
				'downloadable'   => $s::bool(),
			), array( 'product_id' ) ),
			'handler'      => array( $this, 'variation_save' ),
		) );

		$r->register( 'wc_stock_update', array(
			'category'     => 'woocommerce',
			'description'  => 'Set or adjust stock for many products/variations at once, identified by id or sku. mode: set (default), increase, decrease.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'items' => $s::arr( 'object', '[{"sku":"TSHIRT-M","quantity":12},{"id":45,"quantity":3,"mode":"decrease"}]' ),
			), array( 'items' ) ),
			'handler'      => array( $this, 'stock_update' ),
		) );

		$r->register( 'wc_attributes_list', array(
			'category'     => 'woocommerce',
			'description'  => 'List global product attributes (Products → Attributes) with their terms.',
			'handler'      => array( $this, 'attributes_list' ),
		) );

		$r->register( 'wc_attribute_create', array(
			'category'     => 'woocommerce',
			'description'  => 'Create a global product attribute (e.g. Color) and optionally its terms.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'name'  => $s::str( 'Display name, e.g. "Color".' ),
				'slug'  => $s::str( 'Without the pa_ prefix. Defaults from name.' ),
				'terms' => $s::arr( 'string', 'Terms to add, e.g. ["Red","Blue"].' ),
			), array( 'name' ) ),
			'handler'      => array( $this, 'attribute_create' ),
		) );

		$r->register( 'wc_orders_list', array(
			'category'     => 'woocommerce',
			'description'  => 'List orders, newest first. Filter by status, customer (user ID or email), and date range.',
			'input_schema' => $s::obj( array(
				'status'   => $s::arr( 'string', 'e.g. ["processing","on-hold"]. Default: all.' ),
				'customer' => $s::any( 'Customer user ID or billing email.' ),
				'after'    => $s::str( 'Created on/after (YYYY-MM-DD).' ),
				'before'   => $s::str( 'Created on/before (YYYY-MM-DD).' ),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 20, 100 ),
			) ),
			'handler'      => array( $this, 'orders_list' ),
		) );

		$r->register( 'wc_order_get', array(
			'category'     => 'woocommerce',
			'description'  => 'Get an order with line items, totals, taxes, shipping, coupons, addresses, payment details, refunds and order notes.',
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'order_get' ),
		) );

		$r->register( 'wc_order_create', array(
			'category'     => 'woocommerce',
			'description'  => 'Create a manual order (like Orders → Add new). Totals are calculated from the products. Confirm details with the user first.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'customer_id'    => $s::int( 'Existing customer user ID (0 = guest).' ),
				'billing'        => $address,
				'shipping'       => $address,
				'line_items'     => $s::arr( 'object', '[{"product_id":12,"quantity":2},{"product_id":30,"variation_id":31,"quantity":1}]' ),
				'shipping_lines' => $s::arr( 'object', '[{"method_title":"Flat rate","total":"5.00"}]' ),
				'coupon_codes'   => $s::arr( 'string' ),
				'status'         => $s::str( 'pending (default), processing, on-hold, completed…' ),
				'payment_method' => $s::str( 'Gateway ID, e.g. "bacs", "cod".' ),
				'customer_note'  => $s::str(),
				'set_paid'       => $s::bool( 'Mark as paid (sets status to processing/completed).', false ),
			), array( 'line_items' ) ),
			'handler'      => array( $this, 'order_create' ),
		) );

		$r->register( 'wc_order_update', array(
			'category'     => 'woocommerce',
			'description'  => 'Update an order: change status (which sends the normal customer emails), add a private or customer-facing note, edit addresses, customer note, transaction ID or meta.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'             => $s::int(),
				'status'         => $s::str( 'pending, processing, on-hold, completed, cancelled, refunded, failed, or a custom status.' ),
				'note'           => $s::str( 'Add an order note.' ),
				'note_to_customer' => $s::bool( 'Email the note to the customer.', false ),
				'billing'        => $address,
				'shipping'       => $address,
				'customer_note'  => $s::str(),
				'transaction_id' => $s::str(),
				'meta'           => $s::map( 'Order meta {key: value}; null deletes.' ),
			), array( 'id' ) ),
			'handler'      => array( $this, 'order_update' ),
		) );

		$r->register( 'wc_order_refund', array(
			'category'     => 'woocommerce',
			'description'  => 'Refund an order (full or partial). By default only records the refund; refund_payment=true also refunds through the payment gateway (real money). Always confirm the amount with the user.',
			'writes'       => true,
			'destructive'  => true,
			'open_world'   => true,
			'input_schema' => $s::obj( array(
				'order_id'       => $s::int(),
				'amount'         => $s::str( 'Decimal string. Omit for the full remaining amount.' ),
				'reason'         => $s::str(),
				'refund_payment' => $s::bool( 'Send the refund through the gateway.', false ),
				'restock_items'  => $s::bool( 'Return line items to stock (full refunds only).', false ),
			), array( 'order_id' ) ),
			'handler'      => array( $this, 'order_refund' ),
		) );

		$r->register( 'wc_customers_list', array(
			'category'     => 'woocommerce',
			'description'  => 'List registered customers with order count and total spent.',
			'input_schema' => $s::obj( array(
				'search'   => $s::str( 'Name, email or username.' ),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 20, 100 ),
			) ),
			'handler'      => array( $this, 'customers_list' ),
		) );

		$r->register( 'wc_customer_get', array(
			'category'     => 'woocommerce',
			'description'  => 'Get a customer (by user ID or email) with billing/shipping addresses, order count, total spent and recent orders.',
			'input_schema' => $s::obj( array(
				'id'    => $s::int(),
				'email' => $s::str(),
			) ),
			'handler'      => array( $this, 'customer_get' ),
		) );

		$r->register( 'wc_customer_update', array(
			'category'     => 'woocommerce',
			'description'  => 'Update a customer\'s name, email, billing or shipping address.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'id'         => $s::int(),
				'first_name' => $s::str(),
				'last_name'  => $s::str(),
				'email'      => $s::str(),
				'billing'    => $address,
				'shipping'   => $address,
			), array( 'id' ) ),
			'handler'      => array( $this, 'customer_update' ),
		) );

		$coupon_props = array(
			'code'                   => $s::str(),
			'discount_type'          => $s::enum( array( 'percent', 'fixed_cart', 'fixed_product' ) ),
			'amount'                 => $s::str( 'Decimal string.' ),
			'description'            => $s::str(),
			'date_expires'           => $s::str( 'YYYY-MM-DD; empty string for no expiry.' ),
			'individual_use'         => $s::bool(),
			'free_shipping'          => $s::bool(),
			'exclude_sale_items'     => $s::bool(),
			'minimum_amount'         => $s::str(),
			'maximum_amount'         => $s::str(),
			'usage_limit'            => $s::int(),
			'usage_limit_per_user'   => $s::int(),
			'limit_usage_to_x_items' => $s::int(),
			'product_ids'            => $s::arr( 'integer' ),
			'excluded_product_ids'   => $s::arr( 'integer' ),
			'product_categories'     => $s::arr( 'integer' ),
			'excluded_product_categories' => $s::arr( 'integer' ),
			'email_restrictions'     => $s::arr( 'string' ),
		);

		$r->register( 'wc_coupons_list', array(
			'category'     => 'woocommerce',
			'description'  => 'List coupons with type, amount, usage and expiry.',
			'input_schema' => $s::obj( array(
				'search'   => $s::str( 'Coupon code search.' ),
				'page'     => $s::page(),
				'per_page' => $s::per_page( 50, 100 ),
			) ),
			'handler'      => array( $this, 'coupons_list' ),
		) );

		$r->register( 'wc_coupon_save', array(
			'category'     => 'woocommerce',
			'description'  => 'Create a coupon (omit id) or update one. Only fields you pass change.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( array( 'id' => $s::int( 'Omit to create.' ) ), $coupon_props ) ),
			'handler'      => array( $this, 'coupon_save' ),
		) );

		$r->register( 'wc_coupon_delete', array(
			'category'     => 'woocommerce',
			'description'  => 'Delete a coupon permanently.',
			'writes'       => true,
			'destructive'  => true,
			'input_schema' => $s::obj( array( 'id' => $s::int() ), array( 'id' ) ),
			'handler'      => array( $this, 'coupon_delete' ),
		) );

		$r->register( 'wc_sales_report', array(
			'category'     => 'woocommerce',
			'description'  => 'Sales summary for a date range: order count, gross and net sales, refunds, discounts, shipping, tax, items sold, average order value, daily totals and top products. Counts completed, processing and on-hold orders.',
			'input_schema' => $s::obj( array(
				'after'  => $s::str( 'Start date YYYY-MM-DD (default: 30 days ago).' ),
				'before' => $s::str( 'End date YYYY-MM-DD (default: today).' ),
				'top'    => $s::int( 'Number of top products.', array( 'default' => 10 ) ),
			) ),
			'handler'      => array( $this, 'sales_report' ),
		) );
	}

	// ---------------------------------------------------------------
	// Formatting
	// ---------------------------------------------------------------

	private static function date( $d ) {
		return $d instanceof WC_DateTime ? $d->date( 'Y-m-d H:i:s' ) : null;
	}

	private static function term_names( $ids, $taxonomy ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$t = get_term( (int) $id, $taxonomy );
			if ( $t && ! is_wp_error( $t ) ) {
				$out[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
			}
		}
		return $out;
	}

	private static function product_summary( WC_Product $p ) {
		return array(
			'id'             => $p->get_id(),
			'name'           => $p->get_name(),
			'type'           => $p->get_type(),
			'status'         => $p->get_status(),
			'sku'            => $p->get_sku(),
			'price'          => $p->get_price(),
			'regular_price'  => $p->get_regular_price(),
			'sale_price'     => $p->get_sale_price(),
			'stock_status'   => $p->get_stock_status(),
			'stock_quantity' => $p->get_stock_quantity(),
			'categories'     => wp_list_pluck( self::term_names( $p->get_category_ids(), 'product_cat' ), 'name' ),
			'total_sales'    => (int) $p->get_total_sales(),
			'link'           => $p->get_permalink(),
		);
	}

	private static function product_detail( WC_Product $p ) {
		$out = self::product_summary( $p ) + array(
			'slug'               => $p->get_slug(),
			'parent_id'          => $p->get_parent_id(),
			'description'        => $p->get_description(),
			'short_description'  => $p->get_short_description(),
			'featured'           => $p->get_featured(),
			'catalog_visibility' => $p->get_catalog_visibility(),
			'date_on_sale_from'  => self::date( $p->get_date_on_sale_from() ),
			'date_on_sale_to'    => self::date( $p->get_date_on_sale_to() ),
			'manage_stock'       => $p->get_manage_stock(),
			'backorders'         => $p->get_backorders(),
			'low_stock_amount'   => $p->get_low_stock_amount(),
			'sold_individually'  => $p->get_sold_individually(),
			'virtual'            => $p->get_virtual(),
			'downloadable'       => $p->get_downloadable(),
			'tax_status'         => $p->get_tax_status(),
			'tax_class'          => $p->get_tax_class(),
			'weight'             => $p->get_weight(),
			'dimensions'         => array( 'length' => $p->get_length(), 'width' => $p->get_width(), 'height' => $p->get_height() ),
			'shipping_class_id'  => $p->get_shipping_class_id(),
			'reviews_allowed'    => $p->get_reviews_allowed(),
			'average_rating'     => $p->get_average_rating(),
			'review_count'       => $p->get_review_count(),
			'purchase_note'      => $p->get_purchase_note(),
			'menu_order'         => $p->get_menu_order(),
			'categories'         => self::term_names( $p->get_category_ids(), 'product_cat' ),
			'tags'               => self::term_names( $p->get_tag_ids(), 'product_tag' ),
			'image'              => $p->get_image_id() ? array( 'id' => (int) $p->get_image_id(), 'url' => wp_get_attachment_url( $p->get_image_id() ) ) : null,
			'gallery'            => array_map( function ( $id ) {
				return array( 'id' => (int) $id, 'url' => wp_get_attachment_url( $id ) );
			}, $p->get_gallery_image_ids() ),
			'attributes'         => self::attributes_out( $p ),
			'default_attributes' => (object) $p->get_default_attributes(),
			'upsell_ids'         => $p->get_upsell_ids(),
			'cross_sell_ids'     => $p->get_cross_sell_ids(),
			'meta'               => self::public_meta( $p ),
			'edit_link'          => get_edit_post_link( $p->get_id(), 'raw' ),
		);
		if ( $p instanceof WC_Product_External ) {
			$out['product_url'] = $p->get_product_url();
			$out['button_text'] = $p->get_button_text();
		}
		if ( $p instanceof WC_Product_Grouped ) {
			$out['children'] = $p->get_children();
		}
		if ( $p instanceof WC_Product_Variable ) {
			$out['variations'] = array();
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$out['variations'][] = self::variation_out( $v );
				}
			}
		}
		return $out;
	}

	private static function variation_out( WC_Product_Variation $v ) {
		return array(
			'id'             => $v->get_id(),
			'attributes'     => (object) $v->get_attributes(),
			'status'         => $v->get_status(),
			'sku'            => $v->get_sku(),
			'price'          => $v->get_price(),
			'regular_price'  => $v->get_regular_price(),
			'sale_price'     => $v->get_sale_price(),
			'manage_stock'   => $v->get_manage_stock(),
			'stock_quantity' => $v->get_stock_quantity(),
			'stock_status'   => $v->get_stock_status(),
			'image_id'       => $v->get_image_id(),
		);
	}

	private static function attributes_out( WC_Product $p ) {
		$out = array();
		foreach ( $p->get_attributes() as $key => $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute ) {
				continue;
			}
			$out[] = array(
				'name'      => $attr->get_name(),
				'label'     => wc_attribute_label( $attr->get_name() ),
				'global'    => $attr->is_taxonomy(),
				'options'   => $attr->is_taxonomy() ? wp_list_pluck( (array) $attr->get_terms(), 'name' ) : $attr->get_options(),
				'visible'   => $attr->get_visible(),
				'variation' => $attr->get_variation(),
			);
		}
		return $out;
	}

	private static function public_meta( $object ) {
		$out = array();
		foreach ( $object->get_meta_data() as $m ) {
			$d = $m->get_data();
			if ( strpos( $d['key'], '_' ) !== 0 ) {
				$out[ $d['key'] ] = $d['value'];
			}
		}
		return (object) $out;
	}

	private static function address( WC_Order $o, $type ) {
		$out = array();
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $f ) {
			$getter = "get_{$type}_{$f}";
			if ( is_callable( array( $o, $getter ) ) ) {
				$out[ $f ] = $o->$getter();
			}
		}
		return $out;
	}

	private static function order_summary( WC_Order $o ) {
		return array(
			'id'             => $o->get_id(),
			'number'         => $o->get_order_number(),
			'status'         => $o->get_status(),
			'date_created'   => self::date( $o->get_date_created() ),
			'total'          => $o->get_total(),
			'currency'       => $o->get_currency(),
			'customer_id'    => $o->get_customer_id(),
			'customer'       => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
			'email'          => $o->get_billing_email(),
			'items'          => $o->get_item_count(),
			'payment_method' => $o->get_payment_method_title(),
		);
	}

	private static function order_detail( WC_Order $o ) {
		$items = array();
		foreach ( $o->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$items[] = array(
				'item_id'      => $item_id,
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => $item->get_name(),
				'sku'          => $product ? $product->get_sku() : null,
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
				'tax'          => $item->get_total_tax(),
			);
		}
		$shipping = array();
		foreach ( $o->get_shipping_methods() as $m ) {
			$shipping[] = array( 'method' => $m->get_method_title(), 'total' => $m->get_total() );
		}
		$fees = array();
		foreach ( $o->get_fees() as $f ) {
			$fees[] = array( 'name' => $f->get_name(), 'total' => $f->get_total() );
		}
		$refunds = array();
		foreach ( $o->get_refunds() as $refund ) {
			$refunds[] = array( 'id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'reason' => $refund->get_reason(), 'date' => self::date( $refund->get_date_created() ) );
		}
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) as $n ) {
			$notes[] = array( 'id' => (int) $n->id, 'date' => $n->date_created ? $n->date_created->date( 'Y-m-d H:i:s' ) : null, 'note' => wp_strip_all_tags( $n->content ), 'customer_note' => (bool) $n->customer_note, 'by' => $n->added_by );
		}
		return self::order_summary( $o ) + array(
			'date_paid'      => self::date( $o->get_date_paid() ),
			'date_completed' => self::date( $o->get_date_completed() ),
			'subtotal'       => $o->get_subtotal(),
			'discount_total' => $o->get_discount_total(),
			'shipping_total' => $o->get_shipping_total(),
			'total_tax'      => $o->get_total_tax(),
			'total_refunded' => $o->get_total_refunded(),
			'coupons'        => $o->get_coupon_codes(),
			'line_items'     => $items,
			'shipping_lines' => $shipping,
			'fees'           => $fees,
			'billing'        => self::address( $o, 'billing' ),
			'shipping'       => self::address( $o, 'shipping' ),
			'customer_note'  => $o->get_customer_note(),
			'transaction_id' => $o->get_transaction_id(),
			'refunds'        => $refunds,
			'notes'          => $notes,
			'meta'           => self::public_meta( $o ),
			'edit_link'      => $o->get_edit_order_url(),
		);
	}

	// ---------------------------------------------------------------
	// Store
	// ---------------------------------------------------------------

	public function store_info() {
		$gateways = array();
		foreach ( WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array() as $id => $g ) {
			if ( $g->enabled === 'yes' ) {
				$gateways[] = array( 'id' => $id, 'title' => $g->get_title() );
			}
		}
		$zones = array();
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			foreach ( WC_Shipping_Zones::get_zones() as $z ) {
				$zones[] = array(
					'id'      => (int) $z['id'],
					'name'    => $z['zone_name'],
					'methods' => array_values( array_map( function ( $m ) {
						return $m->get_title();
					}, $z['shipping_methods'] ) ),
				);
			}
		}
		$order_counts = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			$order_counts[ str_replace( 'wc-', '', $status ) ] = (int) wc_orders_count( str_replace( 'wc-', '', $status ) );
		}
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		return array(
			'version'               => WC_VERSION,
			'currency'              => get_woocommerce_currency(),
			'currency_symbol'       => html_entity_decode( get_woocommerce_currency_symbol() ),
			'base_location'         => wc_get_base_location(),
			'prices_include_tax'    => wc_prices_include_tax(),
			'taxes_enabled'         => wc_tax_enabled(),
			'weight_unit'           => get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'        => get_option( 'woocommerce_dimension_unit' ),
			'hpos'                  => $hpos,
			'pages'                 => array(
				'shop'      => wc_get_page_id( 'shop' ),
				'cart'      => wc_get_page_id( 'cart' ),
				'checkout'  => wc_get_page_id( 'checkout' ),
				'myaccount' => wc_get_page_id( 'myaccount' ),
				'terms'     => wc_get_page_id( 'terms' ),
			),
			'payment_gateways'      => $gateways,
			'shipping_zones'        => $zones,
			'products'              => array_filter( (array) wp_count_posts( 'product' ) ),
			'orders_by_status'      => $order_counts,
		);
	}

	// ---------------------------------------------------------------
	// Products
	// ---------------------------------------------------------------

	public function products_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args );
		$q        = array(
			'status'   => Site_Manager_Helpers::arg( $args, 'status', 'any' ) === 'any' ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $args['status'],
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => Site_Manager_Helpers::arg( $args, 'orderby', 'date' ),
			'order'    => Site_Manager_Helpers::arg( $args, 'order', 'DESC' ),
		);
		foreach ( array( 'type', 'sku', 'stock_status' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$q[ $key ] = $args[ $key ];
			}
		}
		if ( ! empty( $args['category'] ) ) {
			$q['category'] = array( (string) $args['category'] );
		}
		if ( ! empty( $args['tag'] ) ) {
			$q['tag'] = array( (string) $args['tag'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$q['s'] = (string) $args['search'];
		}
		if ( isset( $args['featured'] ) ) {
			$q['featured'] = Site_Manager_Helpers::bool( $args, 'featured' );
		}
		if ( Site_Manager_Helpers::bool( $args, 'on_sale' ) ) {
			$q['include'] = wc_get_product_ids_on_sale() ?: array( 0 );
		}
		if ( $q['orderby'] === 'price' ) {
			$q['orderby']  = 'meta_value_num';
			$q['meta_key'] = '_price';
		}
		$result = wc_get_products( $q );
		return Site_Manager_Helpers::paged( array_map( array( __CLASS__, 'product_summary' ), $result->products ), $result->total, $page, $per_page );
	}

	private function resolve_product( array $args ) {
		$id = ! empty( $args['id'] ) ? (int) $args['id'] : ( ! empty( $args['sku'] ) ? wc_get_product_id_by_sku( (string) $args['sku'] ) : 0 );
		$p  = $id ? wc_get_product( $id ) : null;
		return $p ? $p : new WP_Error( 'not_found', 'Product not found.' );
	}

	public function product_get( array $args ) {
		$p = $this->resolve_product( $args );
		return is_wp_error( $p ) ? $p : self::product_detail( $p );
	}

	/** IDs for term names/IDs in a taxonomy, creating missing names. */
	private static function term_ids( array $items, $taxonomy ) {
		$ids = array();
		foreach ( $items as $item ) {
			if ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
				continue;
			}
			$term = get_term_by( 'name', (string) $item, $taxonomy ) ?: get_term_by( 'slug', sanitize_title( $item ), $taxonomy );
			if ( ! $term ) {
				$new = wp_insert_term( (string) $item, $taxonomy );
				if ( is_wp_error( $new ) ) {
					continue;
				}
				$ids[] = (int) $new['term_id'];
			} else {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	private static function build_attributes( array $attrs ) {
		$out = array();
		$pos = 0;
		foreach ( $attrs as $a ) {
			$a       = (array) $a;
			$name    = isset( $a['name'] ) ? (string) $a['name'] : '';
			$options = isset( $a['options'] ) ? array_map( 'strval', (array) $a['options'] ) : array();
			if ( $name === '' ) {
				continue;
			}
			$attr     = new WC_Product_Attribute();
			$taxonomy = strpos( $name, 'pa_' ) === 0 ? $name : ( taxonomy_exists( 'pa_' . sanitize_title( $name ) ) ? 'pa_' . sanitize_title( $name ) : '' );
			if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attr->set_name( $taxonomy );
				$attr->set_options( self::term_ids( $options, $taxonomy ) );
			} else {
				$attr->set_id( 0 );
				$attr->set_name( $name );
				$attr->set_options( $options );
			}
			$attr->set_position( $pos++ );
			$attr->set_visible( isset( $a['visible'] ) ? (bool) $a['visible'] : true );
			$attr->set_variation( isset( $a['variation'] ) ? (bool) $a['variation'] : false );
			$out[] = $attr;
		}
		return $out;
	}

	/**
	 * Convert {"Color": "Blue"} into the stored form: taxonomy attributes
	 * keyed "pa_color" with the term slug; local ones keyed by sanitized name.
	 */
	private static function variation_attributes( array $attrs ) {
		$out = array();
		foreach ( $attrs as $name => $value ) {
			$name     = (string) $name;
			$taxonomy = strpos( $name, 'pa_' ) === 0 ? $name : 'pa_' . sanitize_title( $name );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term             = get_term_by( 'slug', sanitize_title( $value ), $taxonomy ) ?: get_term_by( 'name', (string) $value, $taxonomy );
				$out[ $taxonomy ] = $term ? $term->slug : sanitize_title( $value );
			} else {
				$out[ sanitize_title( $name ) ] = (string) $value;
			}
		}
		return $out;
	}

	private function apply_product( WC_Product $p, array $args ) {
		foreach ( self::PRODUCT_PROPS as $prop ) {
			if ( array_key_exists( $prop, $args ) && $args[ $prop ] !== null && is_callable( array( $p, "set_{$prop}" ) ) ) {
				$p->{"set_{$prop}"}( $args[ $prop ] );
			}
		}
		if ( isset( $args['categories'] ) ) {
			$p->set_category_ids( self::term_ids( (array) $args['categories'], 'product_cat' ) );
		}
		if ( isset( $args['tags'] ) ) {
			$p->set_tag_ids( self::term_ids( (array) $args['tags'], 'product_tag' ) );
		}
		if ( isset( $args['attributes'] ) ) {
			$p->set_attributes( self::build_attributes( (array) $args['attributes'] ) );
		}
		if ( isset( $args['default_attributes'] ) ) {
			$p->set_default_attributes( self::variation_attributes( (array) $args['default_attributes'] ) );
		}
		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $key => $value ) {
				if ( $value === null ) {
					$p->delete_meta_data( (string) $key );
				} else {
					$p->update_meta_data( (string) $key, $value );
				}
			}
		}
	}

	public function product_create( array $args ) {
		$type      = Site_Manager_Helpers::arg( $args, 'type', 'simple' );
		$classname = WC_Product_Factory::get_product_classname( 0, $type );
		if ( ! $classname || ! class_exists( $classname ) ) {
			return new WP_Error( 'invalid_type', sprintf( 'Unknown product type "%s".', $type ) );
		}
		$p = new $classname();
		if ( ! isset( $args['status'] ) ) {
			$args['status'] = 'draft';
		}
		$this->apply_product( $p, $args );
		$p->save();
		return self::product_detail( wc_get_product( $p->get_id() ) );
	}

	public function product_update( array $args ) {
		$p = $this->resolve_product( $args );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$this->apply_product( $p, $args );
		$p->save();
		if ( $p instanceof WC_Product_Variable ) {
			WC_Product_Variable::sync( $p->get_id() );
		}
		return self::product_detail( wc_get_product( $p->get_id() ) );
	}

	public function product_delete( array $args ) {
		$p = $this->resolve_product( $args );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$summary = self::product_summary( $p );
		$force   = Site_Manager_Helpers::bool( $args, 'force' );
		if ( $force && $p instanceof WC_Product_Variable ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$v->delete( true );
				}
			}
		}
		$p->delete( $force );
		if ( $p->get_parent_id() ) {
			WC_Product_Variable::sync( $p->get_parent_id() );
		}
		return array( 'deleted' => $force, 'trashed' => ! $force, 'product' => $summary );
	}

	public function variation_save( array $args ) {
		$parent = wc_get_product( (int) $args['product_id'] );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return new WP_Error( 'not_variable', 'product_id must be a variable product.' );
		}
		if ( ! empty( $args['variation_id'] ) ) {
			$v = wc_get_product( (int) $args['variation_id'] );
			if ( ! $v || $v->get_parent_id() !== $parent->get_id() ) {
				return new WP_Error( 'not_found', 'Variation not found on this product.' );
			}
		} else {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $parent->get_id() );
		}
		if ( isset( $args['attributes'] ) ) {
			$v->set_attributes( self::variation_attributes( (array) $args['attributes'] ) );
		}
		$this->apply_product( $v, array_intersect_key( $args, array_flip( array(
			'status', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status',
			'weight', 'length', 'width', 'height', 'image_id', 'description', 'virtual', 'downloadable',
		) ) ) );
		$v->save();
		WC_Product_Variable::sync( $parent->get_id() );
		return self::variation_out( wc_get_product( $v->get_id() ) );
	}

	public function stock_update( array $args ) {
		$results = array();
		foreach ( (array) $args['items'] as $item ) {
			$item = (array) $item;
			$p    = $this->resolve_product( $item );
			$ref  = isset( $item['sku'] ) ? $item['sku'] : ( isset( $item['id'] ) ? $item['id'] : '?' );
			if ( is_wp_error( $p ) ) {
				$results[] = array( 'ref' => $ref, 'error' => 'not found' );
				continue;
			}
			if ( ! $p->get_manage_stock() ) {
				$p->set_manage_stock( true );
				$p->save();
			}
			$mode      = isset( $item['mode'] ) && in_array( $item['mode'], array( 'increase', 'decrease' ), true ) ? $item['mode'] : 'set';
			$new       = wc_update_product_stock( $p, (int) $item['quantity'], $mode );
			$results[] = array( 'ref' => $ref, 'id' => $p->get_id(), 'stock_quantity' => $new );
		}
		return $results;
	}

	public function attributes_list() {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$taxonomy = wc_attribute_taxonomy_name( $a->attribute_name );
			$terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			$out[]    = array(
				'id'       => (int) $a->attribute_id,
				'name'     => $a->attribute_label,
				'taxonomy' => $taxonomy,
				'type'     => $a->attribute_type,
				'terms'    => is_wp_error( $terms ) ? array() : wp_list_pluck( $terms, 'name' ),
			);
		}
		return $out;
	}

	public function attribute_create( array $args ) {
		$id = wc_create_attribute( array(
			'name' => (string) $args['name'],
			'slug' => isset( $args['slug'] ) ? (string) $args['slug'] : sanitize_title( $args['name'] ),
		) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$attr     = wc_get_attribute( $id );
		$taxonomy = $attr->slug;
		// The taxonomy isn't registered until the next request; register it now to add terms.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false ) );
		}
		$added = array();
		foreach ( (array) Site_Manager_Helpers::arg( $args, 'terms', array() ) as $term ) {
			$t = wp_insert_term( (string) $term, $taxonomy );
			if ( ! is_wp_error( $t ) ) {
				$added[] = (string) $term;
			}
		}
		return array( 'id' => $id, 'name' => $attr->name, 'taxonomy' => $taxonomy, 'terms' => $added );
	}

	// ---------------------------------------------------------------
	// Orders
	// ---------------------------------------------------------------

	public function orders_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args );
		$q        = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => 'shop_order',
		);
		if ( ! empty( $args['status'] ) ) {
			$q['status'] = array_map( function ( $s ) {
				return 'wc-' . preg_replace( '/^wc-/', '', $s );
			}, (array) $args['status'] );
		}
		if ( ! empty( $args['customer'] ) ) {
			$q['customer'] = is_numeric( $args['customer'] ) ? (int) $args['customer'] : (string) $args['customer'];
		}
		$after  = Site_Manager_Helpers::arg( $args, 'after' );
		$before = Site_Manager_Helpers::arg( $args, 'before' );
		if ( $after || $before ) {
			$q['date_created'] = ( $after ? $after : '1970-01-01' ) . '...' . ( $before ? $before . ' 23:59:59' : gmdate( 'Y-m-d 23:59:59' ) );
		}
		$result = wc_get_orders( $q );
		return Site_Manager_Helpers::paged( array_map( array( __CLASS__, 'order_summary' ), $result->orders ), $result->total, $page, $per_page );
	}

	private function resolve_order( $id ) {
		$o = wc_get_order( (int) $id );
		return $o && ! $o instanceof WC_Order_Refund ? $o : new WP_Error( 'not_found', 'Order not found.' );
	}

	public function order_get( array $args ) {
		$o = $this->resolve_order( $args['id'] );
		return is_wp_error( $o ) ? $o : self::order_detail( $o );
	}

	private static function set_address( $object, $type, $fields ) {
		foreach ( (array) $fields as $key => $value ) {
			$setter = "set_{$type}_{$key}";
			if ( is_callable( array( $object, $setter ) ) ) {
				$object->$setter( (string) $value );
			}
		}
	}

	public function order_create( array $args ) {
		$order = wc_create_order( array( 'customer_id' => (int) Site_Manager_Helpers::arg( $args, 'customer_id', 0 ), 'created_via' => 'site-manager' ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$errors = array();
		foreach ( (array) $args['line_items'] as $line ) {
			$line    = (array) $line;
			$pid     = ! empty( $line['variation_id'] ) ? (int) $line['variation_id'] : (int) ( isset( $line['product_id'] ) ? $line['product_id'] : 0 );
			$product = $pid ? wc_get_product( $pid ) : ( ! empty( $line['sku'] ) ? wc_get_product( wc_get_product_id_by_sku( $line['sku'] ) ) : null );
			if ( ! $product ) {
				$errors[] = 'Product not found: ' . wp_json_encode( $line );
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				$errors[] = sprintf( 'Product %d is variable; pass the variation_id of the option being ordered.', $product->get_id() );
				continue;
			}
			$order->add_product( $product, max( 1, (int) ( isset( $line['quantity'] ) ? $line['quantity'] : 1 ) ) );
		}
		if ( ! empty( $args['billing'] ) ) {
			self::set_address( $order, 'billing', $args['billing'] );
		}
		if ( ! empty( $args['shipping'] ) ) {
			self::set_address( $order, 'shipping', $args['shipping'] );
		}
		foreach ( (array) Site_Manager_Helpers::arg( $args, 'shipping_lines', array() ) as $line ) {
			$line = (array) $line;
			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( isset( $line['method_title'] ) ? $line['method_title'] : 'Shipping' );
			$item->set_method_id( isset( $line['method_id'] ) ? $line['method_id'] : '' );
			$item->set_total( isset( $line['total'] ) ? $line['total'] : 0 );
			$order->add_item( $item );
		}
		if ( ! empty( $args['payment_method'] ) ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$order->set_payment_method( isset( $gateways[ $args['payment_method'] ] ) ? $gateways[ $args['payment_method'] ] : (string) $args['payment_method'] );
		}
		if ( isset( $args['customer_note'] ) ) {
			$order->set_customer_note( (string) $args['customer_note'] );
		}
		$order->calculate_totals();
		foreach ( (array) Site_Manager_Helpers::arg( $args, 'coupon_codes', array() ) as $code ) {
			$applied = $order->apply_coupon( (string) $code );
			if ( is_wp_error( $applied ) ) {
				$errors[] = $code . ': ' . $applied->get_error_message();
			}
		}
		if ( Site_Manager_Helpers::bool( $args, 'set_paid' ) ) {
			$order->payment_complete();
		}
		if ( ! empty( $args['status'] ) ) {
			$order->update_status( (string) $args['status'], 'Created via Site Manager.' );
		}
		$order->save();
		return array( 'order' => self::order_detail( wc_get_order( $order->get_id() ) ), 'warnings' => $errors );
	}

	public function order_update( array $args ) {
		$o = $this->resolve_order( $args['id'] );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		if ( ! empty( $args['billing'] ) ) {
			self::set_address( $o, 'billing', $args['billing'] );
		}
		if ( ! empty( $args['shipping'] ) ) {
			self::set_address( $o, 'shipping', $args['shipping'] );
		}
		if ( isset( $args['customer_note'] ) ) {
			$o->set_customer_note( (string) $args['customer_note'] );
		}
		if ( isset( $args['transaction_id'] ) ) {
			$o->set_transaction_id( (string) $args['transaction_id'] );
		}
		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $key => $value ) {
				if ( $value === null ) {
					$o->delete_meta_data( (string) $key );
				} else {
					$o->update_meta_data( (string) $key, $value );
				}
			}
		}
		$o->save();
		if ( ! empty( $args['status'] ) ) {
			$o->update_status( (string) $args['status'], '', true );
		}
		if ( ! empty( $args['note'] ) ) {
			$o->add_order_note( (string) $args['note'], Site_Manager_Helpers::bool( $args, 'note_to_customer' ) ? 1 : 0, true );
		}
		return self::order_detail( wc_get_order( $o->get_id() ) );
	}

	public function order_refund( array $args ) {
		$o = $this->resolve_order( $args['order_id'] );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		$remaining = (float) $o->get_total() - (float) $o->get_total_refunded();
		$amount    = isset( $args['amount'] ) && $args['amount'] !== '' ? (float) $args['amount'] : $remaining;
		if ( $amount <= 0 || $amount > $remaining + 0.001 ) {
			return new WP_Error( 'invalid_amount', sprintf( 'Refund amount must be between 0 and the remaining %s.', wc_format_decimal( $remaining, 2 ) ) );
		}
		$line_items = array();
		if ( Site_Manager_Helpers::bool( $args, 'restock_items' ) && abs( $amount - $remaining ) < 0.001 ) {
			foreach ( $o->get_items() as $item_id => $item ) {
				$line_items[ $item_id ] = array( 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => $item->get_taxes()['total'] ?? array() );
			}
		}
		$refund = wc_create_refund( array(
			'order_id'       => $o->get_id(),
			'amount'         => wc_format_decimal( $amount ),
			'reason'         => (string) Site_Manager_Helpers::arg( $args, 'reason', '' ),
			'refund_payment' => Site_Manager_Helpers::bool( $args, 'refund_payment' ),
			'restock_items'  => (bool) $line_items,
			'line_items'     => $line_items,
		) );
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		return array(
			'refund_id'      => $refund->get_id(),
			'amount'         => $refund->get_amount(),
			'via_gateway'    => Site_Manager_Helpers::bool( $args, 'refund_payment' ),
			'order_status'   => wc_get_order( $o->get_id() )->get_status(),
			'total_refunded' => wc_get_order( $o->get_id() )->get_total_refunded(),
		);
	}

	// ---------------------------------------------------------------
	// Customers
	// ---------------------------------------------------------------

	private static function customer_out( WC_Customer $c, $full = false ) {
		$out = array(
			'id'          => $c->get_id(),
			'email'       => $c->get_email(),
			'name'        => trim( $c->get_first_name() . ' ' . $c->get_last_name() ),
			'username'    => $c->get_username(),
			'orders'      => (int) $c->get_order_count(),
			'total_spent' => wc_format_decimal( $c->get_total_spent(), 2 ),
			'city'        => $c->get_billing_city(),
			'country'     => $c->get_billing_country(),
			'registered'  => self::date( $c->get_date_created() ),
		);
		if ( $full ) {
			$out['first_name'] = $c->get_first_name();
			$out['last_name']  = $c->get_last_name();
			$out['billing']    = $c->get_billing();
			$out['shipping']   = $c->get_shipping();
			$last              = $c->get_last_order();
			$out['last_order'] = $last ? self::order_summary( $last ) : null;
		}
		return $out;
	}

	public function customers_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args );
		$q        = array( 'role' => 'customer', 'number' => $per_page, 'paged' => $page, 'orderby' => 'registered', 'order' => 'DESC', 'count_total' => true );
		if ( ! empty( $args['search'] ) ) {
			$q['search'] = '*' . trim( (string) $args['search'], '*' ) . '*';
		}
		$query = new WP_User_Query( $q );
		$items = array_map( function ( $u ) {
			return self::customer_out( new WC_Customer( $u->ID ) );
		}, $query->get_results() );
		return Site_Manager_Helpers::paged( $items, $query->get_total(), $page, $per_page );
	}

	private function resolve_customer( array $args ) {
		$id = ! empty( $args['id'] ) ? (int) $args['id'] : 0;
		if ( ! $id && ! empty( $args['email'] ) ) {
			$user = get_user_by( 'email', (string) $args['email'] );
			$id   = $user ? $user->ID : 0;
		}
		if ( ! $id || ! get_userdata( $id ) ) {
			return new WP_Error( 'not_found', 'Customer not found (guest customers only exist on their orders — use wc_orders_list with customer=email).' );
		}
		return new WC_Customer( $id );
	}

	public function customer_get( array $args ) {
		$c = $this->resolve_customer( $args );
		return is_wp_error( $c ) ? $c : self::customer_out( $c, true );
	}

	public function customer_update( array $args ) {
		$c = $this->resolve_customer( $args );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		foreach ( array( 'first_name', 'last_name', 'email' ) as $f ) {
			if ( isset( $args[ $f ] ) ) {
				$c->{"set_{$f}"}( (string) $args[ $f ] );
			}
		}
		if ( ! empty( $args['billing'] ) ) {
			self::set_address( $c, 'billing', $args['billing'] );
		}
		if ( ! empty( $args['shipping'] ) ) {
			self::set_address( $c, 'shipping', $args['shipping'] );
		}
		$c->save();
		return self::customer_out( new WC_Customer( $c->get_id() ), true );
	}

	// ---------------------------------------------------------------
	// Coupons
	// ---------------------------------------------------------------

	private static function coupon_out( WC_Coupon $c ) {
		return array(
			'id'                     => $c->get_id(),
			'code'                   => $c->get_code(),
			'discount_type'          => $c->get_discount_type(),
			'amount'                 => $c->get_amount(),
			'description'            => $c->get_description(),
			'date_expires'           => self::date( $c->get_date_expires() ),
			'usage_count'            => $c->get_usage_count(),
			'usage_limit'            => $c->get_usage_limit(),
			'usage_limit_per_user'   => $c->get_usage_limit_per_user(),
			'individual_use'         => $c->get_individual_use(),
			'free_shipping'          => $c->get_free_shipping(),
			'exclude_sale_items'     => $c->get_exclude_sale_items(),
			'minimum_amount'         => $c->get_minimum_amount(),
			'maximum_amount'         => $c->get_maximum_amount(),
			'product_ids'            => $c->get_product_ids(),
			'excluded_product_ids'   => $c->get_excluded_product_ids(),
			'product_categories'     => $c->get_product_categories(),
			'email_restrictions'     => $c->get_email_restrictions(),
			'status'                 => get_post_status( $c->get_id() ),
		);
	}

	public function coupons_list( array $args ) {
		$page     = Site_Manager_Helpers::page( $args );
		$per_page = Site_Manager_Helpers::per_page( $args, 50 );
		$query    = new WP_Query( array(
			'post_type'      => 'shop_coupon',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			's'              => (string) Site_Manager_Helpers::arg( $args, 'search', '' ),
			'paged'          => $page,
			'posts_per_page' => $per_page,
			'fields'         => 'ids',
		) );
		$items = array_map( function ( $id ) {
			return self::coupon_out( new WC_Coupon( $id ) );
		}, $query->posts );
		return Site_Manager_Helpers::paged( $items, $query->found_posts, $page, $per_page );
	}

	public function coupon_save( array $args ) {
		if ( ! empty( $args['id'] ) ) {
			$c = new WC_Coupon( (int) $args['id'] );
			if ( ! $c->get_id() ) {
				return new WP_Error( 'not_found', 'Coupon not found.' );
			}
		} else {
			if ( empty( $args['code'] ) ) {
				return new WP_Error( 'missing_code', 'code is required to create a coupon.' );
			}
			if ( wc_get_coupon_id_by_code( (string) $args['code'] ) ) {
				return new WP_Error( 'exists', 'A coupon with that code already exists.' );
			}
			$c = new WC_Coupon();
		}
		$props = array( 'code', 'discount_type', 'amount', 'description', 'date_expires', 'individual_use', 'free_shipping', 'exclude_sale_items',
			'minimum_amount', 'maximum_amount', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'product_ids',
			'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'email_restrictions' );
		foreach ( $props as $prop ) {
			if ( array_key_exists( $prop, $args ) && $args[ $prop ] !== null ) {
				$value = $args[ $prop ];
				if ( $prop === 'date_expires' && $value === '' ) {
					$value = null;
				}
				$c->{"set_{$prop}"}( $value );
			}
		}
		$c->save();
		return self::coupon_out( new WC_Coupon( $c->get_id() ) );
	}

	public function coupon_delete( array $args ) {
		$c = new WC_Coupon( (int) $args['id'] );
		if ( ! $c->get_id() ) {
			return new WP_Error( 'not_found', 'Coupon not found.' );
		}
		$out = self::coupon_out( $c );
		$c->delete( true );
		return array( 'deleted' => $out );
	}

	// ---------------------------------------------------------------
	// Reports
	// ---------------------------------------------------------------

	public function sales_report( array $args ) {
		$tz     = wp_timezone();
		$after  = Site_Manager_Helpers::arg( $args, 'after', wp_date( 'Y-m-d', strtotime( '-30 days' ) ) );
		$before = Site_Manager_Helpers::arg( $args, 'before', wp_date( 'Y-m-d' ) );
		$start  = ( new DateTime( $after . ' 00:00:00', $tz ) )->getTimestamp();
		$end    = ( new DateTime( $before . ' 23:59:59', $tz ) )->getTimestamp();

		$ids = wc_get_orders( array(
			'type'         => 'shop_order',
			'status'       => array_map( function ( $s ) {
				return 'wc-' . $s;
			}, self::PAID_STATUSES ),
			'date_created' => $start . '...' . $end,
			'limit'        => 10000,
			'return'       => 'ids',
		) );

		$totals   = array( 'orders' => 0, 'gross' => 0.0, 'refunds' => 0.0, 'discounts' => 0.0, 'shipping' => 0.0, 'tax' => 0.0, 'items' => 0 );
		$daily    = array();
		$products = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$total = (float) $o->get_total();
			$day   = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d' ) : 'unknown';
			$totals['orders']++;
			$totals['gross']     += $total;
			$totals['refunds']   += (float) $o->get_total_refunded();
			$totals['discounts'] += (float) $o->get_discount_total();
			$totals['shipping']  += (float) $o->get_shipping_total();
			$totals['tax']       += (float) $o->get_total_tax();
			$daily[ $day ]        = ( isset( $daily[ $day ] ) ? $daily[ $day ] : 0 ) + $total;
			foreach ( $o->get_items() as $item ) {
				$pid = $item->get_product_id();
				$qty = (int) $item->get_quantity();
				$totals['items'] += $qty;
				if ( ! isset( $products[ $pid ] ) ) {
					$products[ $pid ] = array( 'product_id' => $pid, 'name' => $item->get_name(), 'quantity' => 0, 'revenue' => 0.0 );
				}
				$products[ $pid ]['quantity'] += $qty;
				$products[ $pid ]['revenue']  += (float) $item->get_total();
			}
		}
		usort( $products, function ( $a, $b ) {
			return $b['revenue'] <=> $a['revenue'];
		} );
		ksort( $daily );
		$round = function ( $v ) {
			return round( $v, 2 );
		};
		return array(
			'range'               => array( 'after' => $after, 'before' => $before ),
			'currency'            => get_woocommerce_currency(),
			'orders'              => $totals['orders'],
			'gross_sales'         => $round( $totals['gross'] ),
			'refunds'             => $round( $totals['refunds'] ),
			'net_sales'           => $round( $totals['gross'] - $totals['refunds'] - $totals['shipping'] - $totals['tax'] ),
			'discounts'           => $round( $totals['discounts'] ),
			'shipping'            => $round( $totals['shipping'] ),
			'tax'                 => $round( $totals['tax'] ),
			'items_sold'          => $totals['items'],
			'average_order_value' => $totals['orders'] ? $round( $totals['gross'] / $totals['orders'] ) : 0,
			'daily_gross'         => (object) array_map( $round, $daily ),
			'top_products'        => array_map( function ( $p ) use ( $round ) {
				$p['revenue'] = $round( $p['revenue'] );
				return $p;
			}, array_slice( $products, 0, max( 1, (int) Site_Manager_Helpers::arg( $args, 'top', 10 ) ) ) ),
			'truncated'           => count( $ids ) >= 10000,
		);
	}
}

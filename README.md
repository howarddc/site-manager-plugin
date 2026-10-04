# Site Manager

An admin-only [Model Context Protocol](https://modelcontextprotocol.io) server for WordPress. Connect Claude (claude.ai, Claude Desktop, Cowork, Claude Code) or any MCP client and manage the whole site: content and custom post types, custom fields, media, users, comments, settings, menus, themes and plugins — plus dedicated support for ACF, Yoast SEO, WooCommerce, Gravity Forms, WPForms, Contact Form 7, Redirection and Elementor.

- **Author:** Rob Howard — https://howard.ai
- **License:** GPL v2 or later
- **Requires:** WordPress 6.2+, PHP 7.4+

## Install

1. `./build.sh` → `builds/site-manager-<version>.zip`
2. WordPress → Plugins → Add New → Upload, then activate.
3. Settings → Site Manager → copy the MCP server URL:
   `https://example.com/wp-json/site-manager/v1/mcp`

## Connect

| Client | How |
| --- | --- |
| claude.ai / Desktop / Cowork | Settings → Connectors → Add custom connector → paste the URL. Approve in the browser. |
| Claude Code | `claude mcp add --transport http site-manager <URL>`, then `/mcp` to authenticate. |
| Anything else | HTTP Basic with a WordPress Application Password. |

Only users with `manage_options` (administrators) can approve a connection or call the endpoint. The capability is re-checked on every request, so demoting a user cuts off their tokens immediately.

## Activity log

Site Manager records every action WordPress can detect, by any user, cron, WP-CLI or MCP client, in its own table (`{prefix}site_manager_events`):

| Area | Events |
| --- | --- |
| Authentication | logins, failed logins (folded per username + IP), logouts, password reset requests |
| Users | created, deleted, profile/email changes, password changes, role changes (elevation to administrator is **critical**), application passwords, super admin |
| Updates | WordPress core, plugins, themes and translations with **old → new version**, manual vs automatic, and failed automatic updates |
| Plugins & themes | installed, activated, deactivated, deleted, auto-updates toggled, theme switched, Customizer published, built-in file editor used |
| Content | created, published, scheduled, updated (fields changed), unpublished, trashed, restored, permanently deleted — every post type with an admin UI, plus Additional CSS, global styles, templates; media uploads/edits/deletions; Elementor edits |
| Structure | categories/tags/custom terms, menus and menu items, widgets |
| Comments | moderation, edits, deletions, comments by logged-in users |
| Settings | core settings (security-relevant ones flagged), any plugin settings page saved through `options.php`, WooCommerce settings, Site Manager's own capability gates |
| Data leaving the site | WXR exports, personal-data exports and erasures, activity-log CSV exports |
| Integrations | WooCommerce order status changes and refunds, Gravity Forms forms/entries, Redirection redirects |
| MCP | every write tool run (with redacted arguments and the OAuth client or application-password name), client authorizations and revocations |

Each event stores time, actor (ID, login, role), IP (full / anonymized / off, optional proxy header), user agent, source (`admin`, `login`, `rest`, `mcp`, `cron`, `cli`, `ajax`, `xmlrpc`, `web`), severity (`info` → `critical`), the object acted on and before/after details. Repeated events (failed-login floods, rapid saves) fold into one row with an occurrence count.

- **Settings → Site Manager → Activity Log**: filter by date, category, severity, source, user or text, and export CSV.
- **MCP**: `activity_log_query`, `activity_stats`, and `activity_report`, which defaults to last calendar month or takes `month: "2026-09"`. The report covers update history with versions, plugin/theme changes, user changes, login stats, content changes, settings changes, security events and MCP usage.
- Retention defaults to 365 days (0 = forever). There is deliberately no "clear" button; exporting the log and disabling Site Manager are themselves logged as events.
- Developers can forward events (e.g. critical ones to Slack) with the `site_manager_activity_logged` action.

## Tools (204)

| Group | Tools |
| --- | --- |
| Site overview | `site_info`, `site_health`, `site_search` |
| Activity log | `activity_log_query`, `activity_stats`, `activity_report` |
| Content (any post type) | `post_types_list`, `post_list`, `post_get`, `post_create`, `post_update`, `post_delete`, `post_restore`, `post_duplicate`, `posts_bulk_update`, `content_search_replace`, `post_meta_get`, `post_meta_update`, `post_meta_delete`, `meta_keys_list`, `revisions_list`, `revision_get`, `revision_restore`, `post_blocks_get`, `post_blocks_update`, `block_types_list`, `block_patterns_list` |
| Taxonomies | `taxonomies_list`, `terms_list`, `term_get`, `term_create`, `term_update`, `term_delete`, `post_terms_set` |
| Media | `media_list`, `media_get`, `media_upload`, `media_update`, `media_delete`, `media_regenerate`, `image_sizes_list` |
| Users & roles | `users_list`, `user_get`, `user_create`, `user_update`, `user_delete`, `user_send_password_reset`, `user_sessions_destroy`, `roles_list`, `role_create`, `role_update`, `role_delete`, `application_passwords_list`, `application_password_revoke` |
| Comments | `comments_list`, `comment_get`, `comment_create`, `comment_update`, `comments_moderate`, `comment_delete` |
| Settings & options | `settings_get`, `settings_update`, `options_list`, `option_get`, `option_update`, `option_delete` |
| Menus & widgets | `menus_list`, `menu_get`, `menu_create`, `menu_update`, `menu_delete`, `menu_item_add`, `menu_item_update`, `menu_item_delete`, `menu_locations_set`, `sidebars_list` |
| Appearance | `themes_list`, `theme_activate`, `theme_install`, `theme_update`, `theme_delete`, `theme_mods_get`, `theme_mods_update`, `custom_css_get`, `custom_css_update`, `global_styles_get`, `global_styles_update` |
| Plugins & updates | `plugins_list`, `plugin_search`, `plugin_activate`, `plugin_deactivate`, `plugin_install`, `plugin_update`, `plugin_delete`, `plugin_auto_update_set`, `updates_check`, `core_update` |
| Maintenance | `cron_list`, `cron_run`, `cron_unschedule`, `cache_flush`, `transients_delete`, `rewrite_flush`, `email_send`, `debug_log_read` |
| Developer | `rest_routes_list`, `rest_request`, `db_tables_list`, `db_query`, `file_list`, `file_read`, `hooks_list`, `shortcodes_list`, plus gated `db_execute`, `file_write`, `file_delete`, `php_execute` |
| ACF (when active) | `acf_field_groups_list`, `acf_field_group_get`, `acf_field_group_save`, `acf_field_group_delete`, `acf_values_get`, `acf_values_update`, `acf_options_pages_list`, `acf_post_types_list`, `acf_post_type_save` |
| Yoast SEO (when active) | `yoast_post_get`, `yoast_post_update`, `yoast_posts_audit`, `yoast_term_get`, `yoast_term_update`, `yoast_settings_get`, `yoast_settings_update`, `yoast_redirects_list` |
| WooCommerce (when active) | `wc_store_info`, `wc_products_list`, `wc_product_get`, `wc_product_create`, `wc_product_update`, `wc_product_delete`, `wc_variation_save`, `wc_stock_update`, `wc_attributes_list`, `wc_attribute_create`, `wc_orders_list`, `wc_order_get`, `wc_order_create`, `wc_order_update`, `wc_order_refund`, `wc_customers_list`, `wc_customer_get`, `wc_customer_update`, `wc_coupons_list`, `wc_coupon_save`, `wc_coupon_delete`, `wc_sales_report` |
| Gravity Forms (when active) | `gf_forms_list`, `gf_form_get`, `gf_form_save`, `gf_form_set_active`, `gf_form_delete`, `gf_entries_list`, `gf_entry_get`, `gf_entry_create`, `gf_entry_update`, `gf_entry_delete`, `gf_entry_resend_notifications` |
| WPForms (when active) | `wpforms_forms_list`, `wpforms_form_get`, `wpforms_form_save`, `wpforms_form_delete`, `wpforms_entries_list`, `wpforms_entry_get` (entries need Pro) |
| Contact Form 7 (when active) | `cf7_forms_list`, `cf7_form_get`, `cf7_form_save`, `cf7_form_duplicate`, `cf7_form_delete`, `cf7_submissions_list` (submissions need Flamingo) |
| Redirection (when active) | `redirection_setup`, `redirection_list`, `redirection_create`, `redirection_bulk_create`, `redirection_update`, `redirection_delete`, `redirection_groups`, `redirection_404s`, `redirection_404s_clear`, `redirection_log`, `redirection_test_url` |
| Elementor (when active) | `elementor_status`, `elementor_pages_list`, `elementor_document_get`, `elementor_document_save`, `elementor_element_get`, `elementor_element_update`, `elementor_element_insert`, `elementor_element_delete`, `elementor_text_replace`, `elementor_templates_list`, `elementor_kit_get`, `elementor_kit_update`, `elementor_widgets_list`, `elementor_css_regenerate` |

`post_create` / `post_update` also accept `terms`, `meta`, `acf`, `yoast`, `featured_image_id` and `template`, so one call can fully set up a post of any type.

`rest_request` calls any REST route on the site internally as the connected admin, which reaches every other plugin with a REST API (and WooCommerce settings, tax rates, shipping zones and webhooks via `/wc/v3`).

Integration tools only appear when their plugin is active. Elementor pages keep their layout in an element tree rather than `post_content`; `post_get` flags them with `page_builder: "elementor"` and the `elementor_element_*` tools edit individual widgets.

## Safety

- Tool groups can be switched off in **Settings → Site Manager → Tools**.
- **Database writes**, **file writes** and **PHP execution** are off by default. File writes are limited to `wp-content`, PHP files are syntax-checked first, and a `.bak` is kept. `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` force file writes off.
- `wp-config.php` and `.env` files are never readable.
- Destructive tools carry MCP `destructiveHint` annotations so clients ask before running them; several also take `dry_run`.
- Guards: can't delete or demote yourself, strip administrator capabilities, deactivate/delete Site Manager, or change `siteurl` / `home` without an explicit confirm flag.
- Every MCP write and every error is recorded in **MCP Calls** (secrets redacted). Reads can be logged too. Write tool runs also appear in the site **Activity Log**.
- **Connections** lists OAuth clients and revokes them instantly.

## Extending

Register more tools from another plugin or an mu-plugin:

```php
add_action( 'site_manager_register_tools', function ( Site_Manager_Registry $r ) {
	$r->add_category( 'acme', 'Acme', 'Acme-specific tools.' );
	$r->register( 'acme_ping', array(
		'category'     => 'acme',
		'description'  => 'Say hello.',
		'input_schema' => Site_Manager_Schema::obj( array( 'name' => Site_Manager_Schema::str() ) ),
		'handler'      => function ( $args ) {
			return array( 'hello' => $args['name'] ?? 'world' );
		},
	) );
} );
```

## Development

See [AGENTS.md](AGENTS.md).

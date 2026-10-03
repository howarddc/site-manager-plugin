# Site Manager

An admin-only [Model Context Protocol](https://modelcontextprotocol.io) server for WordPress. Connect Claude (claude.ai, Claude Desktop, Cowork, Claude Code) or any MCP client and manage the whole site: content and custom post types, custom fields, media, users, comments, settings, menus, themes, plugins, ACF and Yoast SEO.

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

## Tools (131)

| Group | Tools |
| --- | --- |
| Site overview | `site_info`, `site_health`, `site_search` |
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

`post_create` / `post_update` also accept `terms`, `meta`, `acf`, `yoast`, `featured_image_id` and `template`, so one call can fully set up a post of any type.

`rest_request` calls any REST route on the site internally as the connected admin, which reaches every plugin with a REST API (WooCommerce, Gravity Forms, …) without dedicated tools.

## Safety

- Tool groups can be switched off in **Settings → Site Manager → Tools**.
- **Database writes**, **file writes** and **PHP execution** are off by default. File writes are limited to `wp-content`, PHP files are syntax-checked first, and a `.bak` is kept. `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` force file writes off.
- `wp-config.php` and `.env` files are never readable.
- Destructive tools carry MCP `destructiveHint` annotations so clients ask before running them; several also take `dry_run`.
- Guards: can't delete or demote yourself, strip administrator capabilities, deactivate/delete Site Manager, or change `siteurl` / `home` without an explicit confirm flag.
- Every write and every error is recorded in **Activity** (secrets redacted). Reads can be logged too.
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

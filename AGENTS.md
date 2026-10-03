# AGENTS.md

Working notes for AI coding agents (humans welcome). Not included in built zips.

## Project

**Site Manager** — a WordPress plugin that exposes the whole site to MCP clients (Claude). Admin-only. Author Rob Howard (https://howard.ai), GPL v2+. Plugin slug / text domain: `site-manager`. The repo folder name (`site-manager-plugin`) differs from the slug; `build.sh` stages into `site-manager/`.

The MCP + OAuth layer is modeled on the sister plugin `myiwai-improvements` (`includes/mcp/`), generalized and hardened:

- Admin-only (`manage_options`) at the endpoint, at OAuth consent, and at token exchange/refresh.
- Bearer tokens resolve via `determine_current_user`, scoped to the MCP route only.
- Tools grouped in categories that admins can switch off; dangerous tools behind setting "gates".
- Activity log table; OAuth connections list with revoke.

## Layout

```
site-manager.php                 Bootstrap, activation, init wiring
includes/class-settings.php      Single option `site_manager_settings`; gates
includes/class-log.php           {prefix}site_manager_log
includes/class-schema.php        Terse JSON Schema builders (Site_Manager_Schema::str() etc.)
includes/class-helpers.php       Formatting + shared write helpers (apply_post_extras, apply_meta…)
includes/mcp/class-registry.php  Tool + category registry
includes/mcp/class-server.php    JSON-RPC over POST /wp-json/site-manager/v1/mcp
includes/oauth/                  Discovery (.well-known), DCR/authorize/token, token store
includes/admin/class-admin.php   Settings → Site Manager (Connect / Tools / Connections / Activity)
includes/tools/                  Core WordPress tool classes, one per category
includes/integrations/           ACF, Yoast, WooCommerce, Gravity Forms, WPForms, CF7, Redirection, Elementor
                                 (registered only when the plugin is active)
```

## Conventions

- Each tool class takes the registry in its constructor, calls `add_category()` once, then `register()` per tool. Tools register on `init` priority 99 so CPTs, ACF and Yoast are loaded.
- Tool definition keys: `category`, `description` (written for the model), `input_schema`, `handler`, `writes`, `destructive`, `open_world`, `gate`, `capability` (default `manage_options`).
- Handlers take `array $args`, return an array/scalar or `WP_Error`. The server JSON-encodes into a text content block and logs writes/errors.
- Use `wp_slash()` on anything passed to `wp_insert_post`, `wp_update_post`, `update_metadata`, etc.
- Empty object schemas: `Site_Manager_Schema::obj()` — the server converts empty `properties` to `{}`. For "any type" array items use `new stdClass()`, never `array()`.
- Add a new integration as `includes/integrations/class-tools-<name>.php` with static `is_active()` and `version()`, require it from `site-manager.php`, and add it to `site_manager_integrations()`. In the constructor, add a line of model guidance via the `site_manager_instructions` filter.
- Prefer a plugin's own model/CRUD layer (WC_Product, GFAPI, Red_Item, Elementor Document::save) over raw meta writes, so caches, hooks and CSS regeneration still happen.
- Third parties can add tools via the `site_manager_register_tools` action.

## Testing

No test suite yet. A disposable local WordPress works well:

1. Download WordPress + the `sqlite-database-integration` plugin; copy its `db.copy` to `wp-content/db.php`.
2. `wp core install`, symlink this repo to `wp-content/plugins/site-manager`, activate.
3. `php -S 127.0.0.1:8899 router.php` (router that falls back to `index.php`).
4. Create an Application Password and POST JSON-RPC to `/wp-json/site-manager/v1/mcp` with Basic auth.
5. With many plugins active, run WP-CLI with `php -d memory_limit=1G`.

Note: `php -S` is single-threaded, so loopback requests (`redirection_test_url`, Site Health loopback tests) time out there but work on a real server.

Count tools: `grep -rho "register( '" includes/tools includes/integrations | wc -l`

## Release

Bump `Version:` and `SITE_MANAGER_VERSION` in `site-manager.php` and `Stable tag` in `readme.txt`, then `./build.sh`.

## Ideas / next

- More integrations: Rank Math, WP Rocket, UpdraftPlus, The Events Calendar, LearnDash, MemberPress, Beaver Builder / Divi.
- Gravity Forms tools have not yet been run against a live Gravity Forms install (commercial plugin).
- Multisite network tools.
- MCP resources (e.g. expose posts as resources) and prompts.

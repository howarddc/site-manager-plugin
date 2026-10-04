# AGENTS.md

Working notes for contributors — AI coding agents and humans. User-facing documentation is in [README.md](README.md). This file is not included in release zips.

## Project

**Site Manager** is a WordPress plugin that exposes a whole site to MCP clients such as Claude. It's admin-only. Author: Rob Howard ([howard.ai](https://howard.ai)). License: GPL v2 or later.

- **Slug and text domain:** `site-manager`. The repository folder (`site-manager-plugin`) differs from the slug; `build.sh` packages everything into a `site-manager/` folder. Keep the slug and the main file name: the update checker and existing installs depend on `site-manager/site-manager.php`.
- **Class prefix:** `Site_Manager_`. **Option, table, hook and nonce prefix:** `site_manager_`. **REST namespace:** `site-manager/v1`.
- **Supported:** WordPress 6.2+, PHP 7.4+. Don't use PHP 8-only syntax or functions: `match`, `?->`, named arguments, union types, attributes, `str_contains()` / `str_starts_with()`, and so on.
- The MCP and OAuth layer started from the `myiwai-improvements` plugin's `includes/mcp/` and was generalized: admin-only at every step, bearer tokens scoped to the MCP route, tool groups that can be switched off, and capability gates.

## Layout

```
site-manager.php                 Bootstrap: requires, activation/upgrade, hooks, site_manager_integrations()
uninstall.php                    Drops tables, options and archived reports
build.sh                         Builds builds/site-manager-<version>.zip (bundles the update checker; checks versions)
.gitattributes                   export-ignore rules: what stays out of the zip
.github/workflows/lint.yml       CI: PHP 7.4/8.4 syntax check, build check
bin/generate-tool-docs.php       Regenerates docs/TOOLS.md from the live registry
docs/TOOLS.md                    Generated tool reference (don't edit by hand)
docs/ACTIVITY-LOG.md             Activity log event catalog (keep in sync with the hooks)
includes/class-settings.php      Option site_manager_settings; capability gates
includes/class-log.php           MCP Calls log ({prefix}site_manager_log); argument redaction
includes/class-schema.php        Terse JSON Schema builders (Site_Manager_Schema::str() etc.)
includes/class-helpers.php       Formatting and shared write helpers (post_detail, apply_post_extras, apply_meta…)
includes/mcp/class-registry.php  Tool and category registry
includes/mcp/class-server.php    JSON-RPC endpoint POST /wp-json/site-manager/v1/mcp; bearer auth; logging
includes/oauth/                  .well-known discovery, DCR/authorize/token endpoints, token store
includes/admin/class-admin.php   Settings → Site Manager: Connect, Tools, Connections, Reports, Activity Log, MCP Calls
includes/tools/                  Core WordPress tools, one class per group
includes/integrations/           ACF, Yoast, WooCommerce, Gravity Forms, WPForms, CF7, Redirection, Elementor
includes/activity/               Activity log: storage/query/report (class-activity.php) and WP hooks (class-activity-hooks.php)
includes/reports/                Client reports: PDF writer, data/archive/email/schedule, renderer
```

## Conventions

**Tools**

- Each tool class takes the registry in its constructor, calls `add_category()` once, then `register()` for each tool. Tools register on `init` at priority 99 so custom post types and integration plugins are loaded.
- Definition keys: `category`, `description` (written for the model — say what it does, when to use it and any gotchas), `input_schema`, `handler`, `writes`, `destructive`, `open_world`, `gate`, `capability` (default `manage_options`; the endpoint always requires `manage_options` as well).
- Handlers take `array $args` and return a JSON-serializable value or `WP_Error`. The server wraps the result in a text content block and logs writes and errors to both the MCP Calls log and the activity log.
- Use `wp_slash()` on anything passed to `wp_insert_post()`, `wp_update_post()`, `update_metadata()` and similar.
- Empty object schemas: `Site_Manager_Schema::obj()` — the server converts empty `properties` to `{}`. For "any type" array items pass `new stdClass()`, never `array()` (it encodes as `[]`, which is invalid JSON Schema).
- Destructive tools should accept `dry_run` where practical, and refuse obviously dangerous cases (self-deletion, removing admin capabilities, site URL changes) unless a `confirm` flag is passed.
- After adding or changing tools, regenerate `docs/TOOLS.md` (see Testing).

**Integrations**

- Add `includes/integrations/class-tools-<name>.php` with static `is_active()` and `version()`, require it from `site-manager.php`, and add it to `site_manager_integrations()`.
- In the constructor, add one line of model guidance through the `site_manager_instructions` filter.
- Prefer the plugin's own model or CRUD layer (`WC_Product`, `GFAPI`, `Red_Item`, Elementor's `Document::save()`) over raw meta or SQL writes, so its caches, hooks and CSS regeneration still run.

**Activity log**

- Log with `Site_Manager_Activity::log( array( 'category', 'action', 'message', 'severity', 'object_type', 'object_id', 'object_name', 'details', 'dedupe' ) )`. Logging never throws.
- Capture anything an action destroys (names, versions) on the "before" hook and log on the "after" hook — see the plugin and theme deletion handlers.
- Use `dedupe` (seconds) for events that fire repeatedly; folded rows merge their `details`.
- Hooks for third-party plugins live in `Site_Manager_Activity_Hooks` and cost nothing when the plugin is absent.
- Post types without an admin UI are skipped unless listed in `APPEARANCE_POST_TYPES` or `FORM_POST_TYPES`.
- Update `docs/ACTIVITY-LOG.md` whenever you add or change an event.

**Reports**

- `Site_Manager_Report::data()` gathers everything as plain arrays (also returned by MCP); `Site_Manager_Report_Renderer` lays it out; `Site_Manager_PDF` is a minimal writer (Helvetica / Helvetica-Bold, WinAnsi encoding, JPEG images, Flate streams).
- The character widths in `Site_Manager_PDF::WIDTHS` were generated from Adobe's Helvetica AFM metrics — don't edit them by hand.
- To add a section: add a key to `Site_Manager_Report::sections()`, add its data in `data()`, and add a `section_<key>()` method to the renderer.
- Client reports must not show agency internals (MCP activity, Site Manager settings).

**Schema changes**

- Bump `SITE_MANAGER_DB_VERSION` when a table changes; `site_manager_install()` runs `dbDelta()` on the next page load.

## Testing

There's no automated test suite yet. A disposable local WordPress works well:

1. Download WordPress and the `sqlite-database-integration` plugin. Copy the plugin's `db.copy` to `wp-content/db.php` and replace its two placeholders.
2. Run `wp core install`, symlink this repository to `wp-content/plugins/site-manager`, and activate it. Install the integration plugins you need (all are on WordPress.org except Gravity Forms).
3. Serve it with `php -S 127.0.0.1:8899 router.php`, using a router script that serves real files and falls back to `index.php`.
4. Create an Application Password and POST JSON-RPC to `/wp-json/site-manager/v1/mcp` with Basic auth.

Notes:

- `WP_ENVIRONMENT_TYPE=local` allows Application Passwords without HTTPS.
- With many plugins active, run WP-CLI with `php -d memory_limit=1G`.
- `php -S` is single-threaded, so loopback requests (`redirection_test_url`, Site Health loopback tests) time out there; they work on a real server.
- To inspect outgoing email, add a must-use plugin that returns `true` from `pre_wp_mail` and writes `$atts` (and attachments) to disk.
- Check `wp-content/debug.log` (with `WP_DEBUG_LOG`) for notices from `includes/` after every change.
- Regenerate the tool reference with every integration active:

  ```bash
  wp eval-file wp-content/plugins/site-manager/bin/generate-tool-docs.php
  ```

  The script reports any integration that wasn't active. Gravity Forms is commercial; on a site without it, a must-use plugin that defines empty `GFAPI` and `GFForms` classes is enough for its tools to register for documentation.

## Release

Releases are GitHub releases; installed copies update from them automatically through the bundled [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (pinned in `build.sh`). The checker compares the plugin header `Version` with the latest release's tag and installs only the attached `site-manager-<version>.zip` (`REQUIRE_RELEASE_ASSETS`), so **every release must have the built zip attached** — a release without it is ignored — and the tag must be `v<version>`. If you upgrade the pinned library version, update the `v5p7` class reference in `site-manager.php`.

1. Bump the version in all four places — `build.sh` refuses to build if they differ:
   - `Version:` in the `site-manager.php` header
   - `SITE_MANAGER_VERSION` in `site-manager.php`
   - `- **Version:**` in `README.md`
   - a new `## [x.y.z] — YYYY-MM-DD` section at the top of `CHANGELOG.md` (and its compare link at the bottom)
2. Regenerate `docs/TOOLS.md` if tools changed.
3. Commit and push to `main`, then tag: `git tag -a vX.Y.Z -m "Site Manager X.Y.Z" && git push origin vX.Y.Z`.
4. Build from the tag: `./build.sh vX.Y.Z` → `builds/site-manager-X.Y.Z.zip`.
5. Create the release with the zip attached:

   ```bash
   gh release create vX.Y.Z builds/site-manager-X.Y.Z.zip --title "Site Manager X.Y.Z" --notes-file notes.md
   ```

   Publish it as a normal (non-draft, non-prerelease) release — drafts and prereleases are ignored by the update checker.
6. Verify: on a site running the previous version, `wp plugin list` (or Dashboard → Updates → Check again) shows the new version, and updating installs it.

Don't commit `vendor/` or `builds/` (both are in `.gitignore`).

## Ideas

- Alerts: forward critical activity events to Slack, email or a webhook (`site_manager_activity_logged` already exists).
- Safe updates with automatic rollback, and backup plugin integrations.
- Site audit: vulnerable or abandoned plugins, SSL expiry, PHP end-of-life, unused admin accounts.
- More integrations: Rank Math, WP Rocket, LiteSpeed Cache, Wordfence, UpdraftPlus, The Events Calendar, LearnDash, MemberPress, Beaver Builder, Divi.
- Run the Gravity Forms tools against a live Gravity Forms install.
- Multisite network tools; MCP resources and prompts.

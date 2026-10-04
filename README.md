# Site Manager

An admin-only [Model Context Protocol](https://modelcontextprotocol.io) (MCP) server for WordPress. Connect Claude — or any MCP client — and manage an entire WordPress site in conversation: content and custom post types, custom fields, media, users, settings, menus, themes, plugins and updates, with first-class support for popular plugins. Site Manager also keeps a site-wide audit log and emails branded monthly reports to clients.

Built for web design and development agencies that maintain many client sites.

![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)
![WordPress 6.2+](https://img.shields.io/badge/WordPress-6.2%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

- **Author:** Rob Howard — [howard.ai](https://howard.ai)
- **Version:** 0.4.1 · [Changelog](CHANGELOG.md)
- **License:** [GPL v2 or later](LICENSE)

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Connecting an MCP client](#connecting-an-mcp-client)
- [Security model](#security-model)
- [Tools](#tools)
- [Plugin integrations](#plugin-integrations)
- [Activity log](#activity-log)
- [Client reports](#client-reports)
- [Settings reference](#settings-reference)
- [Data and storage](#data-and-storage)
- [Extending Site Manager](#extending-site-manager)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [License](#license)

## Features

- **209 MCP tools** in 22 groups — from editing a post to installing plugins, issuing WooCommerce refunds or changing a single Elementor widget. See the full [tool reference](docs/TOOLS.md).
- **Every post type**, custom fields, taxonomies, Gutenberg blocks and revisions. One `post_create` / `post_update` call can set content, terms, custom fields, ACF fields, Yoast SEO fields, featured image and template together.
- **Plugin integrations** for Advanced Custom Fields, Yoast SEO, WooCommerce, Gravity Forms, WPForms, Contact Form 7 (with Flamingo), Redirection and Elementor — loaded only when the plugin is active.
- **Any REST API on the site**: `rest_request` calls core and third-party REST routes internally as the connected administrator.
- **Activity log** of every action WordPress can detect, by anyone — logins, failed logins, user and role changes, core/plugin/theme updates with old → new versions, content, settings, file edits, exports and MCP activity — with filters, CSV export and reporting tools.
- **Branded client reports**: a monthly PDF with your title, agency name, logo and colors, emailed automatically to any recipients.
- **Secure by default**: administrators only, OAuth 2.1 with PKCE, per-request capability checks, dangerous capabilities off by default, and a record of everything.
- **WordPress-native admin screens** under Settings → Site Manager. No external services, libraries or build steps.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later, with the GD extension for report logos
- HTTPS on production sites (needed by MCP clients for OAuth, and by WordPress for Application Passwords)
- An administrator account (`manage_options`)

Tested with WordPress 7.1 and PHP 8.5; see [plugin integrations](#plugin-integrations) for plugin versions. Multisite has not been tested.

## Installation

**From a release zip**

1. Build the zip (or download one from the releases page):

   ```bash
   ./build.sh
   ```

   This writes `builds/site-manager-<version>.zip` containing a `site-manager/` folder.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.

**From source**

```bash
cd wp-content/plugins
git clone https://github.com/howarddc/site-manager-plugin.git site-manager
```

Then activate **Site Manager** under **Plugins**.

The plugin header sets `Update URI` to this repository, so WordPress never offers an "update" from an unrelated WordPress.org plugin that happens to use the same slug.

## Connecting an MCP client

Go to **Settings → Site Manager → Connect** and copy the MCP server URL:

```
https://example.com/wp-json/site-manager/v1/mcp
```

### Claude (claude.ai, Claude Desktop, Cowork)

1. In Claude, open **Settings → Connectors → Add custom connector**.
2. Paste the MCP server URL. Leave the OAuth client fields empty.
3. Claude opens the site in your browser. Sign in as an administrator and click **Approve**.

### Claude Code

```bash
claude mcp add --transport http site-manager https://example.com/wp-json/site-manager/v1/mcp
```

Then run `/mcp` inside Claude Code and authenticate in the browser.

### Other clients (Application Passwords)

Clients without OAuth support can use HTTP Basic auth with a [WordPress Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) created under **Users → Profile → Application Passwords**:

```bash
curl -s -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  https://example.com/wp-json/site-manager/v1/mcp
```

### Protocol details

- **Transport:** MCP Streamable HTTP — JSON-RPC 2.0 over `POST` with JSON responses; batch requests are supported. A `GET` with `Accept: text/event-stream` returns `405` (there is no server-initiated stream); a plain `GET` returns server info.
- **Protocol versions:** `2025-11-25`, `2025-06-18` (default), `2025-03-26`, `2024-11-05`.
- **Capabilities:** `tools`. `resources/list`, `resources/templates/list` and `prompts/list` return empty lists.
- **Instructions:** on `initialize` the server describes the site and every active integration, so the model knows where to start.
- **OAuth discovery:**
  - `https://example.com/.well-known/oauth-protected-resource` (RFC 9728)
  - `https://example.com/.well-known/oauth-authorization-server` (RFC 8414)

## Security model

| Control | Behavior |
| --- | --- |
| Who can connect | Only users with `manage_options` (administrators) can approve an OAuth client or call the endpoint. The capability is re-checked on every request and at every token refresh, so demoting a user cuts off their tokens immediately. |
| OAuth 2.1 | Dynamic client registration (RFC 7591), public clients, PKCE S256 required. Authorization codes are single-use and expire after 5 minutes; access tokens last 24 hours; refresh tokens last 30 days and rotate on every use. Tokens are stored as SHA-256 hashes. |
| Token scope | Bearer tokens are accepted only on the MCP endpoint, not on the rest of the REST API. |
| Redirect URIs | HTTPS, loopback `http://` and native-app schemes only. |
| Tool groups | Any group can be switched off in **Settings → Site Manager → Tools**. |
| Gated capabilities | Database writes (`db_execute`), file writes (`file_write`, `file_delete`) and PHP execution (`php_execute`) are **off by default**. File writes are limited to `wp-content`, PHP files are syntax-checked before saving, a `.bak` copy is kept, and `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` force file writes off. Enabling any of these is logged as a critical event. |
| Sensitive files | `wp-config.php`, `.env*`, `.htpasswd` and `auth.json` can never be read or written. |
| Built-in guards | You can't delete or demote yourself, remove capabilities from the administrator role, delete the administrator or default role, or deactivate or delete Site Manager through MCP. Changing the `siteurl`, `home`, `active_plugins`, `template` or `stylesheet` options requires an explicit `confirm` flag. |
| Tool annotations | Every tool carries MCP `readOnlyHint`, `destructiveHint` and `openWorldHint` annotations, so clients can ask before running anything that changes or deletes data. Many destructive tools also accept `dry_run`. |
| Logging | Every MCP write and every error is recorded (secrets redacted) in **MCP Calls** and in the site **Activity Log**, along with the OAuth client or Application Password name. |
| Revocation | **Settings → Site Manager → Connections** lists connected OAuth clients and revokes them instantly. |

## Tools

The full list with every parameter is in **[docs/TOOLS.md](docs/TOOLS.md)**, generated from the code.

| Group | Tools | Highlights |
| --- | --- | --- |
| Site overview | 3 | `site_info` (start here), Site Health report, search across everything |
| Content | 21 | Any post type; custom fields; revisions; block tree read/write; bulk updates; search and replace |
| Taxonomies | 7 | Categories, tags, custom taxonomies, term meta |
| Media | 7 | Upload from a URL or base64, alt text, regenerate thumbnails |
| Users & roles | 13 | Users, user meta, roles and capabilities, password resets, sessions, Application Passwords |
| Comments | 6 | List, reply, moderate, delete |
| Settings & options | 6 | Core settings screens and any option |
| Menus & widgets | 10 | Classic menus, menu items, locations, widget areas |
| Appearance | 11 | Themes, Customizer settings, Additional CSS, block-theme global styles |
| Plugins & updates | 10 | Search, install, activate, update, delete, auto-updates, core updates |
| Maintenance | 8 | Cron, caches (including popular caching plugins), transients, rewrite rules, test email, debug log |
| Developer | 12 | Internal REST requests, read-only SQL, file browser and reader, hooks, shortcodes; gated database, file and PHP tools |
| Activity log | 3 | Query, stats and period reports from the audit log |
| Client reports | 5 | Report settings, generate, send, archive |
| Plugin integrations | 87 | See [plugin integrations](#plugin-integrations) |

## Plugin integrations

Each integration registers its tools only when its plugin is active, and adds a line to the server instructions so the model knows it's available.

| Plugin | Tools | Coverage | Tested with |
| --- | --- | --- | --- |
| Advanced Custom Fields (free and PRO) | 9 | Field groups (read, create/update from export JSON, delete); values on posts, terms, users, comments and options pages, with repeater, group and flexible-content rows keyed by sub-field name; options pages; ACF-registered post types and taxonomies | ACF 6.8.10 |
| Yoast SEO | 8 | Post and term SEO fields (title, description, focus keyphrase, canonical, robots, social, schema, primary terms); SEO-gap audit; settings; Premium redirects (read only) | Yoast SEO 28.6 |
| WooCommerce | 22 | Every product type, variations, stock, global attributes, orders (list, view, create, update, refund), customers, coupons and a sales report; HPOS compatible. Store settings, tax rates and shipping zones via `rest_request` on `/wc/v3` | WooCommerce 11.1.2 |
| Gravity Forms | 11 | Full form objects, activate/deactivate, entries (filter, create, edit, delete), resend notifications | Written against the GFAPI; **not yet tested on a live install** |
| WPForms | 6 | Forms; entries on WPForms Pro | WPForms Lite 2.0.2.1 |
| Contact Form 7 | 6 | Forms (template, mail, autoresponder, messages, configuration errors), duplicate, delete; submissions via Flamingo | Contact Form 7 6.1.7, Flamingo 2.6.4 |
| Redirection | 11 | Redirects (create, bulk create, update, delete, regex, 410s), groups, 404 log grouped by URL, redirect log, first-run database setup, live URL test | Redirection 5.10.1 |
| Elementor | 14 | Page outline, full element tree, single-element update/insert/delete, text replace across documents, templates, kit (global colors, fonts, layout), widget controls, CSS regeneration | Elementor 4.3.3 |

`post_get` flags pages built with Elementor (`page_builder: "elementor"`), because their visible content lives in Elementor's element tree rather than in `post_content`.

## Activity log

Site Manager records every action WordPress can detect — by any user, cron job, WP-CLI or MCP client — in its own database table.

- **What's captured:** authentication; users and roles; core, plugin, theme and translation updates (with versions, manual vs automatic, and failures); plugin and theme changes; content and media; taxonomies, menus and widgets; comment moderation; settings (core screens and any plugin settings page saved through `options.php`); the built-in file editor; exports and privacy requests; WooCommerce orders and refunds; Gravity Forms; Redirection; MCP tool runs; and Site Manager's own configuration. See the full **[event catalog](docs/ACTIVITY-LOG.md)**.
- **Each event stores:** time; actor (ID, login, role); IP address; user agent; source (`admin`, `login`, `rest`, `mcp`, `cron`, `cli`, `ajax`, `xmlrpc`, `web`); credential (OAuth client or Application Password name); severity (`info`, `notice`, `warning`, `critical`); the object acted on; and before/after details.
- **Noise control:** repeated events, such as failed-login floods or rapid saves, fold into one row with an occurrence count and merged details.
- **Where to see it:** **Settings → Site Manager → Activity Log** (filter by date, category, severity, source, user or text; export CSV), or through MCP with `activity_log_query`, `activity_stats` and `activity_report`.
- **Audit integrity:** there is deliberately no "clear log" button. Exporting the log, clearing the MCP Calls log, deactivating Site Manager and enabling gated capabilities are all logged.

## Client reports

A branded monthly PDF for each client, built from the activity log and live site data.

- **Sections** (each can be switched off): at a glance · software updates with old → new versions · plugins and themes · content by type · users and logins · security events · WooCommerce sales and top products · form submissions · site health snapshot. Client reports leave out agency tooling (MCP activity, Site Manager settings) and events already shown in another section.
- **Branding:** report title, agency name, website, contact line, logo (any Media Library image; transparent areas print on white), accent color, US Letter or A4, an introduction, and an optional note for an individual report.
- **Delivery:** any number of recipient addresses (they don't need WordPress accounts); a subject template with `{title}`, `{site}`, `{period}` and `{agency}`; a Reply-To address; and the agency name as the sender name. The email contains a short branded summary with the PDF attached.
- **Schedule:** optionally email last month's report automatically on a chosen day of the month (1–28). It's sent once per month and catches up if WP-Cron missed the day.
- **Archive:** the last 36 reports are kept for administrators to download.
- **Admin:** **Settings → Site Manager → Reports** — download any of the last 12 months as a PDF, or email it now.
- **MCP:** `report_settings_get`, `report_settings_update`, `report_generate` (returns the report data and, optionally, the PDF as base64), `report_send` and `reports_list`.

PDFs are produced by a small built-in writer using the standard Helvetica fonts, so the plugin has no dependencies. Text is limited to Western European characters (Windows-1252).

## Settings reference

All settings live under **Settings → Site Manager**.

**Tools tab**

| Setting | Default | Notes |
| --- | --- | --- |
| Tool groups | All enabled | Unchecked groups are hidden from clients. |
| Database writes | Off | Enables `db_execute`. |
| File writes | Off | Enables `file_write` and `file_delete`. Unavailable when `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is set. |
| PHP execution | Off | Enables `php_execute`. |
| Site activity | On | Turns the activity log on or off. |
| Keep site activity for | 365 days | `0` keeps entries forever. |
| IP addresses | Store full IP | Or anonymize (drop the last octet), or don't store. |
| Read IP from | `REMOTE_ADDR` | Or `CF-Connecting-IP`, `X-Forwarded-For` or `X-Real-IP`. Only choose a proxy header if the site is behind that proxy — otherwise visitors can spoof their IP. |
| Log reads | Off | Also record read-only MCP calls in MCP Calls (writes and errors are always recorded). |
| Keep MCP calls for | 30 days | 1–3650 days. |

**Reports tab**

| Setting | Default |
| --- | --- |
| Report title | Monthly Website Report |
| Agency name, website, contact line, logo | Empty |
| Accent color | `#2271b1` |
| Paper size | US Letter |
| Introduction | Empty |
| Sections | All |
| Recipients | None |
| Email subject | `{title}: {site} — {period}` |
| Reply-To | Empty |
| Schedule | Off (day 3 of the month when enabled) |

## Data and storage

| Item | Purpose |
| --- | --- |
| `{prefix}site_manager_oauth_clients` table | Registered OAuth clients |
| `{prefix}site_manager_oauth_tokens` table | Authorization codes and access/refresh tokens (hashed) |
| `{prefix}site_manager_log` table | MCP Calls log |
| `{prefix}site_manager_events` table | Activity log |
| `site_manager_settings` option | Tools tab settings |
| `site_manager_report_settings` and `site_manager_reports` options | Report settings and the archive index |
| `site_manager_db_version` option | Schema version (tables upgrade automatically) |
| `wp-content/uploads/site-manager-reports/` | Archived report PDFs (random file names; direct access blocked by `.htaccess`) |
| `site_manager_daily` cron event | Purges expired tokens and old log entries, and sends the scheduled report |

**Uninstalling** (deleting the plugin from the Plugins screen) removes everything above, including the activity log and archived reports. Export the activity log first if you need to keep it. Deactivating the plugin keeps all data.

## Extending Site Manager

**Register your own tools** from another plugin or an mu-plugin:

```php
add_action( 'site_manager_register_tools', function ( Site_Manager_Registry $r ) {
	$r->add_category( 'acme', 'Acme', 'Acme-specific tools.' );
	$r->register( 'acme_ping', array(
		'category'     => 'acme',
		'description'  => 'Say hello.',
		'input_schema' => Site_Manager_Schema::obj( array( 'name' => Site_Manager_Schema::str() ) ),
		'handler'      => function ( array $args ) {
			return array( 'hello' => isset( $args['name'] ) ? $args['name'] : 'world' );
		},
		// Optional: 'writes' => true, 'destructive' => true, 'open_world' => true,
		// 'gate' => 'allow_php_exec', 'capability' => 'edit_posts'.
	) );
} );
```

Handlers receive the tool arguments as an array and return any JSON-serializable value or a `WP_Error`.

**Hooks**

| Hook | Type | Use |
| --- | --- | --- |
| `site_manager_register_tools` | action `( Site_Manager_Registry $r )` | Add tools and groups. |
| `site_manager_instructions` | filter `( string[] $lines )` | Add lines to the instructions sent to clients on `initialize`. |
| `site_manager_activity_logged` | action `( array $row )` | React to activity events — for example, forward critical events to Slack or a SIEM. |
| `site_manager_activity_ignored_post_types` | filter `( string[] $post_types )` | Post types whose changes are not logged. |

**Log your own events:**

```php
Site_Manager_Activity::log( array(
	'category'    => 'acme',
	'action'      => 'invoice_sent',
	'severity'    => 'notice',          // info | notice | warning | critical
	'object_type' => 'invoice',
	'object_id'   => 42,
	'object_name' => 'INV-0042',
	'message'     => 'Invoice INV-0042 sent to the client.',
	'details'     => array( 'amount' => 1200 ),
) );
```

## Troubleshooting

| Problem | Fix |
| --- | --- |
| Clients get `401` even with valid credentials | Some Apache and CGI setups strip the `Authorization` header. Make sure `.htaccess` includes WordPress's standard rule: `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. |
| OAuth discovery fails on a site installed in a subdirectory | Requests to the domain root's `/.well-known/` never reach WordPress. Most clients follow the `resource_metadata` URL in the `401` response instead; otherwise, serve the two discovery documents from the web root. |
| The Application Passwords section is missing | WordPress requires HTTPS for Application Passwords (unless `WP_ENVIRONMENT_TYPE` is `local`). |
| Plugin or theme installs and updates fail | WordPress needs direct filesystem access. Add `define( 'FS_METHOD', 'direct' );` to `wp-config.php` if the web server user owns the files, or install through your host. |
| Report emails don't arrive | WordPress sends mail with PHP's `mail()` by default, which many hosts block or mark as spam. Use an SMTP plugin. |
| Scheduled reports or log cleanup don't run | They run on WP-Cron (`site_manager_daily`). If `DISABLE_WP_CRON` is set, make sure a system cron job calls `wp-cron.php`. |
| A tool reports that it's disabled | Its group is switched off, or it's a gated tool — see **Settings → Site Manager → Tools**. |

## Development

```
site-manager.php                 Bootstrap, activation, hooks
includes/class-settings.php      Settings and capability gates
includes/class-log.php           MCP Calls log
includes/class-schema.php        JSON Schema helpers for tool inputs
includes/class-helpers.php       Shared formatting and write helpers
includes/mcp/                    Tool registry and the MCP server
includes/oauth/                  OAuth discovery, authorization server, token store
includes/admin/                  Settings → Site Manager screens
includes/tools/                  Core WordPress tools (one class per group)
includes/integrations/           Plugin integrations (loaded only when active)
includes/activity/               Activity log storage, queries, reports and hooks
includes/reports/                PDF writer, report data and delivery, renderer
bin/generate-tool-docs.php       Regenerates docs/TOOLS.md
docs/                            Tool reference and activity event catalog
```

- **Contributor notes** — conventions, testing and release steps for people and AI coding agents — are in [AGENTS.md](AGENTS.md).
- **Regenerate the tool reference** on a development site with every integration plugin active:

  ```bash
  wp eval-file wp-content/plugins/site-manager/bin/generate-tool-docs.php
  ```

- **Build a release zip** (development files are excluded):

  ```bash
  ./build.sh
  ```

## License

Site Manager is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version. It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY. See [LICENSE](LICENSE) for the full text.

Copyright © 2026 Rob Howard.

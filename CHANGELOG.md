# Changelog

All notable changes to Site Manager are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [semantic versioning](https://semver.org/).

## [0.4.1] — 2026-10-04

### Fixed

- WPForms and Contact Form 7 form changes are now recorded in the activity log (category `form`). Their post types have no admin UI of their own, so they were previously skipped.

### Added

- `bin/generate-tool-docs.php` builds `docs/TOOLS.md` from the live tool registry.
- Documentation: GitHub README, [tool reference](docs/TOOLS.md), [activity log event catalog](docs/ACTIVITY-LOG.md), this changelog, and the GPL v2 [LICENSE](LICENSE).
- `Update URI` plugin header, so WordPress never offers an update from an unrelated WordPress.org plugin with the same slug.

### Removed

- `readme.txt` (WordPress.org plugin directory format). This project is distributed through GitHub.

## [0.4.0] — 2026-10-04

### Added

- **Client reports:** a branded monthly PDF built from the activity log and live site data.
  - Sections: at a glance, software updates with versions, plugins and themes, content, users and logins, security events, WooCommerce sales, form submissions and a site health snapshot. Each can be switched off.
  - Branding: report title, agency name, website, contact line, logo, accent color, paper size, introduction and per-report notes.
  - Delivery: any recipients, subject template, Reply-To and agency sender name; optional monthly schedule; archive of the last 36 reports.
  - **Settings → Site Manager → Reports** tab with a Media Library logo picker.
  - MCP tools: `report_settings_get`, `report_settings_update`, `report_generate`, `report_send`, `reports_list`.
- Built-in, dependency-free PDF writer.

### Changed

- Plugin reactivations right after an update are logged as `plugin_reactivated` instead of `plugin_activated`.
- `activity_report` rows include `severity`, `category` and `action`.

## [0.3.0] — 2026-10-03

### Added

- **Activity log** of every action WordPress can detect — authentication, users and roles, core/plugin/theme/translation updates (with versions, automatic and failed), content, media, taxonomies, menus, widgets, comments, settings, the file editor, exports, privacy requests, WooCommerce, Gravity Forms, Redirection, MCP tool runs and Site Manager's own configuration.
- Activity Log admin tab with filters and CSV export.
- MCP tools: `activity_log_query`, `activity_stats`, `activity_report`.
- Settings for activity retention, IP storage and the proxy header to trust.
- `site_manager_activity_logged` action and `site_manager_activity_ignored_post_types` filter.

### Changed

- The MCP tool-call log tab is now called **MCP Calls**. Application Password clients are identified by name.

### Fixed

- Creating a Redirection redirect no longer triggers an undefined-index warning.

## [0.2.0] — 2026-10-03

### Added

- Integrations (70 tools), registered only when the plugin is active:
  - **WooCommerce:** products, variations, stock, attributes, orders, refunds, customers, coupons, sales report. Declares HPOS compatibility.
  - **Gravity Forms:** forms, entries, notifications.
  - **WPForms:** forms; entries on Pro.
  - **Contact Form 7:** forms; submissions via Flamingo.
  - **Redirection:** redirects, groups, 404 and redirect logs, first-run setup, URL test.
  - **Elementor:** document outline, per-element edits, text replace, templates, kit settings, widgets, CSS regeneration.
- `site_manager_instructions` filter; `post_get` flags Elementor-built pages.

## [0.1.0] — 2026-10-03

### Added

- Admin-only MCP server (Streamable HTTP, JSON-RPC 2.0) at `/wp-json/site-manager/v1/mcp`.
- OAuth 2.1 with dynamic client registration and PKCE, plus Application Password support.
- 131 tools: site overview, content of every post type, taxonomies, media, users and roles, comments, settings and options, menus and widgets, appearance, plugins and updates, maintenance, developer tools, Advanced Custom Fields and Yoast SEO.
- Gated database writes, file writes and PHP execution (off by default).
- Settings → Site Manager admin screen: Connect, Tools, Connections and an MCP call log.
- `site_manager_register_tools` action for adding tools.

[0.4.1]: https://github.com/howarddc/site-manager-plugin/compare/6383f3f...HEAD
[0.4.0]: https://github.com/howarddc/site-manager-plugin/compare/6b7393f...6383f3f
[0.3.0]: https://github.com/howarddc/site-manager-plugin/compare/0f92132...6b7393f
[0.2.0]: https://github.com/howarddc/site-manager-plugin/compare/efae93c...0f92132
[0.1.0]: https://github.com/howarddc/site-manager-plugin/commit/efae93c

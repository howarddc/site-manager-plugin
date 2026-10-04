=== Site Manager ===
Contributors: robhoward
Tags: mcp, claude, ai, woocommerce, elementor
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-only MCP server that lets Claude and other MCP clients manage your whole WordPress site.

== Description ==

Site Manager turns WordPress into a Model Context Protocol (MCP) server. Connect Claude (claude.ai, Claude Desktop, Cowork or Claude Code) and manage the site in conversation:

* Posts, pages and every custom post type, including custom fields, revisions and Gutenberg blocks
* Categories, tags and custom taxonomies
* Media library uploads, alt text and image sizes
* Users, roles and capabilities
* Comments and moderation
* Settings and any option
* Menus, widgets, themes, customizer settings, additional CSS and global styles
* Plugins, updates, cron, caches and the debug log
* Advanced Custom Fields: field groups and values on posts, terms, users and options pages
* Yoast SEO: titles, meta descriptions, focus keyphrases, robots, social metadata and settings
* WooCommerce: products, variations, stock, orders, refunds, customers, coupons and sales reports
* Gravity Forms, WPForms and Contact Form 7: forms, notifications and entries / submissions
* Redirection: redirects, groups, 404 log and redirect log
* Elementor: page layouts, individual widgets, templates and global kit settings
* Any REST API route on the site, including routes added by other plugins
* Branded monthly PDF reports for clients — updates, content, users, security, sales, forms and site health — emailed automatically to any recipients
* A site-wide activity log of every detectable action — logins, updates with versions, users and roles, content, settings, file edits, exports — for monthly reports and security audits

Only administrators can connect. Connections use OAuth 2.1 (with dynamic client registration) or WordPress Application Passwords.

Database writes, file writes and PHP execution are disabled by default and can be enabled individually.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → Site Manager and copy the MCP server URL.
3. In Claude, open Settings → Connectors → Add custom connector and paste the URL.
4. Approve the connection in your browser while signed in as an administrator.

== Frequently Asked Questions ==

= Who can connect? =

Only users with the manage_options capability (administrators). The check runs on every request.

= How do I disconnect an application? =

Settings → Site Manager → Connections → Revoke.

= Is there a log? =

Two. Settings → Site Manager → Activity Log records every detectable action on the site by anyone (with CSV export and an MCP report tool). MCP Calls records every tool call made through the MCP server.

== Changelog ==

= 0.4.0 =
* Client reports: branded monthly PDF (title, agency name, website, contact line, logo, accent color, paper size, intro, sections) emailed to any recipients on a monthly schedule or on demand, with an archive of the last 36 reports.
* MCP tools: report_settings_get, report_settings_update, report_generate, report_send, reports_list.
* Activity log: plugin reactivations after updates are recorded as such; report rows include severity, category and action.

= 0.3.0 =
* Site-wide activity log: authentication, users and roles, core/plugin/theme/translation updates with versions (including automatic and failed updates), content, media, menus, widgets, comments, settings, file editor, exports, privacy requests, WooCommerce, Gravity Forms, Redirection and MCP activity.
* Activity Log admin tab with filters and CSV export; MCP tools activity_log_query, activity_stats and activity_report.
* Settings for activity retention, IP storage and proxy header.
* MCP log renamed to MCP Calls; application-password clients are identified by name.

= 0.2.0 =
* Add WooCommerce, Gravity Forms, WPForms, Contact Form 7 (with Flamingo), Redirection and Elementor integrations (70 tools).
* post_get flags Elementor-built pages.
* Declare WooCommerce HPOS compatibility.

= 0.1.0 =
* Initial release: 131 tools across content, taxonomies, media, users, comments, settings, menus, appearance, plugins, maintenance, developer, ACF and Yoast SEO.

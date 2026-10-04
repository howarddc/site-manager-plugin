# Activity log event catalog

Every event Site Manager records, grouped by **category**. Use the `category` and `action` values to filter in **Settings → Site Manager → Activity Log** or with the `activity_log_query`, `activity_stats` and `activity_report` MCP tools.

Severity levels, from lowest to highest: `info` · `notice` · `warning` · `critical`.

"Folded" events update an existing row instead of adding a new one when the same actor repeats the same action on the same object within the window shown. The row's `occurrences` count goes up, `last_at` moves forward and details are merged (lists are combined; `from`/`to` pairs keep the earliest `from` and the latest `to`).

## Fields

| Field | Description |
| --- | --- |
| `time` / `last_time` | When the event first and last occurred (site timezone in the admin, API and exports; stored in UTC) |
| `occurrences` | How many times a folded event happened |
| `user`, `user_id`, `role` | The actor. Empty for visitors and for system tasks such as automatic updates |
| `ip`, `user_agent` | Request origin (see the IP settings on the Tools tab) |
| `source` | `admin`, `login`, `rest`, `mcp`, `cron`, `cli`, `ajax`, `xmlrpc` or `web` |
| `via` | Credential used: `oauth:<client name>` or `app-password:<name>` |
| `severity` | `info`, `notice`, `warning` or `critical` |
| `category`, `action` | What happened (below) |
| `object_type`, `object_id`, `object_name` | What it happened to |
| `message` | Human-readable summary |
| `details` | Structured extra data — before/after values, versions, changed fields |

## auth

| Action | Severity | When |
| --- | --- | --- |
| `login` | info | A user logs in. |
| `login_failed` | warning | A login fails. Records whether the username exists. Folded for 15 minutes per username and IP. |
| `logout` | info | A user logs out. |
| `password_reset_requested` | notice | A password reset email is requested. |

## user

| Action | Severity | When |
| --- | --- | --- |
| `user_created` | notice · **critical** if the new user is an administrator | A user is created or registers. |
| `user_updated` | info · warning if an administrator's email changed | Email, display name, website or username changes. Folded for 1 minute. |
| `password_changed` | notice · warning for administrators | A user's password is changed. |
| `password_reset` | notice · warning for administrators | A password is reset through the emailed link. |
| `user_role_changed` | notice · **critical** when granting administrator · warning when removing it | A user's role changes. |
| `user_deleted` | warning · **critical** for administrators | A user is deleted (details include whether content was reassigned). |
| `app_password_created` | warning | An Application Password is created. |
| `app_password_revoked` | notice | An Application Password is revoked. |
| `super_admin_granted` | **critical** | Multisite super admin granted. |
| `super_admin_revoked` | warning | Multisite super admin revoked. |

## core

| Action | Severity | When |
| --- | --- | --- |
| `core_updated` | notice | WordPress is updated. Details: `from`, `to`, `automatic`. |
| `translations_updated` | info | Translation packs are updated. Details list each package. |
| `update_failed` | warning | An automatic core or translation update fails. |

## plugin

| Action | Severity | When |
| --- | --- | --- |
| `plugin_installed` | notice | A plugin is installed. |
| `plugin_updated` | notice | A plugin is updated, or replaced by uploading a zip. Details: `from`, `to`, `automatic`. |
| `plugin_activated` | notice | A plugin is activated. |
| `plugin_reactivated` | info | A plugin is reactivated within 10 minutes of being updated (WordPress deactivates active plugins silently while updating them). |
| `plugin_deactivated` | notice · **critical** for Site Manager itself | A plugin is deactivated. |
| `plugin_deleted` | warning | A plugin is deleted. |
| `plugin_delete_failed` | warning | Deleting a plugin fails. |
| `auto_update_enabled` | info | Automatic updates turned on for a plugin. |
| `auto_update_disabled` | notice | Automatic updates turned off for a plugin. |
| `update_failed` | warning | An automatic plugin update fails. |

## theme

Covers themes and site-wide appearance: Additional CSS, block-theme global styles, templates, template parts and navigation menus (`wp_navigation`) are logged here with the [post actions](#post) below.

| Action | Severity | When |
| --- | --- | --- |
| `theme_installed` | notice | A theme is installed. |
| `theme_updated` | notice | A theme is updated. Details: `from`, `to`, `automatic`. |
| `theme_switched` | warning | The active theme changes. |
| `theme_deleted` | warning | A theme is deleted. |
| `theme_delete_failed` | warning | Deleting a theme fails. |
| `auto_update_enabled` / `auto_update_disabled` | info / notice | Automatic updates turned on or off for a theme. |
| `update_failed` | warning | An automatic theme update fails. |
| `customizer_saved` | notice | Customizer changes are published. Details list the changed settings. |
| `widgets_updated` | info | Widget areas change (not logged when caused by a theme switch). Folded for 2 minutes. |
| `elementor_kit_updated` | notice | Elementor site settings (the kit) change. Folded for 5 minutes. |

## post

Every post type with an admin screen — posts, pages, products, coupons, ACF field groups, Elementor templates and other custom post types. `object_type` is the post type. Internal and high-volume types are skipped: revisions, auto-drafts, menu items, Customizer changesets, oEmbed caches, privacy requests, ACF fields, WooCommerce orders (logged under [woocommerce](#woocommerce) instead), Flamingo messages, WPForms logs and Elementor snippets. Add more with the `site_manager_activity_ignored_post_types` filter.

| Action | Severity | When |
| --- | --- | --- |
| `created` | info | Content is first saved (as a draft, pending, etc.). |
| `published` | info | Content is published, including scheduled content going live. |
| `scheduled` | info | Content is scheduled for the future. |
| `updated` | info | Title, content, excerpt, slug, author, parent, date, menu order or password changes. Details list the changed fields, with before/after title and slug. Elementor saves are recorded here with `"builder": "elementor"`. Folded for 5 minutes. |
| `unpublished` | notice | Published content is changed to another status. |
| `status_changed` | info | Any other status change. |
| `trashed` | notice | Content is moved to the trash. |
| `restored` | info | Content is restored from the trash. |
| `deleted` | warning | Content is permanently deleted. |

## media

| Action | Severity | When |
| --- | --- | --- |
| `uploaded` | info | A file is added to the media library. |
| `updated` | info | A file's title, caption or description changes. Folded for 5 minutes. |
| `deleted` | notice | A file is deleted. |

## taxonomy

Taxonomies with an admin screen (categories, tags, product categories, custom taxonomies).

| Action | Severity | When |
| --- | --- | --- |
| `term_created` | info | A term is created. |
| `term_updated` | info | A term is edited. |
| `term_deleted` | notice | A term is deleted. |

## menu

Classic navigation menus.

| Action | Severity | When |
| --- | --- | --- |
| `menu_created` | info | A menu is created. |
| `menu_updated` | info | A menu is renamed. |
| `menu_deleted` | notice | A menu is deleted. |
| `menu_items_updated` | info | Menu items are added, changed or reordered. Folded for 2 minutes. |

## comment

Recorded only for actions by logged-in users, so visitor comments and spam don't flood the log.

| Action | Severity | When |
| --- | --- | --- |
| `comment_posted` | info | A logged-in user posts a comment. |
| `comment_edited` | info | A comment is edited. |
| `comment_approved` / `comment_unapproved` / `comment_spam` / `comment_trash` | info | A comment is moderated. |
| `comment_deleted` | notice | A comment is permanently deleted. |

## setting

| Action | Severity | When |
| --- | --- | --- |
| `option_updated` | notice · warning for `users_can_register`, `default_role`, `admin_email`, `siteurl`, `home` and `blog_public` | A core setting (General, Writing, Reading, Discussion, Media, Permalinks, Privacy) changes, or **any** option changes while a settings page is saved through `options.php` — which covers most plugin settings pages. Details: `from`, `to`, `page`. Changes that only alter the value's type (for example `"10"` → `10`) are ignored. |
| `option_added` | notice | An option is saved for the first time from a settings page. |
| `woocommerce_settings_saved` | notice | A WooCommerce settings tab is saved. |

## file

| Action | Severity | When |
| --- | --- | --- |
| `file_edited` | **critical** | A theme or plugin file is saved in the built-in file editor. |

Files written through the MCP `file_write` tool are recorded under [mcp](#mcp).

## export

| Action | Severity | When |
| --- | --- | --- |
| `content_exported` | warning | Content is exported from **Tools → Export** (WXR). |

## privacy

| Action | Severity | When |
| --- | --- | --- |
| `personal_data_exported` | notice | A personal data export file is generated. |
| `personal_data_erased` | warning | Personal data is erased for a request. |

## woocommerce

| Action | Severity | When |
| --- | --- | --- |
| `order_status_changed` | info · notice for refunded, cancelled or failed | An order's status changes (from checkout, the admin, a gateway or MCP). Details: `from`, `to`, `total`. |
| `order_refunded` | notice | A refund is created. Details: amount and reason. |

## form

**Gravity Forms**

| Action | Severity | When |
| --- | --- | --- |
| `form_created` | info | A form is created. |
| `form_updated` | info | A form is saved. Folded for 2 minutes. |
| `form_deleted` | warning | A form (and its entries) is deleted. |
| `entry_deleted` | notice | An entry is deleted. |

**WPForms and Contact Form 7** store forms as posts (`object_type` `wpforms` or `wpcf7_contact_form`), so their forms use the [post actions](#post) — `created`, `published`, `updated`, `trashed`, `restored`, `deleted` — under the `form` category.

## redirect

The Redirection plugin.

| Action | Severity | When |
| --- | --- | --- |
| `redirect_saved` | info | A redirect is created or updated. Folded for 1 minute. |
| `redirect_deleted` | notice | A redirect is deleted. |

## mcp

| Action | Severity | When |
| --- | --- | --- |
| `tool_run` | info · warning for destructive tools · **critical** for gated tools (`db_execute`, `file_write`, `file_delete`, `php_execute`) | An MCP tool that changes the site runs. `object_name` is the tool; details include the arguments (secrets redacted). |
| `tool_failed` | same as above | An MCP write tool returns an error. |

The WordPress events a tool causes (for example `published` or `plugin_updated`) are also logged with `source: mcp`.

## site_manager

| Action | Severity | When |
| --- | --- | --- |
| `mcp_client_authorized` | warning | An administrator approves an OAuth client. |
| `mcp_client_revoked` | notice | OAuth access is revoked on the Connections tab. |
| `capability_enabled` | **critical** | Database writes, file writes or PHP execution are enabled. |
| `capability_disabled` | notice | One of those capabilities is disabled. |
| `settings_updated` | notice | Other Site Manager settings change. |
| `activity_log_exported` | notice | The activity log is exported to CSV. |
| `mcp_log_cleared` | warning | The MCP Calls log is cleared. |
| `report_sent` | notice | A client report is emailed. |
| `report_send_failed` | warning | A scheduled client report couldn't be emailed. |

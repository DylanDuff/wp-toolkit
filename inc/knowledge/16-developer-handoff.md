# Developer Handoff — Site Overview

This article is for developers or agencies taking over this site. It covers what's been set up, where to find it, and how to migrate away from any of it cleanly if needed.

---

## WP Toolkit

This site uses **WP Toolkit** — a modular WordPress admin plugin installed and maintained by the original agency. It manages a collection of site-specific tweaks from a single settings page.

**Location:** WordPress admin → Tools → WP Toolkit

The plugin handles things that would otherwise require separate plugins or custom theme code: registering custom post types, ACF field groups, admin UI tweaks, and more. Most of what's described in this article is driven from there.

---

## ACF Presets

Several ACF field groups and custom post types are registered by WP Toolkit rather than stored as ACF field groups in the database. This means they won't appear under **ACF → Field Groups** — they're defined in code and applied automatically when the tweak is enabled.

The ACF presets currently active on this site may include:

| Preset | What it provides |
|---|---|
| **FAQs** | A `faq` custom post type with a custom `faq-tag` taxonomy, and a Relationship field for linking FAQs to pages and posts |
| **Service Areas** | A `service-area` custom post type with a `region` taxonomy |
| **Site Options** | An ACF options page under Settings with site-wide fields (logo, contact info, social links, etc.) |

You can see exactly which are enabled in the **ACF tab** of WP Toolkit.

---

## Exporting ACF Presets

If you're migrating away from WP Toolkit and want to continue managing these field groups through ACF directly, you can export them as ACF-importable JSON.

1. Go to **Tools → WP Toolkit → ACF tab**
2. Find the **Export ACF Presets** card at the bottom
3. Click **Export** — this downloads a JSON file containing all currently-enabled ACF preset field groups
4. In ACF, go to **Tools → Import** and upload the file

The exported file is in standard ACF JSON format and can be re-imported into any WordPress install running ACF.

> **Note on CPT data:** Exporting the field group definitions does not migrate the content (posts) within those CPTs. If you're moving FAQs or Service Areas to a new environment, you'll need to export that content separately — via a plugin like WP All Export, or by using the WordPress exporter under **Tools → Export**.

---

## Custom Post Types and Taxonomies

The following CPTs and taxonomies are registered by WP Toolkit. If the plugin is deactivated or removed, their registrations go away — the data remains in the database but WordPress will no longer recognise the post type. Re-register them (in ACF, a custom plugin, or theme code) before deactivating if you want to keep the content accessible.

| Post Type | Slug | Taxonomy |
|---|---|---|
| FAQs | `faq` | `faq-tag` |
| Service Areas | `service-area` | `region` |

---

## Settings Storage

All WP Toolkit settings are stored in `wp_options`. Option keys follow the pattern `ddwpt_{tweak_id}_{setting_id}` — for example, `ddwpt_acf_faq_enabled` or `ddwpt_acf_site_options_post_types`. They can be inspected or cleaned up directly in the database or via WP-CLI if the plugin is removed.

---

## This Knowledge Base

The knowledge base you're reading now is also managed by WP Toolkit (the **Knowledge Base** tweak). Articles are Markdown files stored in `wp-content/plugins/wp-toolkit/inc/knowledge/` and indexed via `manifest.php` in that same directory. To add or edit articles, update those files directly — no database entries are involved.

---

## Key Places at a Glance

| What | Where |
|---|---|
| Plugin settings | Tools → WP Toolkit |
| ACF field groups (code-registered) | ACF tab in WP Toolkit |
| ACF preset export | ACF tab → Export ACF Presets card |
| ACF field groups (database) | ACF → Field Groups |
| Knowledge base articles | `wp-content/plugins/wp-toolkit/inc/knowledge/` |
| WP Toolkit option keys | `wp_options` rows prefixed `ddwpt_` |

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

| Preset | CPT slug | What it provides |
|---|---|---|
| **FAQs** | `faq` | `faq-tag` taxonomy, Relationship field linkable to pages/posts |
| **Service Areas** | `service-area` | `region` taxonomy |
| **Team Members** | `team-member` | `job-title` taxonomy, ACF profile fields (`tm_*`), Relationship field linkable to pages/posts |
| **Testimonials** | `testimonial` | `testimonial-category` taxonomy, ACF detail fields (`tb_*`), Relationship field linkable to pages/posts, optional WP Social Ninja sync |
| **Locations** | `location` | `location-type` taxonomy, ACF detail fields (`lc_*`) |
| **Projects** | `project` | `project-type` taxonomy, ACF detail fields (`pj_*`), Relationship field linkable to pages/posts |
| **Site Options** | — | ACF options page under Settings with site-wide fields (`so_*`) |

You can see exactly which are enabled in the **ACF tab** of WP Toolkit.

Each preset card includes a **Field Names** accordion listing every available field name, type, and description — useful when building templates without needing to cross-reference ACF.

---

## ACF Field Reference

Each preset registers fields using a consistent prefix. Use these names with `get_field()` or in Bricks dynamic data.

**Team Members** (`team-member`)
| Field | Name |
|---|---|
| Name | `post_title` |
| Bio | `post_content` |
| Job Title | taxonomy: `job-title` |
| Email | `tm_email` |
| Phone | `tm_phone` |
| LinkedIn | `tm_linkedin_url` |
| Linked (on pages) | `linked_team_members` |

**Testimonials** (`testimonial`)
| Field | Name |
|---|---|
| Reviewer name | `post_title` |
| Quote | `post_content` |
| Category | taxonomy: `testimonial-category` |
| Company | `tb_company` |
| Role | `tb_role` |
| Rating (1–5) | `tb_rating` |
| Linked (on pages) | `linked_testimonials` |

**Locations** (`location`)
| Field | Name |
|---|---|
| Name | `post_title` |
| Description | `post_content` |
| Location Type | taxonomy: `location-type` |
| Address | `lc_address` |
| Phone | `lc_phone` |
| Email | `lc_email` |
| Hours | `lc_hours` |
| Map embed | `lc_map_embed` |

**Projects** (`project`)
| Field | Name |
|---|---|
| Name | `post_title` |
| Description | `post_content` |
| Project Type | taxonomy: `project-type` |
| Client | `pj_client` |
| URL | `pj_url` |
| Year | `pj_year` |
| Linked (on pages) | `linked_projects` |

**Site Options** (options page — use `get_field('field_name', 'option')`)

`so_brand_logo`, `so_phone_number`, `so_email_address`, `so_primary_address`, `so_operating_hours`, `so_facebook_url`, `so_instagram_url`, `so_twitter_url`, `so_linkedin_url`, `so_youtube_url`, `so_tiktok_url`, `so_pinterest_url`, `so_whatsapp_url`, `so_reddit_url`, `so_linktree_url`, `so_vimeo_url`, `so_gmb_url`, `so_mapbox_api_key`, `so_map_latitude`, `so_map_longitude`, `so_map_icon`, `so_announcement_enabled`, `so_announcement_content`, `so_trusted_logos`, `so_award_logos`

---

## WP Social Ninja — Testimonials Sync

If WP Social Ninja is installed and connected to review platforms (Google, Facebook, etc.), the Testimonials preset can sync reviews automatically as testimonial posts.

**How it works:**
- Manual reviews added inside WP Social Ninja sync immediately via a plugin hook.
- Platform reviews (Google, Facebook, Yelp, etc.) are synced in the background via an hourly WordPress cron job that processes **25 reviews per run**. This keeps the initial bulk import from overwhelming the server — expect it to drain over several hours rather than all at once.
- Each synced review is tracked with a `ddwpt_wpsr_review_id` post meta on the testimonial post, so no review is ever created twice.
- The reviewer's platform (Google, Facebook, etc.) is used to automatically create and assign a `testimonial-category` term.
- The **Minimum rating** setting (in the Testimonials card) filters which reviews are synced.

**To disable sync without losing data:** uncheck "Sync from WP Social Ninja" in the Testimonials card. Existing testimonial posts are untouched; the cron stops running.

---

## Exporting ACF Presets

If you're migrating away from WP Toolkit and want to continue managing these field groups through ACF directly, you can export them as ACF-importable JSON.

1. Go to **Tools → WP Toolkit → ACF tab**
2. Find the **Export ACF Presets** card at the bottom
3. Click **Export** — this downloads a JSON file containing all field groups from every currently-enabled ACF preset tweak
4. In ACF, go to **Tools → Import** and upload the file

The exported file is in standard ACF JSON format. Presets with multiple field groups (Team Members, Testimonials, Projects) will export all of them in a single file.

> **Note on CPT data:** Exporting field group definitions does not migrate the post content within those CPTs. Export that separately via WP All Export or **Tools → Export** in WordPress, selecting the relevant post type.

---

## Custom Post Types and Taxonomies

The following CPTs and taxonomies are registered by WP Toolkit. If the plugin is deactivated or removed, their registrations go away — the data remains in the database but WordPress will no longer recognise the post type. Re-register them before deactivating if you want to keep the content accessible.

| Post Type | Slug | Taxonomy | Taxonomy slug |
|---|---|---|---|
| FAQs | `faq` | FAQ Tag | `faq-tag` |
| Service Areas | `service-area` | Region | `region` |
| Team Members | `team-member` | Job Title | `job-title` |
| Testimonials | `testimonial` | Testimonial Category | `testimonial-category` |
| Locations | `location` | Location Type | `location-type` |
| Projects | `project` | Project Type | `project-type` |

### Collections menu grouping

If the **Group CPTs** tweak is enabled, all of the above CPTs are moved under a single **Collections** menu item in the WordPress admin rather than appearing as individual top-level items. Disabling this tweak restores them to top-level menus without affecting content.

---

## Settings Storage

All WP Toolkit settings are stored in `wp_options`. Option keys follow the pattern `ddwpt_{tweak_id}_{setting_id}` — for example, `ddwpt_acf_faq_enabled` or `ddwpt_acf_testimonials_sync_wpsr_min_rating`. They can be inspected or cleaned up directly in the database or via WP-CLI if the plugin is removed.

The WPSR sync tracking key is `ddwpt_wpsr_review_id` stored as post meta on each synced testimonial post.

---

## This Knowledge Base

The knowledge base you're reading now is also managed by WP Toolkit (the **Knowledge Base** tweak). Articles are Markdown files stored in `wp-content/plugins/wp-toolkit/inc/knowledge/` and indexed via `manifest.php` in that same directory. To add or edit articles, update those files directly — no database entries are involved.

> **Important:** This directory lives inside the plugin folder. Plugin updates will overwrite it, replacing any articles you've added or edited. If you need to customise the knowledge base, either pin the plugin to a specific version and update manually, or maintain your article files outside version control and redeploy them after each update.

---

## Key Places at a Glance

| What | Where |
|---|---|
| Plugin settings | Tools → WP Toolkit |
| ACF field groups (code-registered) | ACF tab in WP Toolkit |
| Field name reference | Each preset card → Field Names accordion |
| ACF preset export | ACF tab → Export ACF Presets card |
| ACF field groups (database) | ACF → Field Groups |
| WP Social Ninja reviews | WP Social Ninja → Reviews |
| Knowledge base articles | `wp-content/plugins/wp-toolkit/inc/knowledge/` |
| WP Toolkit option keys | `wp_options` rows prefixed `ddwpt_` |
| WPSR sync tracking | `wp_postmeta` where `meta_key = 'ddwpt_wpsr_review_id'` |

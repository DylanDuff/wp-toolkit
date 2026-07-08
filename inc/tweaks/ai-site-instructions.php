<?php

namespace DDWPTweaks\Tweaks;

$default_instructions = <<<'INSTRUCTIONS'
# Site Instructions

This document describes how this WordPress site is built and how you should work with it. Read this before taking any action on the site.

---

## Stack

- **Page builder:** Bricks Builder — all visual page layouts are built in Bricks.
- **Custom fields:** ACF Pro — all structured data on collections and the global options page is stored as ACF fields.
- **SEO:** SEOPress — SEO titles, meta descriptions, and Open Graph images are managed per-post via SEOPress.
- **CMS:** WordPress with WP Toolkit managing custom post types, field groups, and admin configuration.

---

## Content Model

There are two types of content on this site:

### Pages
One-off pages (Home, About, Contact, etc.) built with Bricks Builder. Each has a unique layout. Pages are edited visually in Bricks — do not create or modify page layouts programmatically. When updating a page's written content, edit the underlying post content or ACF fields rather than the Bricks builder data unless you have a specific reason to modify the builder JSON.

### Collections
Groups of structured content entries that share a layout — things like Blog Posts, Services, Team Members, or Projects. Each collection is a custom post type with ACF fields. Collections are edited as individual posts with structured fields, not through the visual editor. Create and update collection entries via the WordPress REST API or WP-CLI using `post_title`, `post_content`, and ACF `meta` fields.

---

## Collections Reference

The following custom post types may be active on this site. Check which are in use before assuming any are present.

### FAQs (`faq`)
| Field | Key |
|---|---|
| Question | `post_title` |
| Answer | `post_content` |
| Tag | taxonomy: `faq-tag` |

### Service Areas (`service-area`)
| Field | Key |
|---|---|
| Name | `post_title` |
| Content | `post_content` |
| Region | taxonomy: `region` |

### Team Members (`team-member`)
| Field | Key |
|---|---|
| Name | `post_title` |
| Bio | `post_content` |
| Job Title | taxonomy: `job-title` |
| Email | `tm_email` |
| Phone | `tm_phone` |
| LinkedIn URL | `tm_linkedin_url` |
| Linked pages | `linked_team_members` (relationship field) |

### Testimonials (`testimonial`)
| Field | Key |
|---|---|
| Reviewer name | `post_title` |
| Quote | `post_content` |
| Category | taxonomy: `testimonial-category` |
| Company | `tb_company` |
| Role | `tb_role` |
| Rating (1–5) | `tb_rating` |
| Linked pages | `linked_testimonials` (relationship field) |

### Locations (`location`)
| Field | Key |
|---|---|
| Name | `post_title` |
| Description | `post_content` |
| Location type | taxonomy: `location-type` |
| Address | `lc_address` |
| Phone | `lc_phone` |
| Email | `lc_email` |
| Hours | `lc_hours` |
| Map embed | `lc_map_embed` |

### Projects (`project`)
| Field | Key |
|---|---|
| Name | `post_title` |
| Description | `post_content` |
| Project type | taxonomy: `project-type` |
| Client | `pj_client` |
| URL | `pj_url` |
| Year | `pj_year` |
| Linked pages | `linked_projects` (relationship field) |

---

## Global Site Options

Site-wide settings are stored on an ACF options page. Read and write these with `get_field('field_name', 'option')` / `update_field('field_name', $value, 'option')`.

| Setting | Field key |
|---|---|
| Logo | `so_brand_logo` |
| Phone | `so_phone_number` |
| Email | `so_email_address` |
| Address | `so_primary_address` |
| Operating hours | `so_operating_hours` (repeater) |
| Facebook | `so_facebook_url` |
| Instagram | `so_instagram_url` |
| X / Twitter | `so_twitter_url` |
| LinkedIn | `so_linkedin_url` |
| YouTube | `so_youtube_url` |
| TikTok | `so_tiktok_url` |
| Pinterest | `so_pinterest_url` |
| WhatsApp | `so_whatsapp_url` |
| Reddit | `so_reddit_url` |
| Linktree | `so_linktree_url` |
| Vimeo | `so_vimeo_url` |
| Google Business | `so_gmb_url` |
| Map latitude | `so_map_latitude` |
| Map longitude | `so_map_longitude` |
| Map icon | `so_map_icon` |
| Announcement enabled | `so_announcement_enabled` |
| Announcement content | `so_announcement_content` |
| Trusted logos | `so_trusted_logos` (repeater) |
| Award logos | `so_award_logos` (repeater) |

---

## Conventions

- **Taxonomy terms:** use slugs derived from the term name (lowercase, hyphenated). Create missing terms before assigning them to a post.
- **Relationship fields** (`linked_team_members`, `linked_testimonials`, `linked_projects`): store an array of post IDs. Read the existing value first and append rather than overwrite unless asked to replace.
- **Images:** stored as media attachment IDs in ACF image fields, or as URLs in URL fields. Upload to the media library first, then reference by attachment ID.
- **Blog posts:** standard WordPress `post` post type. Use `post_title`, `post_content`, categories, and tags.
- **Drafts:** set `post_status` to `draft` to save without publishing. Use `publish` to make live.

---

## What Not to Touch

- **Bricks builder data** (`_bricks_editor_data`, `_bricks_template_*` meta) — do not write to these unless you are explicitly working on page layout. Corrupting this data breaks the visual page.
- **WP Toolkit options** (`ddwpt_*`) — these are plugin configuration values, not site content.
- **Permalink structure** — do not change `Settings → Permalinks`.
- **User roles** — do not modify roles or capabilities unless explicitly asked.
- **Plugin/theme files** — do not edit files on disk; work through WordPress data APIs only.

---

## Notes

- [Add site-specific notes here — active CPTs, unusual configurations, client preferences, naming rules]
INSTRUCTIONS;

// Runs at plugins_loaded when the file is required — before wp_abilities_api_init fires.
if (get_option('ddwpt_ai_site_instructions_enabled')) {
    add_action('wp_abilities_api_init', static function () use ($default_instructions) {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $instructions = get_option('ddwpt_ai_site_instructions_instructions', $default_instructions);
        if (!$instructions) {
            return;
        }

        wp_register_ability(
            'wp-toolkit/get-site-instructions',
            [
                'label'               => 'Get Site Instructions',
                'description'         => 'Returns configuration instructions for this WordPress site, describing how it is structured and how AI agents should interact with it.',
                'category'            => 'site',
                'input_schema'        => [
                    'type'                 => ['object', 'null'],
                    'properties'           => [],
                    'additionalProperties' => false,
                ],
                'output_schema'       => [
                    'type'        => 'string',
                    'description' => 'The site instructions document in plain text or Markdown format.',
                ],
                'execute_callback'    => static function () use ($instructions) {
                    return $instructions;
                },
                'permission_callback' => static function () {
                    return current_user_can('read');
                },
                'meta'                => [
                    'annotations'  => [
                        'readonly'   => true,
                        'idempotent' => true,
                    ],
                    'show_in_rest' => true,
                    'mcp'          => [
                        'public' => true,
                    ],
                ],
            ]
        );
    });
}

return [
    'id'    => 'ddwpt_ai_site_instructions',
    'label' => 'Site Instructions',
    'tab'   => 'ai',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Registers a <code>wp-toolkit/get-site-instructions</code> ability via the WordPress Abilities API, making your site configuration instructions available to MCP-connected AI agents.',
        ],
        [
            'id'          => 'instructions',
            'type'        => 'textarea',
            'label'       => 'Site Instructions',
            'description' => 'Plain text or Markdown. Returned by the <code>wp-toolkit/get-site-instructions</code> ability so AI agents understand how your site is structured and how they should work with it.',
            'default'     => $default_instructions,
        ],
    ],

    'callback' => static function ($settings) {
        // Ability registration is handled at file-load time above.
    },
];

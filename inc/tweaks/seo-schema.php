<?php

namespace DDWPTweaks\Tweaks;

use DDWPTweaks\SEO\Schema_Conflicts;
use DDWPTweaks\SEO\Schema_Renderer;
use DDWPTweaks\SEO\Schema_Sources;
use DDWPTweaks\SEO\Schema_Transforms;
use DDWPTweaks\SEO\Schema_Types;

require_once __DIR__ . '/../seo/class-schema-transforms.php';
require_once __DIR__ . '/../seo/class-schema-sources.php';
require_once __DIR__ . '/../seo/class-schema-renderer.php';
require_once __DIR__ . '/../seo/class-schema-types.php';
require_once __DIR__ . '/../seo/class-schema-conflicts.php';

/**
 * Structured data (JSON-LD) built per post type from schema templates.
 * Experimental for now: it only runs while Settings → Experiments is on.
 * See docs/seo-schema.md.
 */

if (!function_exists(__NAMESPACE__ . '\\seo_schema_templates')) {

    /** Saved templates keyed by post type: {"recipe": {"enabled": true, "template": {...}}} */
    function seo_schema_templates(): array
    {
        $saved = json_decode((string) get_option('ddwpt_seo_schema_templates', ''), true);
        return is_array($saved) ? $saved : [];
    }

    function seo_schema_sanitize_templates($value): string
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($decoded)) return '';

        $clean = [];
        foreach ($decoded as $post_type => $entry) {
            if (!is_array($entry) || !is_array($entry['template'] ?? null)) continue;
            $clean[sanitize_key($post_type)] = [
                'enabled'  => !empty($entry['enabled']),
                'template' => $entry['template'],
            ];
        }

        // Script tags are neutralised at output (JSON_HEX_TAG), so the
        // template itself is stored as written.
        return wp_json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The site-wide Organization and WebSite nodes. */
    function seo_schema_site_nodes(array $settings): array
    {
        $same_as = [];
        if (function_exists('get_field')) {
            foreach (['facebook', 'instagram', 'twitter', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'gmb'] as $network) {
                $url = get_field('so_' . $network . '_url', 'option');
                if ($url) $same_as[] = $url;
            }
        }

        $organization = array_filter([
            '@type'  => $settings['organization_type'] ?: 'Organization',
            '@id'    => Schema_Sources::node_id('organization'),
            'name'   => Schema_Sources::site('name'),
            'url'    => Schema_Sources::site('url'),
            'logo'   => Schema_Sources::site('logo'),
            'sameAs' => $same_as,
        ]);

        $website = [
            '@type'     => 'WebSite',
            '@id'       => Schema_Sources::node_id('website'),
            'url'       => Schema_Sources::site('url'),
            'name'      => Schema_Sources::site('name'),
            'publisher' => ['@id' => Schema_Sources::node_id('organization')],
        ];

        return [$organization, $website];
    }

    function seo_schema_render(int $post_id, array $template): array
    {
        return (new Schema_Renderer($post_id))->render($template);
    }

    /** Template for the current singular view, or null when we don't handle it. */
    function seo_schema_current_template(): ?array
    {
        if (!is_singular()) return null;

        $entry = seo_schema_templates()[get_post_type(get_queried_object_id())] ?? null;
        return !empty($entry['enabled']) && !empty($entry['template']) ? $entry['template'] : null;
    }

    /** Saved settings by short key, with the site values handed to the sources. */
    function seo_schema_options(): array
    {
        $options = [];
        foreach (['organization_type', 'organization_name', 'organization_logo', 'site_nodes', 'conflict_mode'] as $key) {
            $options[$key] = get_option('ddwpt_seo_schema_' . $key, '');
        }

        $logo = $options['organization_logo'];
        if (!$logo && function_exists('get_field')) {
            $brand = get_field('so_brand_logo', 'option');
            $logo  = is_array($brand) ? ($brand['url'] ?? null) : (is_numeric($brand) ? wp_get_attachment_image_url((int) $brand, 'full') : $brand);
        }

        Schema_Sources::configure(['name' => $options['organization_name'], 'logo' => $logo]);

        return $options;
    }

    /* ---------- Settings page: editor, preview ---------- */

    function seo_schema_admin(): void
    {
        add_action('ddwpt_render_field_schema_templates', function ($field, $value, $field_id) {
            printf(
                '<div class="ddwpt-schema-editor" data-input="%1$s"><input type="hidden" id="%1$s" name="%1$s" value="%2$s" /><p class="description">Loading editor…</p></div>',
                esc_attr($field_id),
                esc_attr($value ?: '{}')
            );
        }, 10, 3);

        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook !== 'tools_page_ddwptweaks') return;

            wp_enqueue_style('ddwpt-schema-editor', DDWPT_URL . 'assets/css/schema-editor.css', ['ddwpt-settings'], DDWPT_VERSION);
            wp_enqueue_script('ddwpt-schema-editor', DDWPT_URL . 'assets/js/schema-editor.js', ['ddwpt-settings'], DDWPT_VERSION, true);

            $code_editor = wp_enqueue_code_editor(['type' => 'application/json']);

            wp_localize_script('ddwpt-schema-editor', 'ddwptSchema', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('ddwpt_schema'),
                'postTypes'  => Schema_Sources::catalogue(),
                'types'      => Schema_Types::all(),
                'presets'    => Schema_Types::presets(),
                'transforms' => Schema_Transforms::catalogue(),
                'codeEditor' => $code_editor ?: null,
            ]);
        });

        add_action('wp_ajax_ddwpt_schema_posts', function () {
            check_ajax_referer('ddwpt_schema', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

            $posts = get_posts([
                'post_type'      => sanitize_key($_POST['post_type'] ?? ''),
                'post_status'    => 'publish',
                'posts_per_page' => 50,
                'orderby'        => 'modified',
            ]);

            wp_send_json_success(array_map(fn($post) => ['id' => $post->ID, 'title' => get_the_title($post) ?: '#' . $post->ID], $posts));
        });

        add_action('wp_ajax_ddwpt_schema_preview', function () {
            check_ajax_referer('ddwpt_schema', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

            $post     = get_post(absint($_POST['post_id'] ?? 0));
            $template = json_decode(wp_unslash($_POST['template'] ?? ''), true);

            if (!$post || !is_array($template)) {
                wp_send_json_error('Pick a post and make sure the template is valid JSON.');
            }

            $options  = seo_schema_options();
            $renderer = new Schema_Renderer($post->ID);
            $nodes    = $renderer->render($template);
            $warnings = $renderer->warnings();

            foreach ($nodes as $node) {
                foreach (Schema_Types::missing_required($node) as $property) {
                    $warnings[] = sprintf('%s is missing required property "%s"', is_string($node['@type'] ?? null) ? $node['@type'] : 'Node', $property);
                }
            }

            wp_send_json_success([
                'json'     => wp_json_encode(
                    ['@context' => 'https://schema.org', '@graph' => array_merge(seo_schema_site_nodes($options), $nodes)],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'warnings' => array_values(array_unique($warnings)),
                'testUrl'  => 'https://search.google.com/test/rich-results?url=' . rawurlencode(get_permalink($post)),
            ]);
        });
    }
}

// The editor is available whether or not the tweak is on, so templates can
// be built and previewed before switching output on.
if (is_admin()) {
    seo_schema_admin();
}

return [
    'id'    => 'ddwpt_seo_schema',
    'label' => 'Structured Data (Schema)',
    'tab'   => 'experimental',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Build JSON-LD per post type from post fields, ACF, taxonomies and Site Options, and output it in <code>wp_head</code>. Experimental: only runs while Experiments is enabled.',
        ],
        [
            'id'      => 'organization_type',
            'type'    => 'select',
            'label'   => 'Organization type',
            'default' => 'Organization',
            'options' => [
                'Organization'        => 'Organization',
                'LocalBusiness'       => 'Local business',
                'MedicalOrganization' => 'Medical organization',
                'ProfessionalService' => 'Professional service',
                'HomeAndConstructionBusiness' => 'Trades / construction',
                'LegalService'        => 'Legal service',
                'Restaurant'          => 'Restaurant',
                'Store'               => 'Store',
                'EducationalOrganization' => 'Educational organization',
                'NGO'                 => 'Non-profit',
            ],
            'description' => 'The site-wide <code>#organization</code> node that templates reference with <code>{{ref:organization}}</code>.',
        ],
        [
            'id'          => 'organization_name',
            'type'        => 'text',
            'label'       => 'Organization name',
            'description' => 'Defaults to the site title.',
        ],
        [
            'id'          => 'organization_logo',
            'type'        => 'media',
            'label'       => 'Organization logo',
            'description' => 'Defaults to the Site Options brand logo, then the theme\'s custom logo.',
        ],
        [
            'id'          => 'site_nodes',
            'type'        => 'checkbox',
            'label'       => 'Every page',
            'label_inline' => 'Output Organization and WebSite on every page, not only pages with a template',
            'description' => 'Leave off when the SEO plugin already outputs them site-wide.',
        ],
        [
            'id'      => 'conflict_mode',
            'type'    => 'select',
            'label'   => 'SEO plugin schema',
            'default' => 'replace',
            'options' => [
                'replace'   => 'Replace it on pages with a template',
                'merge'     => 'Merge into Yoast\'s graph (Yoast only)',
                'alongside' => 'Output alongside it',
            ],
            'description' => 'How to handle Yoast, Rank Math or SEOPress schema on pages where a template applies.',
        ],
        [
            'id'       => 'templates',
            'type'     => 'schema_templates',
            'label'    => 'Templates',
            'sanitize' => __NAMESPACE__ . '\\seo_schema_sanitize_templates',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled']) || !get_option('ddwpt_experiments_enabled') || is_admin()) {
            return;
        }

        $options = seo_schema_options();

        add_action('wp', function () use ($options) {
            $template = seo_schema_current_template();

            if (!$template) {
                if (!empty($options['site_nodes'])) {
                    add_action('wp_head', fn() => print Schema_Renderer::script(seo_schema_site_nodes($options)), 20);
                }
                return;
            }

            $post_id = get_queried_object_id();
            $graph   = function () use ($post_id, $template) {
                static $nodes = null;
                return $nodes ??= seo_schema_render($post_id, $template);
            };

            if (!Schema_Conflicts::apply($options['conflict_mode'] ?: 'replace', $graph)) {
                return; // Merged into the SEO plugin's graph instead.
            }

            add_action('wp_head', function () use ($graph, $options) {
                $nodes = $graph();
                if ($nodes) {
                    echo Schema_Renderer::script(array_merge(seo_schema_site_nodes($options), $nodes));
                }
            }, 20);
        });
    },
];

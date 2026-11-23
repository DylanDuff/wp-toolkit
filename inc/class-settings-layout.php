<?php

namespace DDWPTweaks;

defined('ABSPATH') || exit;

/**
 * Where tweaks sit on the settings page.
 *
 * A tweak's `tab` names its area and `group` its section within that area.
 * Both are presentation only: moving a tweak never changes its option keys.
 * Areas or sections a tweak names that are not listed here still appear,
 * labelled from their id. See docs/settings-layout.md.
 */
class Settings_Layout
{
    public const AREAS = [
        'site' => [
            'label'    => 'Site',
            'icon'     => 'Shield-Lucide.svg',
            'lede'     => 'Security, media and content defaults.',
            'sections' => [
                'content'  => 'Content',
                'security' => 'Security',
                'media'    => 'Media',
                'notices'  => 'Notices',
            ],
        ],
        'admin' => [
            'label'    => 'Admin',
            'icon'     => 'Gauge-Lucide.svg',
            'lede'     => 'Dashboard, admin bar, menus and list tables.',
            'sections' => [
                'dashboard'     => 'Dashboard',
                'admin-bar'     => 'Admin Bar',
                'menu'          => 'Menu',
                'list-tables'   => 'List Tables',
                'footer'        => 'Footer',
                'look-and-feel' => 'Look & Feel',
            ],
        ],
        'content' => [
            'label'    => 'Content model',
            'icon'     => 'Boxes-Lucide.svg',
            'lede'     => 'ACF presets, site options and edit screens.',
            'sections' => [
                'post-type-presets' => 'Post Type Presets',
                'site-options'      => 'Site Options',
                'editing-screens'   => 'Editing Screens',
                'tools'             => 'Tools',
            ],
        ],
        'builder' => [
            'label'    => 'Builder',
            'icon'     => 'Layout-Dashboard-Lucide.svg',
            'lede'     => 'Bricks editor, elements and block editor.',
            'sections' => [
                'editor'    => 'Editor',
                'data'      => 'Data',
                'elements'  => 'Elements',
                'gutenberg' => 'Gutenberg',
            ],
        ],
        'integrations' => [
            'label'    => 'Integrations',
            'icon'     => 'Dock-Lucide.svg',
            'lede'     => 'Glue for other plugins and services.',
            'sections' => [
                'fluent-forms'       => 'Fluent Forms',
                'wp-social-ninja'    => 'WP Social Ninja',
                'google-tag-manager' => 'Google Tag Manager',
            ],
        ],
        'seo' => [
            'label'    => 'SEO',
            'icon'     => 'Search-Lucide.svg',
            'lede'     => 'Structured data and URL behaviour.',
            'sections' => [
                'schema' => 'Schema',
                'urls'   => 'URLs',
            ],
        ],
        'ai' => [
            'label'    => 'AI & MCP',
            'icon'     => 'Sparkles-Lucide.svg',
            'lede'     => 'Abilities and MCP connection.',
            'sections' => [],
        ],
        // Settings is about the plugin itself: it sits apart at the bottom of
        // the sidebar, has no on/off count and is left off the Overview tiles.
        'settings' => [
            'label'    => 'Settings',
            'icon'     => 'Settings-Lucide.svg',
            'lede'     => 'Branding, experiments and backups.',
            'sections' => [],
            'utility'  => true,
        ],
    ];

    /**
     * Plugins and themes a tweak can name in `requires`. A null check means
     * the dependency cannot be detected reliably, so it is shown as a plain
     * badge and never hides the tweak.
     */
    public static function requirement_met(string $name): ?bool
    {
        switch ($name) {
            case 'ACF':
                return class_exists('ACF');
            case 'Bricks':
                return defined('BRICKS_VERSION');
            case 'Redirection':
                return defined('REDIRECTION_VERSION');
            case 'Fluent Forms':
                return defined('FLUENTFORM');
            case 'WP Social Ninja':
                return defined('WPSOCIALREVIEWS_VERSION');
            case 'GTM4WP':
                return function_exists('gtm4wp_the_gtm_tag') || defined('GTM4WP_VERSION');
            case 'MCP Adapter':
                return self::plugin_active('mcp-adapter/mcp-adapter.php');
            default:
                return null;
        }
    }

    /** Names from a tweak's `requires` that are detectably missing. */
    public static function missing_requirements(array $tweak): array
    {
        return array_values(array_filter(
            $tweak['requires'] ?? [],
            static fn($name) => self::requirement_met($name) === false
        ));
    }

    public static function plugin_active(string $file): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($file);
    }

    /** Areas in display order: known areas first, then any new ones, Settings last. */
    public static function areas(array $tweaks): array
    {
        $used = [];
        foreach ($tweaks as $tweak) {
            $used[self::area_of($tweak)] = true;
        }

        $areas = [];
        foreach (self::AREAS as $id => $area) {
            if (isset($used[$id]) || $id === 'ai') {
                $areas[$id] = $area;
            }
        }
        foreach (array_keys($used) as $id) {
            if (!isset($areas[$id])) {
                $areas[$id] = ['label' => self::label_from_id($id), 'icon' => 'Boxes-Lucide.svg', 'lede' => '', 'sections' => []];
            }
        }

        // Keep the utility area at the end, whatever order the loop produced.
        uasort($areas, static fn($a, $b) => (int) !empty($a['utility']) <=> (int) !empty($b['utility']));
        return $areas;
    }

    public static function area_of(array $tweak): string
    {
        return !empty($tweak['tab']) ? $tweak['tab'] : 'site';
    }

    public static function section_of(array $tweak): string
    {
        return !empty($tweak['group']) ? $tweak['group'] : '';
    }

    /** Sections used by the given tweaks, in the area's declared order. */
    public static function sections(string $area_id, array $tweaks): array
    {
        $declared = self::AREAS[$area_id]['sections'] ?? [];
        $used     = [];
        foreach ($tweaks as $tweak) {
            $used[self::section_of($tweak)] = true;
        }

        $sections = [];
        foreach ($declared as $id => $label) {
            if (isset($used[$id])) $sections[$id] = $label;
        }
        foreach (array_keys($used) as $id) {
            if (!isset($sections[$id])) $sections[$id] = $id === '' ? 'General' : self::label_from_id($id);
        }
        return $sections;
    }

    public static function label_from_id(string $id): string
    {
        return ucwords(str_replace('-', ' ', $id));
    }
}

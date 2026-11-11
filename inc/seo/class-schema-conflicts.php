<?php

namespace DDWPTweaks\SEO;

defined('ABSPATH') || exit;

/**
 * Keeps our JSON-LD from fighting the SEO plugin's on pages where we output
 * template nodes.
 *
 *   replace   suppress the plugin's schema on those pages (default)
 *   merge     Yoast only: add our nodes to Yoast's graph instead of printing our own
 *   alongside leave the plugin alone and print both
 */
class Schema_Conflicts
{
    public static function active_plugin(): ?string
    {
        if (defined('WPSEO_VERSION')) return 'yoast';
        if (defined('RANK_MATH_VERSION')) return 'rank-math';
        if (defined('SEOPRESS_VERSION')) return 'seopress';
        return null;
    }

    /**
     * Called from `wp` (after the query, before wp_head) on pages we handle.
     *
     * @param callable $graph Returns our graph nodes; only called for merge.
     * @return bool Whether we should still print our own script tag.
     */
    public static function apply(string $mode, callable $graph): bool
    {
        $plugin = self::active_plugin();

        if (!$plugin || $mode === 'alongside') {
            return true;
        }

        if ($mode === 'merge' && $plugin === 'yoast') {
            add_filter('wpseo_schema_graph', function ($pieces) use ($graph) {
                return array_merge(is_array($pieces) ? $pieces : [], $graph());
            }, 20);
            return false;
        }

        self::suppress($plugin);
        return true;
    }

    private static function suppress(string $plugin): void
    {
        switch ($plugin) {
            case 'yoast':
                add_filter('wpseo_json_ld_output', '__return_false', 99);
                break;

            case 'rank-math':
                add_filter('rank_math/json_ld', '__return_empty_array', 99);
                break;

            case 'seopress':
                // SEOPress prints each schema from its own wp_head callback and
                // has no single off switch, so unhook the schema callbacks.
                // Breadcrumbs are left alone: we don't output BreadcrumbList.
                add_action('wp_head', [self::class, 'unhook_seopress'], 0);
                break;
        }
    }

    public static function unhook_seopress(): void
    {
        global $wp_filter;

        if (empty($wp_filter['wp_head'])) return;

        foreach ($wp_filter['wp_head']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $name = self::callback_name($callback['function']);
                $is_schema = stripos($name, 'seopress') !== false
                    && preg_match('/schema|json_?ld|rich_?snippet|knowledge_graph/i', $name)
                    && stripos($name, 'breadcrumb') === false;

                if (apply_filters('ddwpt_schema_unhook_callback', $is_schema, $name, $callback['function'])) {
                    remove_action('wp_head', $callback['function'], $priority);
                }
            }
        }
    }

    private static function callback_name($function): string
    {
        if (is_string($function)) return $function;
        if (is_array($function)) {
            $class = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];
            return $class . '::' . $function[1];
        }
        return '';
    }
}

<?php

namespace DDWPTweaks;

defined('ABSPATH') || exit;

class Tweak_Loader
{

    private const ALLOWED_TWEAKS = [
        'ai-mcp-info',
        'ai-content-abilities',
        'ai-redirection-abilities',
        'ai-site-instructions',
        'ai-field-architecture',
        'ai-acf-field-abilities',
        'acf-abilities-api',
        'acf-settings',
        'acf-site-options',
        'acf-export',
        'acf-faq',
        'acf-locations',
        'acf-projects',
        'acf-service-areas',
        'acf-team-members',
        'acf-testimonials',
        'disable-dashboard-widgets',
        'duplicate-post',
        'featured-image-column',
        'inset-subposts',
        'post-id-column',
        'posts-per-page',
        'change-howdy-greeting',
        'environment-indicator',
        'hide-adminbar-logo',
        'invert-admin-bar',
        'whitelabel-adminbar-logo',
        'floating-admin-panel',
        'reorder-sidebar',
        'admin-footer-text',
        'hide-footer-version',
        'admin-theme',
        'bricks-combine-panels',
        'bricks-menu-images',
        'bricks-toolbar-logo',
        'acss-gutenberg',
        'bricks-post-editor',
        'bricks-dashboard-widgets',
        'disable-comments',
        'enable-application-passwords',
        'disable-image-compression',
        'disable-update-nags',
        'gtm-autoload',
        'hide-wp-version',
        'knowledge-base',
        'svg-uploads',
        'test-notifications',
        'toast-notifications',
        'embla-slider-bricks',
        'mapbox-bricks',
        'motion-library',
        'rive-bricks',
        'unicorn-studio-bricks',
        'wpsr-bricks',
        'settings-import-export',
        'plugin-settings',
        'profile-overhaul',
        'experiments',
    ];

    public function load_all()
    {
        $directory = __DIR__ . '/tweaks/';
        $tweaks    = [];
        $failed    = [];

        foreach (self::ALLOWED_TWEAKS as $slug) {
            $file = $directory . $slug . '.php';

            // Missing files used to fatal the whole site via require().
            if (!file_exists($file)) {
                $failed[] = $slug;
                error_log("WP Toolkit: tweak file missing, skipped '{$slug}' ({$file})");
                continue;
            }

            try {
                $def = require $file;
            } catch (\Throwable $e) {
                $failed[] = $slug;
                error_log("WP Toolkit: tweak '{$slug}' failed to load: " . $e->getMessage());
                continue;
            }

            if (!$this->validate($def)) continue;

            // Render-only tweaks have no settings or callback to wire up.
            if (isset($def['render'])) {
                $tweaks[] = $def;
                continue;
            }

            // AUTO-PREFIX SETTINGS
            foreach ($def['settings'] as &$setting) {
                if (!str_starts_with($setting['id'], $def['id'])) {
                    $setting['id'] = $def['id'] . '_' . $setting['id'];
                }
            }
            unset($setting);

            $tweaks[] = $def;

            add_action('init', function () use ($def) {
                $settings = [];

                foreach ($def['settings'] as $setting) {
                    $value = get_option($setting['id'], $setting['default'] ?? null);
                    // full (prefixed) key
                    $settings[$setting['id']] = $value;
                    // also expose short key (unprefixed) for callbacks that expect it
                    $prefix = $def['id'] . '_';
                    if (str_starts_with($setting['id'], $prefix)) {
                        $short = substr($setting['id'], strlen($prefix));
                        $settings[$short] = $value;
                    }
                }

                call_user_func($def['callback'], $settings);
            });
        }

        if (!empty($failed)) {
            add_action('admin_notices', function () use ($failed) {
                if (!current_user_can('manage_options')) return;
                printf(
                    '<div class="notice notice-error"><p><strong>WP Toolkit:</strong> %d tweak(s) failed to load and were skipped, so the rest of the site keeps working: <code>%s</code>. Check the PHP error log for details.</p></div>',
                    count($failed),
                    esc_html(implode(', ', $failed))
                );
            });
        }

        return $tweaks;
    }

    private function validate($tweak)
    {
        if (!is_array($tweak) || !isset($tweak['id'])) return false;
        // Render-only tweak: just needs an id and a render callable.
        if (isset($tweak['render'])) return is_callable($tweak['render']);
        // Standard tweak: needs label, settings array, and callback.
        return isset($tweak['label'], $tweak['settings'], $tweak['callback']);
    }
}

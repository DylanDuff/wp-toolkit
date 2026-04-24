<?php

namespace DDWPTweaks;

defined('ABSPATH') || exit;

class Tweak_Loader
{

    private const ALLOWED_TWEAKS = [
        'acf-site-options',
        'acss-gutenberg',
        'admin-footer-text',
        'admin-theme',
        'bricks-dashboard-widgets',
        'bricks-post-editor',
        'change-howdy-greeting',
        'disable-comments',
        'disable-dashboard-widgets',
        'disable-image-compression',
        'disable-update-nags',
        'duplicate-post',
        'environment-indicator',
        'featured-image-column',
        'gtm-autoload',
        'hide-adminbar-logo',
        'hide-footer-version',
        'hide-wp-version',
        'inset-subposts',
        'knowledge-base',
        'invert-admin-bar',
        'post-id-column',
        'posts-per-page',
        'reorder-sidebar',
        'svg-uploads',
        'test-notifications',
        'toast-notifications',
        'whitelabel-adminbar-logo',
        'mapbox-bricks',
        'motion-library',
        'rive-bricks',
        'unicorn-studio-bricks',
        'wpsr-bricks',
    ];

    public function load_all()
    {
        $directory = __DIR__ . '/tweaks/';
        $files = array_map(
            fn($slug) => $directory . $slug . '.php',
            self::ALLOWED_TWEAKS
        );

        $tweaks = [];

        foreach ($files as $file) {
            $def = require $file;

            if (!$this->validate($def)) continue;

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

        return $tweaks;
    }

    private function validate($tweak)
    {
        return is_array($tweak)
            && isset($tweak['id'], $tweak['label'], $tweak['settings'], $tweak['callback']);
    }
}

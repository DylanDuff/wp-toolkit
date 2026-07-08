<?php

namespace DDWPTweaks\Tweaks;

// Runs during plugins_loaded — before the init callbacks that call
// register_post_type() in acf-faq.php and acf-service-areas.php.
// This is intentionally outside the definition array so the filter
// is in place before any CPT registration happens.
if (get_option('ddwpt_acf_settings_enabled')) {
    add_filter('register_post_type_args', function ($args, $post_type) {
        static $preset_types = ['faq', 'location', 'project', 'service-area', 'team-member', 'testimonial'];
        if (in_array($post_type, $preset_types, true)) {
            $args['show_in_menu'] = 'ddwpt-collections';
        }
        return $args;
    }, 10, 2);
}

return [
    'id'    => 'ddwpt_acf_settings',
    'label' => 'Group CPTs',
    'tab'   => 'acf',
    'group' => 'general',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Group ACF content type presets under a shared "Collections" admin menu item instead of individual top-level items.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('admin_menu', function () {
            // Only register Collections if at least one preset CPT is active.
            $preset_types = ['faq', 'service-area', 'team-member'];
            if (!array_filter($preset_types, 'post_type_exists')) {
                return;
            }

            add_menu_page(
                __('Collections', 'wp-toolkit'),
                __('Collections', 'wp-toolkit'),
                'edit_posts',
                'ddwpt-collections',
                '__return_null',
                'dashicons-category',
                5
            );
        }, 5);
    },
];

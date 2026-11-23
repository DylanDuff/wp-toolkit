<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_tab_errors',
    'label' => 'Highlight Tabs With Errors',
    'tab'   => 'acf',
    'group' => 'general',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Mark ACF tabs that contain a validation error in red, so a required field on another tab is easy to find after a failed save.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Fires on every admin screen that renders ACF fields.
        add_action('acf/input/admin_enqueue_scripts', static function () {
            wp_enqueue_style('ddwpt-acf-tab-errors', DDWPT_URL . 'assets/css/acf-tab-errors.css', [], DDWPT_VERSION);
            wp_enqueue_script('ddwpt-acf-tab-errors', DDWPT_URL . 'assets/js/acf-tab-errors.js', ['acf-input'], DDWPT_VERSION, true);
        });
    },
];

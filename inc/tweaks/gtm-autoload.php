<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_gtm_autoload',
    'label' => 'GTM Autoload',
    'tab'   => 'general',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Auto-inject the GTM4WP tag via wp_body_open.',
        ],
        [
            'id'          => 'exclude_administrator',
            'type'        => 'checkbox',
            'label'       => 'Exclude Administrator',
            'description' => 'Do not load GTM for administrators.',
        ],
        [
            'id'          => 'exclude_editor',
            'type'        => 'checkbox',
            'label'       => 'Exclude Editor',
            'description' => 'Do not load GTM for editors.',
        ],
        [
            'id'          => 'exclude_author',
            'type'        => 'checkbox',
            'label'       => 'Exclude Author',
            'description' => 'Do not load GTM for authors.',
        ],
        [
            'id'          => 'exclude_contributor',
            'type'        => 'checkbox',
            'label'       => 'Exclude Contributor',
            'description' => 'Do not load GTM for contributors.',
        ],
        [
            'id'          => 'exclude_subscriber',
            'type'        => 'checkbox',
            'label'       => 'Exclude Subscriber',
            'description' => 'Do not load GTM for subscribers.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('wp_body_open', function () use ($settings) {
            if (!function_exists('gtm4wp_the_gtm_tag')) {
                return;
            }

            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                $excluded = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
                foreach ($excluded as $role) {
                    if (!empty($settings['exclude_' . $role]) && in_array($role, $user->roles, true)) {
                        return;
                    }
                }
            }

            gtm4wp_the_gtm_tag();
        });
    },
];

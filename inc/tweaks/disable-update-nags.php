<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_disable_update_nags',
    'label' => 'Disable Update Nags',
    'tab'   => 'notifications',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Hide WordPress core, plugin, and theme update notices for non-admin users.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('admin_init', function () {
            if (current_user_can('update_core')) return;

            remove_action('admin_notices', 'update_nag', 3);
            remove_action('admin_notices', 'maintenance_nag', 10);

            add_action('admin_head', function () {
                echo '<style>
                    .update-nag,
                    .notice-warning.notice-alt,
                    #wp-admin-bar-updates {
                        display: none !important;
                    }
                </style>';
            });
        });
    },
];

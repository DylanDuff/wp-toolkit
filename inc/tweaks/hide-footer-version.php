<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_hide_footer_version',
    'label' => 'Hide Footer Version',
    'tab'   => 'footer',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Remove the WordPress version number from the admin footer.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('update_footer', '__return_empty_string', 11);
    },
];

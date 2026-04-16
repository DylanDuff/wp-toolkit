<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_disable_image_compression',
    'label' => 'Disable Image Compression',
    'tab'   => 'media',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Set JPEG compression quality to 100%. WordPress defaults to 82%.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('jpeg_quality', function () {
            return 100;
        });

        add_filter('wp_editor_set_quality', function () {
            return 100;
        });
    },
];

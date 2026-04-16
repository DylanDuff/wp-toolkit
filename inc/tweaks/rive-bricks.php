<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_rive_bricks',
    'label' => 'Rive Bricks Element',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register a Rive animation element in Bricks Builder and allow .riv file uploads.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('init', function () {
            if (!class_exists('\Bricks\Elements')) {
                return;
            }
            \Bricks\Elements::register_element(dirname(__DIR__) . '/elements/element-rive.php');
        }, 11);

        add_filter('upload_mimes', function ($mimes) {
            $mimes['riv'] = 'application/octet-stream';
            return $mimes;
        });
    },
];

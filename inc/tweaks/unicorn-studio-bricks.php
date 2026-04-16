<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_unicorn_studio_bricks',
    'label' => 'Unicorn Studio Bricks Element',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register a Unicorn Studio element in Bricks Builder.',
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
            \Bricks\Elements::register_element(dirname(__DIR__) . '/elements/element-unicorn-studio.php');
        }, 11);
    },
];

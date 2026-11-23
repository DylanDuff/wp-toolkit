<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_mapbox_bricks',
    'label' => 'Mapbox Bricks Element',
    'tab'   => 'builder',
    'group' => 'elements',
    'description' => 'Register a Mapbox GL map element in Bricks Builder.',
    'tags'  => ['CDN'],
    'requires' => ['Bricks'],

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register a Mapbox GL map element in Bricks Builder.',
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
            \Bricks\Elements::register_element(dirname(__DIR__) . '/elements/element-mapbox.php');
        }, 11);
    },
];

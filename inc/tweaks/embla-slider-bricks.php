<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_embla_slider_bricks',
    'label' => 'Embla Slider Bricks Element',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register a nestable Embla Carousel slider element in Bricks Builder (powered by a bundled copy of embla-carousel 8.6.0 — no external requests).',
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
            \Bricks\Elements::register_element(dirname(__DIR__) . '/elements/element-embla-slider.php');
        }, 11);
    },
];

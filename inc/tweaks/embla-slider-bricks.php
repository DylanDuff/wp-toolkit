<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_embla_slider_bricks',
    'label' => 'Embla Slider Bricks Element',
    'tab'   => 'experimental',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register a nestable Embla Carousel slider element in Bricks Builder (powered by embla-carousel v8 via CDN).',
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

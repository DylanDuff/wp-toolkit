<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_embla_slider_bricks',
    'label' => 'Embla Slider Bricks Element',
    'tab'   => 'bricks',
    'group' => 'elements',

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

        // Bricks only enqueues element assets while rendering the body, which lands the
        // stylesheet in the footer — after first paint, and after the generated control
        // CSS it is supposed to lose to. Enqueue it in the head instead when the page
        // uses the element; priority 5 keeps it ahead of Bricks' inline CSS (priority 11).
        add_action('wp_enqueue_scripts', function () {
            if (class_exists('\Prefix_Element_Embla_Slider')) {
                \Prefix_Element_Embla_Slider::maybe_enqueue_assets();
            }
        }, 5);
    },
];

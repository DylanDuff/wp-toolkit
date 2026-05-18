<?php

return [
    'id'       => 'ddwpt_plugin_settings',
    'label'    => 'White Label',
    'tab'      => 'settings',
    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable White Label',
            'description' => 'Apply custom branding to the plugin header.',
        ],
        [
            'id'          => 'gradient_from',
            'type'        => 'color',
            'label'       => 'Gradient start',
            'default'     => '#1a3352',
            'description' => 'Left/top colour.',
        ],
        [
            'id'          => 'gradient_to',
            'type'        => 'color',
            'label'       => 'Gradient end',
            'default'     => '#3a8fd1',
            'description' => 'Right/bottom colour.',
        ],
        [
            'id'      => 'gradient_angle',
            'type'    => 'select',
            'label'   => 'Gradient direction',
            'default' => '135deg',
            'options' => [
                '90deg'  => 'Horizontal (→)',
                '135deg' => 'Diagonal (↘)',
                '160deg' => 'Diagonal steep (↘)',
                '180deg' => 'Vertical (↓)',
            ],
        ],
        [
            'id'          => 'icon',
            'type'        => 'media',
            'label'       => 'Custom icon',
            'description' => 'Replaces the default WP Toolkit logo in the header. SVG or PNG recommended.',
        ],
        [
            'id'          => 'accent',
            'type'        => 'color',
            'label'       => 'Accent colour',
            'default'     => '#2271b1',
            'description' => 'Primary colour used for buttons, toggles, and active states.',
        ],
        [
            'id'          => 'accent_hover',
            'type'        => 'color',
            'label'       => 'Accent hover',
            'default'     => '#135e96',
            'description' => 'Darker shade shown on hover.',
        ],
        [
            'id'          => 'accent_light',
            'type'        => 'color',
            'label'       => 'Accent light',
            'default'     => '#f0f6fc',
            'description' => 'Light tint used for selected backgrounds.',
        ],
    ],
    'callback' => function ( $settings ) {
        // Values are read directly during admin page render — no hooks needed.
    },
];

<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_admin_footer_text',
    'label' => 'Admin Footer Text',
    'tab'   => 'footer',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Replace the default "Thank you for creating with WordPress" footer text.',
        ],
        [
            'id'      => 'content',
            'type'    => 'wysiwyg',
            'label'   => 'Footer content',
            'default' => '',
            'rows'    => 6,
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('admin_footer_text', function () use ($settings) {
            return wp_kses_post($settings['content'] ?? '');
        });
    },
];

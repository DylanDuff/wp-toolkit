<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_posts_per_page',
    'label' => 'Posts Per Page',
    'tab'   => 'admin-tables',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Override the default number of items shown in admin list tables.',
        ],
        [
            'id'          => 'count',
            'type'        => 'text',
            'label'       => 'Items per page',
            'default'     => '40',
            'description' => 'Default is 20. Applies to all post type list tables.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $count = absint($settings['count'] ?? 40);
        if ($count < 1) $count = 20;

        add_filter('edit_posts_per_page', function () use ($count) {
            return $count;
        });
    },
];

<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_featured_image_column',
    'label' => 'Show Featured Image Column',
    'tab'   => 'admin-tables',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Add a thumbnail column to post type list tables that support featured images.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('admin_init', function () {
            $post_types = get_post_types_by_support('thumbnail');

            foreach ($post_types as $post_type) {
                add_filter("manage_{$post_type}_posts_columns", function ($columns) {
                    // Insert thumbnail column after the checkbox column
                    $new = [];
                    foreach ($columns as $key => $label) {
                        $new[$key] = $label;
                        if ($key === 'cb') {
                            $new['ddwpt_thumbnail'] = 'Image';
                        }
                    }
                    return $new;
                });

                add_action("manage_{$post_type}_posts_custom_column", function ($column, $post_id) {
                    if ($column !== 'ddwpt_thumbnail') return;

                    $thumb = get_the_post_thumbnail($post_id, [40, 40], [
                        'style' => 'border-radius: 2px;',
                    ]);

                    echo $thumb ?: '&mdash;';
                }, 10, 2);
            }
        });

        add_action('admin_head', function () {
            echo '<style>.column-ddwpt_thumbnail { width: 52px; }</style>';
        });
    },
];

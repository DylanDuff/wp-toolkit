<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_post_id_column',
    'label' => 'Show Post ID Column',
    'tab'   => 'admin-tables',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Add an ID column to post, page, and custom post type list tables.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Get all public post types and attach column hooks
        add_action('admin_init', function () {
            $post_types = get_post_types(['show_ui' => true]);

            foreach ($post_types as $post_type) {
                add_filter("manage_{$post_type}_posts_columns", function ($columns) {
                    // Insert ID column after the checkbox column
                    $new = [];
                    foreach ($columns as $key => $label) {
                        $new[$key] = $label;
                        if ($key === 'cb') {
                            $new['ddwpt_post_id'] = 'ID';
                        }
                    }
                    return $new;
                });

                add_action("manage_{$post_type}_posts_custom_column", function ($column, $post_id) {
                    if ($column === 'ddwpt_post_id') {
                        echo esc_html($post_id);
                    }
                }, 10, 2);
            }
        });

        // Keep the column narrow
        add_action('admin_head', function () {
            echo '<style>.column-ddwpt_post_id { width: 4em; }</style>';
        });
    },
];

<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acss_gutenberg',
    'label' => 'ACSS in Gutenberg',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Load Automatic CSS into the Gutenberg editor for selected post types, and fix Bricks section padding in the editor.',
        ],
        [
            'id'          => 'post_types',
            'type'        => 'multiselect',
            'label'       => 'Post types',
            'description' => 'Post types that should have ACSS available in the Gutenberg editor.',
            'default'     => '',
            'options'     => function () {
                $post_types = get_post_types(['public' => true, 'show_ui' => true], 'objects');
                $options    = [];

                foreach ($post_types as $slug => $obj) {
                    $options[$slug] = $obj->label ?: $slug;
                }

                return $options;
            },
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $raw   = $settings['post_types'] ?? '';
        $types = is_array($raw) ? $raw : (json_decode($raw, true) ?: []);

        if (!empty($types)) {
            add_filter('acss/gutenberg/allowed_post_types', function ($post_types) use ($types) {
                return array_unique(array_merge($post_types, $types));
            });
        }

        add_action('enqueue_block_editor_assets', function () {
            wp_add_inline_style('wp-edit-blocks', '
                .editor-styles-wrapper .brxe-section {
                    padding: 0 !important;
                    padding-bottom: 50px !important;
                }
            ');
        });
    },
];

<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_bricks_dashboard_widgets',
    'label' => 'Bricks Dashboard Widgets',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Add Bricks section templates as dashboard widgets.',
        ],
        [
            'id'      => 'sections',
            'type'    => 'multiselect',
            'label'   => 'Section templates',
            'default' => '',
            'options' => function () {
                if (!defined('BRICKS_DB_TEMPLATE_SLUG')) {
                    return [];
                }

                $templates = get_posts([
                    'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
                    'posts_per_page' => -1,
                    'post_status'    => 'publish',
                    'meta_key'       => '_bricks_template_type',
                    'meta_value'     => 'section',
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ]);

                $items = [];
                foreach ($templates as $template) {
                    $items[$template->ID] = $template->post_title ?: '(Untitled #' . $template->ID . ')';
                }
                return $items;
            },
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $raw = $settings['sections'] ?? '';
        $ids = json_decode($raw, true);
        if (!is_array($ids)) $ids = [];

        if (empty($ids)) {
            return;
        }

        add_action('wp_dashboard_setup', function () use ($ids) {
            foreach ($ids as $id) {
                $post = get_post($id);
                if (!$post || $post->post_status !== 'publish') {
                    continue;
                }

                $title = $post->post_title ?: 'Bricks Section #' . $id;

                wp_add_dashboard_widget(
                    'ddwpt_bricks_section_' . $id,
                    $title,
                    function () use ($id) {
                        echo do_shortcode('[bricks_template id="' . intval($id) . '"]');
                    }
                );
            }
        });
    },
];

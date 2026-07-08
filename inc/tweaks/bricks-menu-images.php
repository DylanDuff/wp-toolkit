<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_bricks_menu_images',
    'label' => 'Menu Item Images',
    'tab'   => 'bricks',
    'group' => 'builder',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Inject images into nav menu items. Uses an ACF image field set directly on the menu item, with an optional fallback to the linked post\'s featured image.',
        ],
        [
            'id'          => 'min_depth',
            'type'        => 'text',
            'label'       => 'Minimum depth',
            'default'     => '1',
            'description' => 'Only inject images at this depth or deeper. 0 = all items, 1 = submenu items only (default).',
        ],
        [
            'id'          => 'post_types',
            'type'        => 'checkboxes',
            'label'       => 'Fallback CPTs',
            'options'     => function () {
                $post_types = get_post_types(['public' => true], 'objects');
                $options    = [];
                foreach ($post_types as $slug => $obj) {
                    if ($slug === 'attachment') continue;
                    $options[$slug] = $obj->labels->singular_name;
                }
                return $options;
            },
            'default'     => '[]',
            'description' => 'When no ACF image is set on the menu item, fall back to the linked post\'s featured image if it belongs to one of these post types.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        if (function_exists('acf_add_local_field_group')) {
            acf_add_local_field_group([
                'key'    => 'group_ddwpt_menu_item_images',
                'title'  => 'Menu Item Image',
                'fields' => [
                    [
                        'key'               => 'field_ddwpt_menu_item_image',
                        'label'             => 'Image',
                        'name'              => 'menu_item_image',
                        'type'              => 'image',
                        'return_format'     => 'array',
                        'library'           => 'all',
                        'preview_size'      => 'thumbnail',
                        'allow_in_bindings' => 0,
                    ],
                ],
                'location' => [
                    [
                        [
                            'param'    => 'nav_menu_item',
                            'operator' => '==',
                            'value'    => 'all',
                        ],
                    ],
                ],
                'active' => true,
            ]);
        }

        $min_depth  = max(0, (int) ($settings['min_depth'] ?? 1));
        $post_types = json_decode($settings['post_types'] ?? '[]', true);
        if (!is_array($post_types)) {
            $post_types = [];
        }

        add_filter('walker_nav_menu_start_el', function ($item_output, $item, $depth) use ($min_depth, $post_types) {
            if ($depth < $min_depth) {
                return $item_output;
            }

            $img_html = '';

            if (function_exists('get_field')) {
                $acf_image = get_field('menu_item_image', 'menu_item_' . $item->ID);
                if (!empty($acf_image['url'])) {
                    $src = $acf_image['sizes']['thumbnail'] ?? $acf_image['url'];
                    $img_html = sprintf(
                        '<img src="%s" alt="%s" class="menu-item-image" loading="lazy" />',
                        esc_url($src),
                        esc_attr($acf_image['alt'] ?? '')
                    );
                }
            }

            if (!$img_html && !empty($post_types)) {
                $post_id = (int) $item->object_id;
                if ($post_id && in_array(get_post_type($post_id), $post_types, true)) {
                    $img_html = get_the_post_thumbnail($post_id, 'thumbnail', [
                        'class'   => 'menu-item-image',
                        'loading' => 'lazy',
                    ]);
                }
            }

            if (!$img_html) {
                return $item_output;
            }

            $item_output = preg_replace(
                '/(<a[^>]*>)(.*?)(<\/a>)/is',
                '$1' . $img_html . '<span class="menu-item-title">$2</span>$3',
                $item_output
            );

            return $item_output;
        }, 10, 3);
    },
];

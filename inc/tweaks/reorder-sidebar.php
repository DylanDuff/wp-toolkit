<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_reorder_sidebar',
    'label' => 'Reorder Sidebar Menu',
    'tab'   => 'sidebar',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Apply a custom order to the admin sidebar menu.',
        ],
        [
            'id'      => 'order',
            'type'    => 'sortable',
            'label'   => 'Menu order',
            'default' => '',
            'items'   => function () {
                global $menu;
                if (empty($menu)) return [];

                $items = [];
                foreach ($menu as $item) {
                    // Skip separators
                    if (empty($item[0]) || $item[4] === 'wp-menu-separator') {
                        continue;
                    }
                    // Strip HTML from menu labels (notification bubbles etc.)
                    $label = wp_strip_all_tags($item[0]);
                    if (empty($label)) continue;

                    $items[$item[2]] = $label;
                }
                return $items;
            },
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $raw = $settings['order'] ?? '';
        $data = json_decode($raw, true);

        if (empty($data) || !is_array($data)) {
            return;
        }

        $visible_slugs = $data['order'] ?? [];
        $hidden_slugs  = $data['hidden'] ?? [];

        // Hide menu items dragged into the hidden section
        if (!empty($hidden_slugs)) {
            add_action('admin_menu', function () use ($hidden_slugs) {
                foreach ($hidden_slugs as $slug) {
                    remove_menu_page($slug);
                }
            }, 9999);
        }

        // Reorder visible items
        if (!empty($visible_slugs)) {
            add_filter('custom_menu_order', '__return_true');

            add_filter('menu_order', function ($menu_order) use ($visible_slugs) {
                $ordered = [];
                foreach ($visible_slugs as $slug) {
                    if (in_array($slug, $menu_order, true)) {
                        $ordered[] = $slug;
                    }
                }
                foreach ($menu_order as $slug) {
                    if (!in_array($slug, $ordered, true)) {
                        $ordered[] = $slug;
                    }
                }
                return $ordered;
            });
        }
    },
];

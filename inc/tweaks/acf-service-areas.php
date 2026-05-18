<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_service_areas',
    'label' => 'Service Areas',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the Service Areas custom post type and the Region taxonomy.',
        ],
        [
            'id'        => 'import',
            'type'      => 'service_area_import',
            'label'     => 'Import Service Areas',
            'accordion' => true,
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('ddwpt_localize_data', function ($data) {
            $data['saImportNonce'] = wp_create_nonce('ddwpt_sa_import');
            return $data;
        });

        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook !== 'tools_page_ddwptweaks') return;
            if (defined('DDWPT_JSON_EDITOR_ENQUEUED')) return;
            define('DDWPT_JSON_EDITOR_ENQUEUED', true);
            $editor_settings = wp_enqueue_code_editor(['type' => 'application/json']);
            if ($editor_settings !== false) {
                wp_add_inline_script(
                    'ddwpt-settings',
                    'var ddwptJsonEditorSettings = ' . wp_json_encode($editor_settings) . ';',
                    'before'
                );
            }
        });

        add_action('wp_ajax_ddwpt_sa_import', function () {
            check_ajax_referer('ddwpt_sa_import', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            $raw   = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
            $items = json_decode($raw, true);

            if (!is_array($items) || empty($items)) {
                wp_send_json_error('Expected a non-empty JSON array.');
            }

            $md_inline = function (string $text): string {
                $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
                $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
                $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
                $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
                $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);
                $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
                $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);
                return $text;
            };

            $md_to_blocks = function (string $md) use ($md_inline): string {
                $lines      = preg_split('/\r?\n/', $md);
                $blocks     = [];
                $para       = [];
                $list_items = [];
                $list_ordered = false;

                $flush_para = function () use (&$para, &$blocks, $md_inline) {
                    if (empty($para)) return;
                    $text     = $md_inline(implode(' ', $para));
                    $blocks[] = '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
                    $para     = [];
                };

                $flush_list = function () use (&$list_items, &$list_ordered, &$blocks, $md_inline) {
                    if (empty($list_items)) return;
                    $tag   = $list_ordered ? 'ol' : 'ul';
                    $attr  = $list_ordered ? ' {"ordered":true}' : '';
                    $inner = '';
                    foreach ($list_items as $item) {
                        $inner .= '<!-- wp:list-item --><li>' . $md_inline($item) . '</li><!-- /wp:list-item -->';
                    }
                    $blocks[]   = '<!-- wp:list' . $attr . ' --><' . $tag . ' class="wp-block-list">' . $inner . '</' . $tag . '><!-- /wp:list -->';
                    $list_items   = [];
                    $list_ordered = false;
                };

                foreach ($lines as $line) {
                    if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                        $flush_para();
                        $flush_list();
                        $level    = strlen($m[1]);
                        $text     = $md_inline($m[2]);
                        $blocks[] = '<!-- wp:heading {"level":' . $level . '} --><h' . $level . ' class="wp-block-heading">' . $text . '</h' . $level . '><!-- /wp:heading -->';
                        continue;
                    }
                    if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) {
                        $flush_para();
                        if ($list_ordered) $flush_list();
                        $list_items[] = $m[1];
                        continue;
                    }
                    if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
                        $flush_para();
                        if (!$list_ordered && !empty($list_items)) $flush_list();
                        $list_ordered = true;
                        $list_items[] = $m[1];
                        continue;
                    }
                    if (trim($line) === '') {
                        $flush_para();
                        $flush_list();
                        continue;
                    }
                    $flush_list();
                    $para[] = trim($line);
                }

                $flush_para();
                $flush_list();

                return implode("\n", $blocks);
            };

            $created = 0;
            $skipped = 0;

            foreach ($items as $item) {
                if (empty($item['title'])) {
                    $skipped++;
                    continue;
                }

                $content = '';
                if (!empty($item['content'])) {
                    $content = $md_to_blocks((string) $item['content']);
                }

                $post_id = wp_insert_post([
                    'post_type'    => 'service-area',
                    'post_title'   => sanitize_text_field($item['title']),
                    'post_content' => $content,
                    'post_status'  => 'publish',
                ], true);

                if (is_wp_error($post_id)) {
                    $skipped++;
                    continue;
                }

                if (!empty($item['taxonomy'])) {
                    $term = get_term_by('slug', sanitize_key($item['taxonomy']), 'region');
                    if ($term) {
                        wp_set_post_terms($post_id, [$term->term_id], 'region');
                    }
                }

                $created++;
            }

            $message = sprintf('%d service area%s created', $created, $created === 1 ? '' : 's');
            if ($skipped > 0) {
                $message .= sprintf(', %d skipped (missing title or insert error)', $skipped);
            }

            wp_send_json_success(['message' => $message . '.']);
        });

        register_taxonomy('region', ['service-area'], [
            'labels' => [
                'name'                  => 'Regions',
                'singular_name'         => 'Region',
                'menu_name'             => 'Regions',
                'all_items'             => 'All Regions',
                'edit_item'             => 'Edit Region',
                'view_item'             => 'View Region',
                'update_item'           => 'Update Region',
                'add_new_item'          => 'Add New Region',
                'new_item_name'         => 'New Region Name',
                'parent_item'           => 'Parent Region',
                'parent_item_colon'     => 'Parent Region:',
                'search_items'          => 'Search Regions',
                'not_found'             => 'No regions found',
                'no_terms'              => 'No regions',
                'filter_by_item'        => 'Filter by region',
                'items_list_navigation' => 'Regions list navigation',
                'items_list'            => 'Regions list',
                'back_to_items'         => '← Go to regions',
                'item_link'             => 'Region Link',
                'item_link_description' => 'A link to a region',
            ],
            'public'           => true,
            'hierarchical'     => true,
            'show_in_menu'     => true,
            'show_in_rest'     => true,
            'show_admin_column' => true,
        ]);

        register_post_type('service-area', [
            'labels' => [
                'name'                  => 'Service Areas',
                'singular_name'         => 'Service Area',
                'menu_name'             => 'Areas',
                'all_items'             => 'All Service Areas',
                'edit_item'             => 'Edit Service Area',
                'view_item'             => 'View Service Area',
                'view_items'            => 'View Service Areas',
                'add_new_item'          => 'Add New Service Area',
                'add_new'               => 'Add New Service Area',
                'new_item'              => 'New Service Area',
                'parent_item_colon'     => 'Parent Service Area:',
                'search_items'          => 'Search Service Areas',
                'not_found'             => 'No service areas found',
                'not_found_in_trash'    => 'No service areas found in Trash',
                'archives'              => 'Service Area Archives',
                'attributes'            => 'Service Area Attributes',
                'insert_into_item'      => 'Insert into service area',
                'uploaded_to_this_item' => 'Uploaded to this service area',
                'filter_items_list'     => 'Filter service areas list',
                'filter_by_date'        => 'Filter service areas by date',
                'items_list_navigation' => 'Service Areas list navigation',
                'items_list'            => 'Service Areas list',
                'item_published'        => 'Service Area published.',
                'item_published_privately' => 'Service Area published privately.',
                'item_reverted_to_draft'   => 'Service Area reverted to draft.',
                'item_scheduled'        => 'Service Area scheduled.',
                'item_updated'          => 'Service Area updated.',
                'item_link'             => 'Service Area Link',
                'item_link_description' => 'A link to a service area.',
            ],
            'public'           => true,
            'show_in_rest'     => true,
            'menu_icon'        => 'dashicons-location-alt',
            'supports'         => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'has_archive'      => 'areas',
            'rewrite'          => ['feeds' => false],
            'delete_with_user' => false,
        ]);
    },
];

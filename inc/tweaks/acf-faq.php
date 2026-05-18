<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_faq',
    'label' => 'FAQs',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the FAQ custom post type and the Relationship: FAQs ACF field group.',
        ],
        [
            'id'      => 'post_types',
            'type'    => 'checkboxes',
            'label'   => 'Show field on',
            'options' => function () {
                $post_types = get_post_types(['show_ui' => true], 'objects');
                $options = [];
                foreach ($post_types as $slug => $obj) {
                    $options[$slug] = $obj->labels->singular_name;
                }
                return $options;
            },
            'default' => '["page","post"]',
        ],
        [
            'id'        => 'field_keys',
            'type'      => 'acf_keys',
            'label'     => 'Field Names',
            'accordion' => true,
            'keys'      => [
                ['name' => 'post_title',  'type' => 'text',         'label' => 'Question — stored as post title'],
                ['name' => 'post_content','type' => 'html',         'label' => 'Answer — stored as post content'],
                ['name' => 'faq-tag',     'type' => 'taxonomy',     'label' => 'FAQ Tag — custom taxonomy term slug'],
                ['name' => 'linked_faqs', 'type' => 'relationship', 'label' => 'Linked FAQs — relationship field on selected post types'],
            ],
        ],
        [
            'id'        => 'import',
            'type'      => 'faq_import',
            'label'     => 'Import FAQs',
            'accordion' => true,
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        register_taxonomy('faq-tag', 'faq', [
            'labels' => [
                'name'          => 'FAQ Tags',
                'singular_name' => 'FAQ Tag',
                'search_items'  => 'Search FAQ Tags',
                'all_items'     => 'All FAQ Tags',
                'edit_item'     => 'Edit FAQ Tag',
                'update_item'   => 'Update FAQ Tag',
                'add_new_item'  => 'Add New FAQ Tag',
                'new_item_name' => 'New FAQ Tag Name',
                'menu_name'     => 'FAQ Tags',
            ],
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'faq-tag'],
        ]);

        register_post_type('faq', [
            'labels' => [
                'name'                     => 'FAQs',
                'singular_name'            => 'FAQ',
                'menu_name'                => 'FAQs',
                'all_items'                => 'All FAQs',
                'edit_item'                => 'Edit FAQ',
                'view_item'                => 'View FAQ',
                'view_items'               => 'View FAQs',
                'add_new_item'             => 'Add New FAQ',
                'add_new'                  => 'Add New FAQ',
                'new_item'                 => 'New FAQ',
                'parent_item_colon'        => 'Parent FAQ:',
                'search_items'             => 'Search FAQs',
                'not_found'                => 'No faqs found',
                'not_found_in_trash'       => 'No faqs found in Trash',
                'archives'                 => 'FAQ Archives',
                'attributes'               => 'FAQ Attributes',
                'insert_into_item'         => 'Insert into faq',
                'uploaded_to_this_item'    => 'Uploaded to this faq',
                'filter_items_list'        => 'Filter faqs list',
                'filter_by_date'           => 'Filter faqs by date',
                'items_list_navigation'    => 'FAQs list navigation',
                'items_list'               => 'FAQs list',
                'item_published'           => 'FAQ published.',
                'item_published_privately' => 'FAQ published privately.',
                'item_reverted_to_draft'   => 'FAQ reverted to draft.',
                'item_scheduled'           => 'FAQ scheduled.',
                'item_updated'             => 'FAQ updated.',
                'item_link'                => 'FAQ Link',
                'item_link_description'    => 'A link to a faq.',
            ],
            'public'             => true,
            'publicly_queryable' => false,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-align-center',
            'supports'           => ['title', 'editor', 'custom-fields'],
            'taxonomies'         => ['faq-tag'],
            'delete_with_user'   => false,
        ]);

        add_filter('ddwpt_localize_data', function ($data) {
            $data['faqImportNonce'] = wp_create_nonce('ddwpt_faq_import');
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

        add_action('wp_ajax_ddwpt_faq_import', function () {
            check_ajax_referer('ddwpt_faq_import', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            $raw   = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
            $items = json_decode($raw, true);

            if (!is_array($items) || empty($items)) {
                wp_send_json_error('Expected a non-empty JSON array.');
            }

            $created = 0;
            $skipped = 0;

            foreach ($items as $item) {
                if (empty($item['question']) || empty($item['answer'])) {
                    $skipped++;
                    continue;
                }

                $post_id = wp_insert_post([
                    'post_type'    => 'faq',
                    'post_title'   => sanitize_text_field($item['question']),
                    'post_content' => wp_kses_post($item['answer']),
                    'post_status'  => 'publish',
                ], true);

                if (is_wp_error($post_id)) {
                    $skipped++;
                    continue;
                }

                if (!empty($item['taxonomy'])) {
                    $term = get_term_by('slug', sanitize_key($item['taxonomy']), 'faq-tag');
                    if ($term) {
                        wp_set_post_terms($post_id, [$term->term_id], 'faq-tag');
                    }
                }

                $created++;
            }

            $message = sprintf('%d FAQ%s created', $created, $created === 1 ? '' : 's');
            if ($skipped > 0) {
                $message .= sprintf(', %d skipped (missing question or answer, or insert error)', $skipped);
            }

            wp_send_json_success(['message' => $message . '.']);
        });

        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $selected = json_decode($settings['post_types'] ?? '[]', true);
        if (empty($selected) || !is_array($selected)) {
            return;
        }

        $location = array_map(
            fn($slug) => [['param' => 'post_type', 'operator' => '==', 'value' => $slug]],
            $selected
        );

        acf_add_local_field_group([
            'key'    => 'group_695c328871ee2',
            'title'  => 'Relationship: FAQs',
            'fields' => [
                [
                    'key'               => 'field_695c3288d1891',
                    'label'             => 'Linked FAQs',
                    'name'              => 'linked_faqs',
                    'type'              => 'relationship',
                    'post_type'         => ['faq'],
                    'filters'           => ['search', 'taxonomy'],
                    'return_format'     => 'object',
                    'bidirectional'     => 0,
                    'allow_in_bindings' => 0,
                ],
            ],
            'location'      => $location,
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'FAQs',
        ]);
    },
];

<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_testimonials',
    'label' => 'Testimonials',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the Testimonials custom post type, the Testimonial Category taxonomy, and ACF detail and relationship field groups.',
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
            'default' => '["page"]',
        ],
        [
            'id'        => 'import',
            'type'      => 'testimonial_import',
            'label'     => 'Import Testimonials',
            'accordion' => true,
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('ddwpt_localize_data', function ($data) {
            $data['testimonialImportNonce'] = wp_create_nonce('ddwpt_testimonial_import');
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

        add_action('wp_ajax_ddwpt_testimonial_import', function () {
            check_ajax_referer('ddwpt_testimonial_import', 'nonce');
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
                if (empty($item['name'])) {
                    $skipped++;
                    continue;
                }

                $post_id = wp_insert_post([
                    'post_type'    => 'testimonial',
                    'post_title'   => sanitize_text_field($item['name']),
                    'post_content' => !empty($item['quote']) ? wp_kses_post($item['quote']) : '',
                    'post_status'  => 'publish',
                ], true);

                if (is_wp_error($post_id)) {
                    $skipped++;
                    continue;
                }

                if (!empty($item['taxonomy'])) {
                    $term = get_term_by('slug', sanitize_key($item['taxonomy']), 'testimonial-category');
                    if ($term) {
                        wp_set_post_terms($post_id, [$term->term_id], 'testimonial-category');
                    }
                }

                $acf_available = function_exists('update_field');

                if (!empty($item['company'])) {
                    $val = sanitize_text_field($item['company']);
                    $acf_available ? update_field('tb_company', $val, $post_id) : update_post_meta($post_id, 'tb_company', $val);
                }
                if (!empty($item['role'])) {
                    $val = sanitize_text_field($item['role']);
                    $acf_available ? update_field('tb_role', $val, $post_id) : update_post_meta($post_id, 'tb_role', $val);
                }
                if (isset($item['rating'])) {
                    $val = min(5, max(1, (int) $item['rating']));
                    $acf_available ? update_field('tb_rating', $val, $post_id) : update_post_meta($post_id, 'tb_rating', $val);
                }

                $created++;
            }

            $message = sprintf('%d testimonial%s created', $created, $created === 1 ? '' : 's');
            if ($skipped > 0) {
                $message .= sprintf(', %d skipped (missing name or insert error)', $skipped);
            }

            wp_send_json_success(['message' => $message . '.']);
        });

        register_taxonomy('testimonial-category', ['testimonial'], [
            'labels' => [
                'name'                  => 'Testimonial Categories',
                'singular_name'         => 'Testimonial Category',
                'menu_name'             => 'Categories',
                'all_items'             => 'All Categories',
                'edit_item'             => 'Edit Category',
                'update_item'           => 'Update Category',
                'add_new_item'          => 'Add New Category',
                'new_item_name'         => 'New Category Name',
                'search_items'          => 'Search Categories',
                'not_found'             => 'No categories found',
                'no_terms'              => 'No categories',
                'items_list_navigation' => 'Categories list navigation',
                'items_list'            => 'Categories list',
                'back_to_items'         => '← Go to categories',
            ],
            'hierarchical'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'testimonial-category'],
        ]);

        register_post_type('testimonial', [
            'labels' => [
                'name'                     => 'Testimonials',
                'singular_name'            => 'Testimonial',
                'menu_name'                => 'Testimonials',
                'all_items'                => 'All Testimonials',
                'edit_item'                => 'Edit Testimonial',
                'view_item'                => 'View Testimonial',
                'view_items'               => 'View Testimonials',
                'add_new_item'             => 'Add New Testimonial',
                'add_new'                  => 'Add New Testimonial',
                'new_item'                 => 'New Testimonial',
                'search_items'             => 'Search Testimonials',
                'not_found'                => 'No testimonials found',
                'not_found_in_trash'       => 'No testimonials found in Trash',
                'archives'                 => 'Testimonial Archives',
                'attributes'               => 'Testimonial Attributes',
                'insert_into_item'         => 'Insert into testimonial',
                'uploaded_to_this_item'    => 'Uploaded to this testimonial',
                'filter_items_list'        => 'Filter testimonials list',
                'filter_by_date'           => 'Filter testimonials by date',
                'items_list_navigation'    => 'Testimonials list navigation',
                'items_list'               => 'Testimonials list',
                'item_published'           => 'Testimonial published.',
                'item_published_privately' => 'Testimonial published privately.',
                'item_reverted_to_draft'   => 'Testimonial reverted to draft.',
                'item_scheduled'           => 'Testimonial scheduled.',
                'item_updated'             => 'Testimonial updated.',
                'item_link'                => 'Testimonial Link',
                'item_link_description'    => 'A link to a testimonial.',
            ],
            'public'             => true,
            'publicly_queryable' => false,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-format-quote',
            'supports'           => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'taxonomies'         => ['testimonial-category'],
            'delete_with_user'   => false,
        ]);

        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'    => 'group_68293b1c4d2a1',
            'title'  => 'Testimonial: Details',
            'fields' => [
                [
                    'key'               => 'field_68293b1c4d2a2',
                    'label'             => 'Company',
                    'name'              => 'tb_company',
                    'type'              => 'text',
                    'instructions'      => '',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => '',
                ],
                [
                    'key'               => 'field_68293b1c4d2a3',
                    'label'             => 'Role',
                    'name'              => 'tb_role',
                    'type'              => 'text',
                    'instructions'      => '',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => '',
                ],
                [
                    'key'               => 'field_68293b1c4d2a4',
                    'label'             => 'Rating',
                    'name'              => 'tb_rating',
                    'type'              => 'number',
                    'instructions'      => 'Score out of 5.',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => '',
                    'min'               => 1,
                    'max'               => 5,
                    'step'              => 1,
                ],
            ],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'testimonial']],
            ],
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'Details',
        ]);

        $selected = json_decode($settings['post_types'] ?? '[]', true);
        if (empty($selected) || !is_array($selected)) {
            return;
        }

        $location = array_map(
            fn($slug) => [['param' => 'post_type', 'operator' => '==', 'value' => $slug]],
            $selected
        );

        acf_add_local_field_group([
            'key'    => 'group_68293b1c4d2a5',
            'title'  => 'Relationship: Testimonials',
            'fields' => [
                [
                    'key'               => 'field_68293b1c4d2a6',
                    'label'             => 'Linked Testimonials',
                    'name'              => 'linked_testimonials',
                    'type'              => 'relationship',
                    'post_type'         => ['testimonial'],
                    'filters'           => ['search', 'taxonomy'],
                    'return_format'     => 'object',
                    'bidirectional'     => 0,
                    'allow_in_bindings' => 0,
                ],
            ],
            'location'      => $location,
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'Testimonials',
        ]);
    },
];

<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_testimonials',
    'label' => 'Testimonials',
    'tab'   => 'acf',
    'group' => 'presets',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the Testimonials custom post type, the Testimonial Category taxonomy, and ACF detail and relationship field groups.',
        ],
        [
            'id'          => 'sync_wpsr',
            'type'        => 'checkbox',
            'label'       => 'Sync from WP Social Ninja',
            'description' => 'Automatically create testimonial posts from WP Social Ninja reviews. New manual reviews sync immediately; platform reviews (Google, Facebook, etc.) sync in configurable batches per hour.',
        ],
        [
            'id'      => 'sync_wpsr_min_rating',
            'type'    => 'select',
            'label'   => 'Minimum rating to sync',
            'options' => [
                '0' => 'Any rating',
                '3' => '3+ stars',
                '4' => '4+ stars',
                '5' => '5 stars only',
            ],
            'default' => '4',
        ],
        [
            'id'          => 'sync_wpsr_batch_size',
            'type'        => 'text',
            'label'       => 'Sync batch size',
            'description' => 'Number of platform reviews (Google, Facebook, etc.) processed per hourly run. Raise temporarily to catch up after a gap; lower to reduce server load. Default: 25.',
            'default'     => '25',
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
            'id'        => 'field_keys',
            'type'      => 'acf_keys',
            'label'     => 'Field Names',
            'accordion' => true,
            'keys'      => [
                ['name' => 'post_title',           'type' => 'text',         'label' => 'Reviewer name — stored as post title'],
                ['name' => 'post_content',          'type' => 'html',         'label' => 'Quote — stored as post content'],
                ['name' => 'testimonial-category', 'type' => 'taxonomy',     'label' => 'Category — custom taxonomy term slug'],
                ['name' => 'tb_company',           'type' => 'text',         'label' => 'Company name'],
                ['name' => 'tb_role',              'type' => 'text',         'label' => 'Role / position'],
                ['name' => 'tb_rating',            'type' => 'number',       'label' => 'Rating (1–5)'],
                ['name' => 'tb_avatar',            'type' => 'url',          'label' => 'Avatar URL — reviewer profile image (populated automatically from WPSR)'],
                ['name' => 'linked_testimonials',  'type' => 'relationship', 'label' => 'Linked testimonials — relationship field on selected post types'],
            ],
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
                if (!empty($item['avatar'])) {
                    $val = esc_url_raw($item['avatar']);
                    $acf_available ? update_field('tb_avatar', $val, $post_id) : update_post_meta($post_id, 'tb_avatar', $val);
                }

                $created++;
            }

            $message = sprintf('%d testimonial%s created', $created, $created === 1 ? '' : 's');
            if ($skipped > 0) {
                $message .= sprintf(', %d skipped (missing name or insert error)', $skipped);
            }

            wp_send_json_success(['message' => $message . '.']);
        });

        // ── WP Social Ninja sync ──────────────────────────────────────────
        //
        // Reviews are stored in {prefix}wpsr_reviews. The plugin fires
        // wpsocialreviews/custom_review_created for manual reviews only.
        // Platform syncs (Google, Facebook, etc.) batch-insert with no hook,
        // so we also run an hourly cron that processes unsynced rows in
        // batches of 25 to avoid overwhelming the server on first setup.
        //
        // Each synced review is tracked via a ddwpt_wpsr_review_id post meta
        // on the testimonial post so both paths stay idempotent.

        /**
         * Create a single testimonial from a wpsr_reviews row.
         * Returns the new post ID on success, false if skipped/failed.
         */
        $create_from_wpsr = function ($row) {
            global $wpdb;

            $review_id  = (int) $row->id;
            $reviewer   = sanitize_text_field((string) ($row->reviewer_name ?: 'Anonymous'));
            $text       = wp_kses_post((string) $row->reviewer_text);
            $rating     = (int) $row->rating;
            $platform   = sanitize_key((string) $row->platform_name);
            $fields_raw = $row->fields ?? null;

            // Skip below minimum rating (re-reads option so cron respects live changes)
            $min_rating = (int) get_option('ddwpt_acf_testimonials_sync_wpsr_min_rating', 0);
            if ($min_rating > 0 && $rating < $min_rating) {
                return false;
            }

            // Skip if already synced
            $existing = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ddwpt_wpsr_review_id' AND meta_value = %d LIMIT 1",
                $review_id
            ));
            if ($existing) {
                return false;
            }

            $post_args = [
                'post_type'    => 'testimonial',
                'post_title'   => $reviewer,
                'post_content' => $text,
                'post_status'  => 'publish',
            ];

            if (!empty($row->review_time)) {
                $timestamp           = strtotime($row->review_time);
                $post_args['post_date']     = date('Y-m-d H:i:s', $timestamp);
                $post_args['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp);
            }

            $post_id = wp_insert_post($post_args, true);

            if (is_wp_error($post_id)) {
                return false;
            }

            update_post_meta($post_id, 'ddwpt_wpsr_review_id', $review_id);

            // Auto-create/assign a testimonial-category term from the platform name.
            if (!empty($platform)) {
                $term_label = ucwords(str_replace(['-', '_'], ' ', $platform));
                $term = get_term_by('name', $term_label, 'testimonial-category');
                if (!$term) {
                    $result  = wp_insert_term($term_label, 'testimonial-category');
                    $term_id = !is_wp_error($result) ? $result['term_id'] : null;
                } else {
                    $term_id = $term->term_id;
                }
                if (!empty($term_id)) {
                    wp_set_post_terms($post_id, [$term_id], 'testimonial-category');
                }
            }

            $acf_available = function_exists('update_field');

            if ($rating >= 1 && $rating <= 5) {
                $acf_available
                    ? update_field('tb_rating', $rating, $post_id)
                    : update_post_meta($post_id, 'tb_rating', $rating);
            }

            $extra = is_string($fields_raw) ? json_decode($fields_raw, true) : (array) $fields_raw;

            if (!empty($extra['author_company'])) {
                $val = sanitize_text_field($extra['author_company']);
                $acf_available ? update_field('tb_company', $val, $post_id) : update_post_meta($post_id, 'tb_company', $val);
            }
            if (!empty($extra['author_position'])) {
                $val = sanitize_text_field($extra['author_position']);
                $acf_available ? update_field('tb_role', $val, $post_id) : update_post_meta($post_id, 'tb_role', $val);
            }

            // reviewer_img is a direct column (Google, Facebook, etc. return a profile URL).
            $avatar_url = !empty($row->reviewer_img) ? esc_url_raw((string) $row->reviewer_img) : '';
            if (empty($avatar_url) && !empty($extra['reviewer_img'])) {
                $avatar_url = esc_url_raw((string) $extra['reviewer_img']);
            }
            if (!empty($avatar_url)) {
                $acf_available ? update_field('tb_avatar', $avatar_url, $post_id) : update_post_meta($post_id, 'tb_avatar', $avatar_url);
            }

            return $post_id;
        };

        // Immediate sync for manually-created reviews.
        add_action('wpsocialreviews/custom_review_created', function ($review) use ($create_from_wpsr) {
            if (!get_option('ddwpt_acf_testimonials_sync_wpsr')) return;
            // Model object → stdClass so the helper can use property access uniformly.
            $row = (object) (method_exists($review, 'toArray') ? $review->toArray() : (array) $review);
            $create_from_wpsr($row);
        });

        // Hourly batch sync for platform reviews (Google, Facebook, etc.) which
        // are inserted directly into the DB with no WordPress hook.
        $batch_sync = function () use ($create_from_wpsr) {
            if (!get_option('ddwpt_acf_testimonials_sync_wpsr')) return;

            global $wpdb;
            $table = $wpdb->prefix . 'wpsr_reviews';

            // Collect already-synced WPSR review IDs via postmeta.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $synced_ids = $wpdb->get_col(
                "SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta} WHERE meta_key = 'ddwpt_wpsr_review_id'"
            );

            $min_rating = (int) get_option('ddwpt_acf_testimonials_sync_wpsr_min_rating', 0);

            $where = 'WHERE review_approved = 1';

            if (!empty($synced_ids)) {
                // Safe: values are already cast to integers above.
                $id_list = implode(',', array_map('intval', $synced_ids));
                $where  .= " AND id NOT IN ({$id_list})";
            }

            if ($min_rating > 0) {
                $where .= $wpdb->prepare(' AND rating >= %d', $min_rating); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }

            $batch_size = max(1, (int) get_option('ddwpt_acf_testimonials_sync_wpsr_batch_size', 25));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $reviews = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY id ASC LIMIT %d", $batch_size));

            if (empty($reviews)) return;

            foreach ($reviews as $review) {
                $create_from_wpsr($review);
            }
        };

        add_action('ddwpt_testimonial_wpsr_sync', $batch_sync);

        if (!empty($settings['sync_wpsr'])) {
            if (!wp_next_scheduled('ddwpt_testimonial_wpsr_sync')) {
                wp_schedule_event(time(), 'hourly', 'ddwpt_testimonial_wpsr_sync');
            }
        }

        // ── CPT + taxonomy ────────────────────────────────────────────────

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

        // ── ACF field groups ──────────────────────────────────────────────

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
                [
                    'key'               => 'field_68293b1c4d2a7',
                    'label'             => 'Avatar URL',
                    'name'              => 'tb_avatar',
                    'type'              => 'url',
                    'instructions'      => 'Reviewer profile image URL. Populated automatically when syncing from WP Social Ninja.',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => 'https://',
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

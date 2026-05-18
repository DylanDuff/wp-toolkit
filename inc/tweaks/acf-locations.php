<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_locations',
    'label' => 'Locations',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the Locations custom post type, the Location Type taxonomy, and an ACF details field group.',
        ],
        [
            'id'        => 'field_keys',
            'type'      => 'acf_keys',
            'label'     => 'Field Names',
            'accordion' => true,
            'keys'      => [
                ['name' => 'post_title',    'type' => 'text',     'label' => 'Location name — stored as post title'],
                ['name' => 'post_content',  'type' => 'html',     'label' => 'Description — stored as post content'],
                ['name' => 'location-type', 'type' => 'taxonomy', 'label' => 'Location Type — custom taxonomy term slug'],
                ['name' => 'lc_address',    'type' => 'textarea', 'label' => 'Street address'],
                ['name' => 'lc_phone',      'type' => 'text',     'label' => 'Phone number'],
                ['name' => 'lc_email',      'type' => 'email',    'label' => 'Email address'],
                ['name' => 'lc_hours',      'type' => 'textarea', 'label' => 'Opening hours'],
                ['name' => 'lc_map_embed',  'type' => 'textarea', 'label' => 'Map embed code'],
            ],
        ],
        [
            'id'        => 'import',
            'type'      => 'location_import',
            'label'     => 'Import Locations',
            'accordion' => true,
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('ddwpt_localize_data', function ($data) {
            $data['locationImportNonce'] = wp_create_nonce('ddwpt_location_import');
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

        add_action('wp_ajax_ddwpt_location_import', function () {
            check_ajax_referer('ddwpt_location_import', 'nonce');
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
                    'post_type'    => 'location',
                    'post_title'   => sanitize_text_field($item['name']),
                    'post_content' => !empty($item['description']) ? wp_kses_post($item['description']) : '',
                    'post_status'  => 'publish',
                ], true);

                if (is_wp_error($post_id)) {
                    $skipped++;
                    continue;
                }

                if (!empty($item['taxonomy'])) {
                    $term = get_term_by('slug', sanitize_key($item['taxonomy']), 'location-type');
                    if ($term) {
                        wp_set_post_terms($post_id, [$term->term_id], 'location-type');
                    }
                }

                $acf_available = function_exists('update_field');

                $text_fields = ['address' => 'lc_address', 'phone' => 'lc_phone', 'email' => 'lc_email', 'hours' => 'lc_hours', 'map_embed' => 'lc_map_embed'];
                foreach ($text_fields as $key => $acf_name) {
                    if (empty($item[$key])) continue;
                    $val = sanitize_textarea_field($item[$key]);
                    $acf_available ? update_field($acf_name, $val, $post_id) : update_post_meta($post_id, $acf_name, $val);
                }

                $created++;
            }

            $message = sprintf('%d location%s created', $created, $created === 1 ? '' : 's');
            if ($skipped > 0) {
                $message .= sprintf(', %d skipped (missing name or insert error)', $skipped);
            }

            wp_send_json_success(['message' => $message . '.']);
        });

        register_taxonomy('location-type', ['location'], [
            'labels' => [
                'name'                  => 'Location Types',
                'singular_name'         => 'Location Type',
                'menu_name'             => 'Location Types',
                'all_items'             => 'All Location Types',
                'edit_item'             => 'Edit Location Type',
                'update_item'           => 'Update Location Type',
                'add_new_item'          => 'Add New Location Type',
                'new_item_name'         => 'New Location Type Name',
                'search_items'          => 'Search Location Types',
                'not_found'             => 'No location types found',
                'no_terms'              => 'No location types',
                'items_list_navigation' => 'Location Types list navigation',
                'items_list'            => 'Location Types list',
                'back_to_items'         => '← Go to location types',
            ],
            'hierarchical'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'location-type'],
        ]);

        register_post_type('location', [
            'labels' => [
                'name'                     => 'Locations',
                'singular_name'            => 'Location',
                'menu_name'                => 'Locations',
                'all_items'                => 'All Locations',
                'edit_item'                => 'Edit Location',
                'view_item'                => 'View Location',
                'view_items'               => 'View Locations',
                'add_new_item'             => 'Add New Location',
                'add_new'                  => 'Add New Location',
                'new_item'                 => 'New Location',
                'search_items'             => 'Search Locations',
                'not_found'                => 'No locations found',
                'not_found_in_trash'       => 'No locations found in Trash',
                'archives'                 => 'Location Archives',
                'attributes'               => 'Location Attributes',
                'insert_into_item'         => 'Insert into location',
                'uploaded_to_this_item'    => 'Uploaded to this location',
                'filter_items_list'        => 'Filter locations list',
                'filter_by_date'           => 'Filter locations by date',
                'items_list_navigation'    => 'Locations list navigation',
                'items_list'               => 'Locations list',
                'item_published'           => 'Location published.',
                'item_published_privately' => 'Location published privately.',
                'item_reverted_to_draft'   => 'Location reverted to draft.',
                'item_scheduled'           => 'Location scheduled.',
                'item_updated'             => 'Location updated.',
                'item_link'                => 'Location Link',
                'item_link_description'    => 'A link to a location.',
            ],
            'public'             => true,
            'publicly_queryable' => true,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-location',
            'supports'           => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'taxonomies'         => ['location-type'],
            'has_archive'        => 'locations',
            'rewrite'            => ['feeds' => false],
            'delete_with_user'   => false,
        ]);

        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'    => 'group_68293c2d5e3b1',
            'title'  => 'Location: Details',
            'fields' => [
                [
                    'key'               => 'field_68293c2d5e3b2',
                    'label'             => 'Address',
                    'name'              => 'lc_address',
                    'type'              => 'textarea',
                    'instructions'      => '',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'rows'              => 3,
                    'new_lines'         => 'br',
                ],
                [
                    'key'               => 'field_68293c2d5e3b3',
                    'label'             => 'Phone',
                    'name'              => 'lc_phone',
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
                    'key'               => 'field_68293c2d5e3b4',
                    'label'             => 'Email',
                    'name'              => 'lc_email',
                    'type'              => 'email',
                    'instructions'      => '',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => '',
                ],
                [
                    'key'               => 'field_68293c2d5e3b5',
                    'label'             => 'Hours',
                    'name'              => 'lc_hours',
                    'type'              => 'textarea',
                    'instructions'      => 'e.g. Mon–Fri 9am–5pm',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'rows'              => 4,
                    'new_lines'         => 'br',
                ],
                [
                    'key'               => 'field_68293c2d5e3b6',
                    'label'             => 'Map Embed',
                    'name'              => 'lc_map_embed',
                    'type'              => 'textarea',
                    'instructions'      => 'Paste a Google Maps or similar embed code here.',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 0,
                    'rows'              => 4,
                    'new_lines'         => '',
                ],
            ],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'location']],
            ],
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'Details',
        ]);
    },
];

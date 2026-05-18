<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_team_members',
    'label' => 'Team Members',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the Team Members custom post type, the Job Title taxonomy, and ACF profile and relationship field groups.',
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
            'type'      => 'team_member_import',
            'label'     => 'Import Team Members',
            'accordion' => true,
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('ddwpt_localize_data', function ($data) {
            $data['tmImportNonce'] = wp_create_nonce('ddwpt_tm_import');
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

        add_action('wp_ajax_ddwpt_tm_import', function () {
            check_ajax_referer('ddwpt_tm_import', 'nonce');
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
                $lines        = preg_split('/\r?\n/', $md);
                $blocks       = [];
                $para         = [];
                $list_items   = [];
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
                    $blocks[]     = '<!-- wp:list' . $attr . ' --><' . $tag . ' class="wp-block-list">' . $inner . '</' . $tag . '><!-- /wp:list -->';
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
                if (empty($item['name'])) {
                    $skipped++;
                    continue;
                }

                $content = '';
                if (!empty($item['bio'])) {
                    $content = $md_to_blocks((string) $item['bio']);
                }

                $post_id = wp_insert_post([
                    'post_type'    => 'team-member',
                    'post_title'   => sanitize_text_field($item['name']),
                    'post_content' => $content,
                    'post_status'  => 'publish',
                ], true);

                if (is_wp_error($post_id)) {
                    $skipped++;
                    continue;
                }

                if (!empty($item['taxonomy'])) {
                    $term = get_term_by('slug', sanitize_key($item['taxonomy']), 'job-title');
                    if ($term) {
                        wp_set_post_terms($post_id, [$term->term_id], 'job-title');
                    }
                }

                $acf_available = function_exists('update_field');

                if (!empty($item['email'])) {
                    $val = sanitize_email($item['email']);
                    $acf_available ? update_field('tm_email', $val, $post_id) : update_post_meta($post_id, 'tm_email', $val);
                }
                if (!empty($item['phone'])) {
                    $val = sanitize_text_field($item['phone']);
                    $acf_available ? update_field('tm_phone', $val, $post_id) : update_post_meta($post_id, 'tm_phone', $val);
                }
                if (!empty($item['linkedin'])) {
                    $val = esc_url_raw($item['linkedin']);
                    $acf_available ? update_field('tm_linkedin_url', $val, $post_id) : update_post_meta($post_id, 'tm_linkedin_url', $val);
                }

                $created++;
            }

            $message = sprintf('%d team member%s created', $created, $created === 1 ? '' : 's');
            if ($skipped > 0) {
                $message .= sprintf(', %d skipped (missing name or insert error)', $skipped);
            }

            wp_send_json_success(['message' => $message . '.']);
        });

        register_taxonomy('job-title', ['team-member'], [
            'labels' => [
                'name'                  => 'Job Titles',
                'singular_name'         => 'Job Title',
                'menu_name'             => 'Job Titles',
                'all_items'             => 'All Job Titles',
                'edit_item'             => 'Edit Job Title',
                'update_item'           => 'Update Job Title',
                'add_new_item'          => 'Add New Job Title',
                'new_item_name'         => 'New Job Title Name',
                'search_items'          => 'Search Job Titles',
                'not_found'             => 'No job titles found',
                'no_terms'              => 'No job titles',
                'items_list_navigation' => 'Job Titles list navigation',
                'items_list'            => 'Job Titles list',
                'back_to_items'         => '← Go to job titles',
            ],
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'job-title'],
        ]);

        register_post_type('team-member', [
            'labels' => [
                'name'                     => 'Team Members',
                'singular_name'            => 'Team Member',
                'menu_name'                => 'Team',
                'all_items'                => 'All Team Members',
                'edit_item'                => 'Edit Team Member',
                'view_item'                => 'View Team Member',
                'view_items'               => 'View Team Members',
                'add_new_item'             => 'Add New Team Member',
                'add_new'                  => 'Add New Team Member',
                'new_item'                 => 'New Team Member',
                'search_items'             => 'Search Team Members',
                'not_found'                => 'No team members found',
                'not_found_in_trash'       => 'No team members found in Trash',
                'archives'                 => 'Team Member Archives',
                'attributes'               => 'Team Member Attributes',
                'insert_into_item'         => 'Insert into team member',
                'uploaded_to_this_item'    => 'Uploaded to this team member',
                'filter_items_list'        => 'Filter team members list',
                'filter_by_date'           => 'Filter team members by date',
                'items_list_navigation'    => 'Team Members list navigation',
                'items_list'               => 'Team Members list',
                'item_published'           => 'Team Member published.',
                'item_published_privately' => 'Team Member published privately.',
                'item_reverted_to_draft'   => 'Team Member reverted to draft.',
                'item_scheduled'           => 'Team Member scheduled.',
                'item_updated'             => 'Team Member updated.',
                'item_link'                => 'Team Member Link',
                'item_link_description'    => 'A link to a team member.',
            ],
            'public'             => true,
            'publicly_queryable' => true,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-groups',
            'supports'           => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'taxonomies'         => ['job-title'],
            'delete_with_user'   => false,
        ]);

        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'    => 'group_68293a7c2f1e1',
            'title'  => 'Team Member: Profile',
            'fields' => [
                [
                    'key'               => 'field_68293a7c2f1e2',
                    'label'             => 'Email',
                    'name'              => 'tm_email',
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
                    'key'               => 'field_68293a7c2f1e3',
                    'label'             => 'Phone',
                    'name'              => 'tm_phone',
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
                    'key'               => 'field_68293a7c2f1e4',
                    'label'             => 'LinkedIn URL',
                    'name'              => 'tm_linkedin_url',
                    'type'              => 'url',
                    'instructions'      => '',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => '',
                ],
            ],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'team-member']],
            ],
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'Profile',
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
            'key'    => 'group_68293a7c2f1e5',
            'title'  => 'Relationship: Team Members',
            'fields' => [
                [
                    'key'               => 'field_68293a7c2f1e6',
                    'label'             => 'Linked Team Members',
                    'name'              => 'linked_team_members',
                    'type'              => 'relationship',
                    'post_type'         => ['team-member'],
                    'filters'           => ['search', 'taxonomy'],
                    'return_format'     => 'object',
                    'bidirectional'     => 0,
                    'allow_in_bindings' => 0,
                ],
            ],
            'location'      => $location,
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'Team Members',
        ]);
    },
];

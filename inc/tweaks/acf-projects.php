<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_projects',
    'label' => 'Projects',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the Projects custom post type, the Project Type taxonomy, and ACF detail and relationship field groups.',
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
                ['name' => 'post_title',      'type' => 'text',         'label' => 'Project name — stored as post title'],
                ['name' => 'post_content',    'type' => 'blocks',       'label' => 'Description — stored as Gutenberg blocks'],
                ['name' => 'project-type',    'type' => 'taxonomy',     'label' => 'Project Type — custom taxonomy term slug'],
                ['name' => 'pj_client',       'type' => 'text',         'label' => 'Client name'],
                ['name' => 'pj_url',          'type' => 'url',          'label' => 'Project URL'],
                ['name' => 'pj_year',         'type' => 'number',       'label' => 'Year completed'],
                ['name' => 'linked_projects', 'type' => 'relationship', 'label' => 'Linked projects — relationship field on selected post types'],
            ],
        ],
        [
            'id'        => 'import',
            'type'      => 'project_import',
            'label'     => 'Import Projects',
            'accordion' => true,
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('ddwpt_localize_data', function ($data) {
            $data['projectImportNonce'] = wp_create_nonce('ddwpt_project_import');
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

        add_action('wp_ajax_ddwpt_project_import', function () {
            check_ajax_referer('ddwpt_project_import', 'nonce');
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
                if (!empty($item['description'])) {
                    $content = $md_to_blocks((string) $item['description']);
                }

                $post_id = wp_insert_post([
                    'post_type'    => 'project',
                    'post_title'   => sanitize_text_field($item['name']),
                    'post_content' => $content,
                    'post_status'  => 'publish',
                ], true);

                if (is_wp_error($post_id)) {
                    $skipped++;
                    continue;
                }

                if (!empty($item['taxonomy'])) {
                    $term = get_term_by('slug', sanitize_key($item['taxonomy']), 'project-type');
                    if ($term) {
                        wp_set_post_terms($post_id, [$term->term_id], 'project-type');
                    }
                }

                $acf_available = function_exists('update_field');

                if (!empty($item['client'])) {
                    $val = sanitize_text_field($item['client']);
                    $acf_available ? update_field('pj_client', $val, $post_id) : update_post_meta($post_id, 'pj_client', $val);
                }
                if (!empty($item['url'])) {
                    $val = esc_url_raw($item['url']);
                    $acf_available ? update_field('pj_url', $val, $post_id) : update_post_meta($post_id, 'pj_url', $val);
                }
                if (!empty($item['year'])) {
                    $val = (int) $item['year'];
                    $acf_available ? update_field('pj_year', $val, $post_id) : update_post_meta($post_id, 'pj_year', $val);
                }

                $created++;
            }

            $message = sprintf('%d project%s created', $created, $created === 1 ? '' : 's');
            if ($skipped > 0) {
                $message .= sprintf(', %d skipped (missing name or insert error)', $skipped);
            }

            wp_send_json_success(['message' => $message . '.']);
        });

        register_taxonomy('project-type', ['project'], [
            'labels' => [
                'name'                  => 'Project Types',
                'singular_name'         => 'Project Type',
                'menu_name'             => 'Project Types',
                'all_items'             => 'All Project Types',
                'edit_item'             => 'Edit Project Type',
                'update_item'           => 'Update Project Type',
                'add_new_item'          => 'Add New Project Type',
                'new_item_name'         => 'New Project Type Name',
                'search_items'          => 'Search Project Types',
                'not_found'             => 'No project types found',
                'no_terms'              => 'No project types',
                'items_list_navigation' => 'Project Types list navigation',
                'items_list'            => 'Project Types list',
                'back_to_items'         => '← Go to project types',
            ],
            'hierarchical'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'project-type'],
        ]);

        register_post_type('project', [
            'labels' => [
                'name'                     => 'Projects',
                'singular_name'            => 'Project',
                'menu_name'                => 'Projects',
                'all_items'                => 'All Projects',
                'edit_item'                => 'Edit Project',
                'view_item'                => 'View Project',
                'view_items'               => 'View Projects',
                'add_new_item'             => 'Add New Project',
                'add_new'                  => 'Add New Project',
                'new_item'                 => 'New Project',
                'search_items'             => 'Search Projects',
                'not_found'                => 'No projects found',
                'not_found_in_trash'       => 'No projects found in Trash',
                'archives'                 => 'Project Archives',
                'attributes'               => 'Project Attributes',
                'insert_into_item'         => 'Insert into project',
                'uploaded_to_this_item'    => 'Uploaded to this project',
                'filter_items_list'        => 'Filter projects list',
                'filter_by_date'           => 'Filter projects by date',
                'items_list_navigation'    => 'Projects list navigation',
                'items_list'               => 'Projects list',
                'item_published'           => 'Project published.',
                'item_published_privately' => 'Project published privately.',
                'item_reverted_to_draft'   => 'Project reverted to draft.',
                'item_scheduled'           => 'Project scheduled.',
                'item_updated'             => 'Project updated.',
                'item_link'                => 'Project Link',
                'item_link_description'    => 'A link to a project.',
            ],
            'public'             => true,
            'publicly_queryable' => true,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-portfolio',
            'supports'           => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'taxonomies'         => ['project-type'],
            'has_archive'        => 'projects',
            'rewrite'            => ['feeds' => false],
            'delete_with_user'   => false,
        ]);

        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'    => 'group_68293d3e6f4c1',
            'title'  => 'Project: Details',
            'fields' => [
                [
                    'key'               => 'field_68293d3e6f4c2',
                    'label'             => 'Client',
                    'name'              => 'pj_client',
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
                    'key'               => 'field_68293d3e6f4c3',
                    'label'             => 'Project URL',
                    'name'              => 'pj_url',
                    'type'              => 'url',
                    'instructions'      => '',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => '',
                ],
                [
                    'key'               => 'field_68293d3e6f4c4',
                    'label'             => 'Year',
                    'name'              => 'pj_year',
                    'type'              => 'number',
                    'instructions'      => '',
                    'required'          => 0,
                    'conditional_logic' => 0,
                    'wrapper'           => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value'     => '',
                    'allow_in_bindings' => 1,
                    'placeholder'       => '',
                    'min'               => 1900,
                    'max'               => 2100,
                    'step'              => 1,
                ],
            ],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'project']],
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
            'key'    => 'group_68293d3e6f4c5',
            'title'  => 'Relationship: Projects',
            'fields' => [
                [
                    'key'               => 'field_68293d3e6f4c6',
                    'label'             => 'Linked Projects',
                    'name'              => 'linked_projects',
                    'type'              => 'relationship',
                    'post_type'         => ['project'],
                    'filters'           => ['search', 'taxonomy'],
                    'return_format'     => 'object',
                    'bidirectional'     => 0,
                    'allow_in_bindings' => 0,
                ],
            ],
            'location'      => $location,
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'Projects',
        ]);
    },
];

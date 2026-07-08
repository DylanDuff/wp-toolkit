<?php

namespace DDWPTweaks\Tweaks;

const AI_CONTENT_ABILITIES_STATUSES = ['draft', 'pending', 'private', 'publish'];

// The pool of post types/taxonomies an admin can choose to expose, and that
// list-post-types/list-taxonomies report on. show_ui mirrors what the admin
// would actually see and manage in wp-admin — internal types (revisions,
// nav menu items, etc.) are excluded.
function ai_content_site_post_types(): array
{
    return get_post_types(['show_ui' => true], 'objects');
}

function ai_content_site_taxonomies(): array
{
    return get_taxonomies(['show_ui' => true], 'objects');
}

function ai_content_allowed_post_types(array $settings): array
{
    $selected = json_decode($settings['post_types'] ?? '[]', true);
    if (!is_array($selected)) {
        $selected = [];
    }

    return array_values(array_intersect($selected, array_keys(ai_content_site_post_types())));
}

function ai_content_allowed_taxonomies(array $post_types): array
{
    $taxonomies = [];
    foreach ($post_types as $post_type) {
        $taxonomies = array_merge($taxonomies, get_object_taxonomies($post_type, 'names'));
    }

    return array_values(array_unique($taxonomies));
}

function ai_content_find_post($id, $slug, $post_type)
{
    if (!empty($id)) {
        $post = get_post((int) $id);
        return $post instanceof \WP_Post ? $post : null;
    }

    if (!empty($slug) && !empty($post_type)) {
        $posts = get_posts([
            'name'           => sanitize_title($slug),
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ]);
        return $posts[0] ?? null;
    }

    return null;
}

function ai_content_post_payload(\WP_Post $post): array
{
    $meta = function_exists('get_fields') ? get_fields($post->ID) : [];

    $terms = [];
    foreach (get_object_taxonomies($post->post_type, 'names') as $taxonomy) {
        $terms[$taxonomy] = array_map(
            fn($term) => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug],
            wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'all'])
        );
    }

    return [
        'id'        => $post->ID,
        'post_type' => $post->post_type,
        'status'    => $post->post_status,
        'title'     => get_the_title($post),
        'content'   => $post->post_content,
        'excerpt'   => get_the_excerpt($post),
        'link'      => get_permalink($post),
        'date'      => $post->post_date,
        'modified'  => $post->post_modified,
        'meta'      => is_array($meta) ? $meta : [],
        'terms'     => $terms,
    ];
}

function ai_content_save_meta(int $post_id, array $meta): void
{
    $has_acf = function_exists('update_field') && function_exists('acf_get_field');

    foreach ($meta as $key => $value) {
        $key = sanitize_key($key);
        if ($key === '') {
            continue;
        }

        if ($has_acf && acf_get_field($key)) {
            update_field($key, $value, $post_id);
            continue;
        }

        update_post_meta($post_id, $key, is_string($value) ? sanitize_text_field($value) : $value);
    }
}

function ai_content_get_meta_value(int $post_id, string $key)
{
    $has_acf = function_exists('get_field') && function_exists('acf_get_field');

    if ($has_acf && acf_get_field($key)) {
        return get_field($key, $post_id);
    }

    return get_post_meta($post_id, $key, true);
}

function ai_content_save_terms(int $post_id, array $allowed_taxonomies, array $terms, bool $append): void
{
    foreach ($terms as $taxonomy => $slugs) {
        if (!in_array($taxonomy, $allowed_taxonomies, true) || !is_array($slugs)) {
            continue;
        }

        $term_ids = [];
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', sanitize_title($slug), $taxonomy);
            if ($term) {
                $term_ids[] = $term->term_id;
            }
        }

        wp_set_post_terms($post_id, $term_ids, $taxonomy, $append);
    }
}

// Abilities are registered at file-load time (before wp_abilities_api_init
// fires) so the option check below happens on every request; the actual
// wp_register_ability() calls only run once the registry is first built.
if (get_option('ddwpt_ai_content_abilities_enabled')) {
    add_action('wp_abilities_api_categories_init', static function () {
        wp_register_ability_category('wp-toolkit-content', [
            'label'       => 'WP Toolkit — Content',
            'description' => 'Abilities for reading and managing WordPress content exposed by the WP Toolkit plugin.',
        ]);
    });

    add_action('wp_abilities_api_init', static function () {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $settings          = [
            'post_types' => get_option('ddwpt_ai_content_abilities_post_types', '["post","page"]'),
        ];
        $allow_write        = (bool) get_option('ddwpt_ai_content_abilities_allow_write');
        $allowed_post_types = ai_content_allowed_post_types($settings);
        $allowed_taxonomies = ai_content_allowed_taxonomies($allowed_post_types);

        $read_capability  = 'edit_posts';
        $write_capability = 'publish_posts';

        wp_register_ability('wp-toolkit/list-post-types', [
            'label'               => 'List Post Types',
            'description'         => 'List every post type registered on this site (labels, whether it\'s hierarchical, its taxonomies), and whether AI agents currently have read and/or write access to it. Use this for full site context even for content you can\'t touch.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_types' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'          => ['type' => 'string'],
                                'label'         => ['type' => 'string'],
                                'public'        => ['type' => 'boolean'],
                                'hierarchical'  => ['type' => 'boolean'],
                                'taxonomies'    => ['type' => 'array', 'items' => ['type' => 'string']],
                                'read_access'   => ['type' => 'boolean'],
                                'write_access'  => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => static function () use ($allowed_post_types, $allow_write) {
                $result = [];
                foreach (ai_content_site_post_types() as $slug => $obj) {
                    $is_exposed = in_array($slug, $allowed_post_types, true);
                    $result[]   = [
                        'slug'         => $slug,
                        'label'        => $obj->labels->singular_name,
                        'public'       => (bool) $obj->public,
                        'hierarchical' => (bool) $obj->hierarchical,
                        'taxonomies'   => array_values(get_object_taxonomies($slug, 'names')),
                        'read_access'  => $is_exposed,
                        'write_access' => $is_exposed && $allow_write,
                    ];
                }

                return ['post_types' => $result];
            },
            'permission_callback' => static function () use ($read_capability) {
                return current_user_can($read_capability);
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/list-taxonomies', [
            'label'               => 'List Taxonomies',
            'description'         => 'List every taxonomy registered on this site (labels, attached post types), and whether AI agents currently have write access to it (via get-or-create-term). Use this for full site context even for taxonomies you can\'t touch.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'taxonomies' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'          => ['type' => 'string'],
                                'label'         => ['type' => 'string'],
                                'public'        => ['type' => 'boolean'],
                                'hierarchical'  => ['type' => 'boolean'],
                                'post_types'    => ['type' => 'array', 'items' => ['type' => 'string']],
                                'exposed'       => ['type' => 'boolean'],
                                'write_access'  => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => static function () use ($allowed_taxonomies, $allow_write) {
                $result = [];
                foreach (ai_content_site_taxonomies() as $slug => $obj) {
                    $is_exposed = in_array($slug, $allowed_taxonomies, true);
                    $result[]   = [
                        'slug'         => $slug,
                        'label'        => $obj->labels->singular_name,
                        'public'       => (bool) $obj->public,
                        'hierarchical' => (bool) $obj->hierarchical,
                        'post_types'   => array_values($obj->object_type),
                        'exposed'      => $is_exposed,
                        'write_access' => $is_exposed && $allow_write,
                    ];
                }

                return ['taxonomies' => $result];
            },
            'permission_callback' => static function () use ($read_capability) {
                return current_user_can($read_capability);
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/get-post', [
            'label'               => 'Get Post',
            'description'         => 'Fetch a single post by ID, or by slug + post type, including ACF meta and assigned taxonomy terms.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [
                    'id'        => ['type' => 'integer', 'minimum' => 1, 'description' => 'Post ID. Takes precedence over slug/post_type if provided.'],
                    'slug'      => ['type' => 'string', 'description' => 'Post slug. Requires post_type.'],
                    'post_type' => ['type' => 'string', 'enum' => $allowed_post_types],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer'],
                    'post_type' => ['type' => 'string'],
                    'status'    => ['type' => 'string'],
                    'title'     => ['type' => 'string'],
                    'content'   => ['type' => 'string'],
                    'excerpt'   => ['type' => 'string'],
                    'link'      => ['type' => 'string'],
                    'date'      => ['type' => 'string'],
                    'modified'  => ['type' => 'string'],
                    'meta'      => ['type' => 'object'],
                    'terms'     => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_post_types) {
                $post = ai_content_find_post($input['id'] ?? null, $input['slug'] ?? null, $input['post_type'] ?? null);
                if (!$post) {
                    return new \WP_Error('ddwpt_post_not_found', 'No matching post found. Provide either "id", or "slug" together with "post_type".');
                }
                if (!in_array($post->post_type, $allowed_post_types, true)) {
                    return new \WP_Error('ddwpt_post_type_not_allowed', sprintf('Post type "%s" is not exposed to AI agents.', $post->post_type));
                }

                return ai_content_post_payload($post);
            },
            'permission_callback' => static function () use ($read_capability) {
                return current_user_can($read_capability);
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/list-posts', [
            'label'               => 'List Posts',
            'description'         => 'Query posts by post type, taxonomy term, meta key/value, or status.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [
                    'post_type' => array_filter([
                        'type'    => 'string',
                        'enum'    => $allowed_post_types,
                        'default' => $allowed_post_types[0] ?? null,
                    ], fn($v) => $v !== null),
                    'status'    => ['type' => 'string', 'enum' => array_merge(AI_CONTENT_ABILITIES_STATUSES, ['any']), 'default' => 'publish'],
                    'search'    => ['type' => 'string'],
                    'taxonomy'  => ['type' => 'string', 'enum' => $allowed_taxonomies],
                    'term'      => ['type' => 'string', 'description' => 'Term slug. Requires taxonomy.'],
                    'meta_key'  => ['type' => 'string'],
                    'meta_value' => ['type' => 'string', 'description' => 'Requires meta_key.'],
                    'per_page'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
                    'page'      => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'posts'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'      => ['type' => 'integer'],
                                'title'   => ['type' => 'string'],
                                'slug'    => ['type' => 'string'],
                                'status'  => ['type' => 'string'],
                                'excerpt' => ['type' => 'string'],
                                'date'    => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_post_types, $allowed_taxonomies) {
                $post_type = $input['post_type'] ?? ($allowed_post_types[0] ?? '');
                if (!in_array($post_type, $allowed_post_types, true)) {
                    return new \WP_Error('ddwpt_post_type_not_allowed', sprintf('Post type "%s" is not exposed to AI agents.', $post_type));
                }

                $args = [
                    'post_type'      => $post_type,
                    'post_status'    => $input['status'] ?? 'publish',
                    'posts_per_page' => min(50, max(1, (int) ($input['per_page'] ?? 10))),
                    'paged'          => max(1, (int) ($input['page'] ?? 1)),
                ];

                if (!empty($input['search'])) {
                    $args['s'] = sanitize_text_field($input['search']);
                }

                if (!empty($input['taxonomy'])) {
                    if (!in_array($input['taxonomy'], $allowed_taxonomies, true)) {
                        return new \WP_Error('ddwpt_taxonomy_not_allowed', sprintf('Taxonomy "%s" is not exposed to AI agents.', $input['taxonomy']));
                    }
                    if (!empty($input['term'])) {
                        $args['tax_query'] = [[
                            'taxonomy' => $input['taxonomy'],
                            'field'    => 'slug',
                            'terms'    => sanitize_title($input['term']),
                        ]];
                    }
                }

                if (!empty($input['meta_key'])) {
                    $args['meta_key'] = sanitize_key($input['meta_key']);
                    if (isset($input['meta_value'])) {
                        $args['meta_value'] = sanitize_text_field($input['meta_value']);
                    }
                }

                $query = new \WP_Query($args);

                return [
                    'posts'       => array_map(
                        fn($post) => [
                            'id'      => $post->ID,
                            'title'   => get_the_title($post),
                            'slug'    => $post->post_name,
                            'status'  => $post->post_status,
                            'excerpt' => get_the_excerpt($post),
                            'date'    => $post->post_date,
                        ],
                        $query->posts
                    ),
                    'total'       => (int) $query->found_posts,
                    'total_pages' => (int) $query->max_num_pages,
                ];
            },
            'permission_callback' => static function () use ($read_capability) {
                return current_user_can($read_capability);
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/list-terms', [
            'label'               => 'List Terms',
            'description'         => 'List or search existing terms in a taxonomy exposed to AI agents. Use before get-or-create-term to check what already exists.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'taxonomy' => ['type' => 'string', 'enum' => $allowed_taxonomies],
                    'search'   => ['type' => 'string'],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    'page'     => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ],
                'required'             => ['taxonomy'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'terms'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'    => ['type' => 'integer'],
                                'name'  => ['type' => 'string'],
                                'slug'  => ['type' => 'string'],
                                'count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_taxonomies) {
                $taxonomy = $input['taxonomy'] ?? '';
                if (!in_array($taxonomy, $allowed_taxonomies, true)) {
                    return new \WP_Error('ddwpt_taxonomy_not_allowed', sprintf('Taxonomy "%s" is not exposed to AI agents.', $taxonomy));
                }

                $per_page = min(100, max(1, (int) ($input['per_page'] ?? 20)));
                $page     = max(1, (int) ($input['page'] ?? 1));

                $args = [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'number'     => $per_page,
                    'offset'     => ($page - 1) * $per_page,
                ];
                if (!empty($input['search'])) {
                    $args['search'] = sanitize_text_field($input['search']);
                }

                $terms = get_terms($args);
                if (is_wp_error($terms)) {
                    return $terms;
                }

                $count_args              = $args;
                $count_args['number']    = 0;
                $count_args['offset']    = 0;
                $count_args['fields']    = 'count';
                $total                   = (int) get_terms($count_args);

                return [
                    'terms'       => array_map(
                        fn($term) => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count],
                        $terms
                    ),
                    'total'       => $total,
                    'total_pages' => (int) ceil($total / $per_page),
                ];
            },
            'permission_callback' => static function () use ($read_capability) {
                return current_user_can($read_capability);
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/get-option-field', [
            'label'               => 'Get Meta Field',
            'description'         => 'Read a single meta field from a post (via "id"), or from the ACF site options page if "id" is omitted. Reads through ACF when the field is registered there, otherwise falls back to plain post meta.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'field_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                    'id'        => ['type' => 'integer', 'minimum' => 1, 'description' => 'Post ID. Omit to read from the ACF site options page instead.'],
                ],
                'required'             => ['field_key'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'field_key' => ['type' => 'string'],
                    'id'        => ['type' => ['integer', 'null']],
                    'value'     => ['type' => ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null']],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_post_types) {
                $field_key = $input['field_key'];

                if (!empty($input['id'])) {
                    $post = get_post((int) $input['id']);
                    if (!$post instanceof \WP_Post || !in_array($post->post_type, $allowed_post_types, true)) {
                        return new \WP_Error('ddwpt_post_not_found', 'No matching post found among the post types exposed to AI agents.');
                    }

                    return [
                        'field_key' => $field_key,
                        'id'        => $post->ID,
                        'value'     => ai_content_get_meta_value($post->ID, $field_key),
                    ];
                }

                if (!function_exists('get_field')) {
                    return new \WP_Error('ddwpt_acf_required', 'Reading the site options page requires ACF to be active. Provide "id" to read a post\'s meta instead.');
                }

                return [
                    'field_key' => $field_key,
                    'id'        => null,
                    'value'     => get_field($field_key, 'option'),
                ];
            },
            'permission_callback' => static function () use ($read_capability) {
                return current_user_can($read_capability);
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        if (!$allow_write) {
            return;
        }

        wp_register_ability('wp-toolkit/create-post', [
            'label'               => 'Create Post',
            'description'         => 'Create a new post of an allowed post type, with optional ACF meta and taxonomy terms. Terms must already exist — use get-or-create-term first.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => ['type' => 'string', 'enum' => $allowed_post_types],
                    'title'     => ['type' => 'string', 'minLength' => 1],
                    'content'   => ['type' => 'string', 'default' => ''],
                    'status'    => ['type' => 'string', 'enum' => AI_CONTENT_ABILITIES_STATUSES, 'default' => 'draft'],
                    'meta'      => ['type' => 'object', 'description' => 'ACF field name (or post meta key) => value pairs.'],
                    'terms'     => [
                        'type'                 => 'object',
                        'description'          => 'Taxonomy slug => array of existing term slugs.',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'required'             => ['post_type', 'title'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'id'        => ['type' => 'integer'],
                    'post_type' => ['type' => 'string'],
                    'title'     => ['type' => 'string'],
                    'status'    => ['type' => 'string'],
                    'link'      => ['type' => 'string'],
                    'message'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_post_types, $allowed_taxonomies) {
                $post_type = $input['post_type'];
                if (!in_array($post_type, $allowed_post_types, true)) {
                    return new \WP_Error('ddwpt_post_type_not_allowed', sprintf('Post type "%s" is not exposed to AI agents.', $post_type));
                }

                $status  = in_array($input['status'] ?? 'draft', AI_CONTENT_ABILITIES_STATUSES, true) ? $input['status'] : 'draft';
                $post_id = wp_insert_post([
                    'post_type'    => $post_type,
                    'post_title'   => sanitize_text_field($input['title']),
                    'post_content' => wp_kses_post($input['content'] ?? ''),
                    'post_status'  => $status,
                ], true);

                if (is_wp_error($post_id)) {
                    return $post_id;
                }

                if (!empty($input['meta']) && is_array($input['meta'])) {
                    ai_content_save_meta($post_id, $input['meta']);
                }
                if (!empty($input['terms']) && is_array($input['terms'])) {
                    ai_content_save_terms($post_id, $allowed_taxonomies, $input['terms'], true);
                }

                return [
                    'success'   => true,
                    'id'        => $post_id,
                    'post_type' => $post_type,
                    'title'     => get_the_title($post_id),
                    'status'    => get_post_status($post_id),
                    'link'      => (string) get_permalink($post_id),
                    'message'   => sprintf('Created "%s" (ID %d).', get_the_title($post_id), $post_id),
                ];
            },
            'permission_callback' => static function () use ($write_capability) {
                return current_user_can($write_capability);
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/update-post', [
            'label'               => 'Update Post',
            'description'         => 'Update an existing post\'s title, content, status, ACF meta, or taxonomy terms. Terms must already exist — use get-or-create-term first.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'id'            => ['type' => 'integer', 'minimum' => 1],
                    'title'         => ['type' => 'string', 'minLength' => 1],
                    'content'       => ['type' => 'string'],
                    'status'        => ['type' => 'string', 'enum' => AI_CONTENT_ABILITIES_STATUSES],
                    'meta'          => ['type' => 'object', 'description' => 'ACF field name (or post meta key) => value pairs.'],
                    'terms'         => [
                        'type'                 => 'object',
                        'description'          => 'Taxonomy slug => array of existing term slugs.',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'append_terms'  => ['type' => 'boolean', 'default' => true, 'description' => 'If true, add to existing terms; if false, replace them.'],
                ],
                'required'             => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'id'        => ['type' => 'integer'],
                    'post_type' => ['type' => 'string'],
                    'title'     => ['type' => 'string'],
                    'status'    => ['type' => 'string'],
                    'link'      => ['type' => 'string'],
                    'message'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_post_types, $allowed_taxonomies) {
                $post = get_post((int) $input['id']);
                if (!$post instanceof \WP_Post || !in_array($post->post_type, $allowed_post_types, true)) {
                    return new \WP_Error('ddwpt_post_not_found', 'No matching post found among the post types exposed to AI agents.');
                }

                $postarr = ['ID' => $post->ID];
                if (isset($input['title'])) {
                    $postarr['post_title'] = sanitize_text_field($input['title']);
                }
                if (isset($input['content'])) {
                    $postarr['post_content'] = wp_kses_post($input['content']);
                }
                if (isset($input['status']) && in_array($input['status'], AI_CONTENT_ABILITIES_STATUSES, true)) {
                    $postarr['post_status'] = $input['status'];
                }

                if (count($postarr) > 1) {
                    $result = wp_update_post($postarr, true);
                    if (is_wp_error($result)) {
                        return $result;
                    }
                }

                if (!empty($input['meta']) && is_array($input['meta'])) {
                    ai_content_save_meta($post->ID, $input['meta']);
                }
                if (!empty($input['terms']) && is_array($input['terms'])) {
                    ai_content_save_terms($post->ID, $allowed_taxonomies, $input['terms'], $input['append_terms'] ?? true);
                }

                return [
                    'success'   => true,
                    'id'        => $post->ID,
                    'post_type' => $post->post_type,
                    'title'     => get_the_title($post->ID),
                    'status'    => get_post_status($post->ID),
                    'link'      => (string) get_permalink($post->ID),
                    'message'   => sprintf('Updated "%s" (ID %d).', get_the_title($post->ID), $post->ID),
                ];
            },
            'permission_callback' => static function () use ($write_capability) {
                return current_user_can($write_capability);
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/get-or-create-term', [
            'label'               => 'Get or Create Term',
            'description'         => 'Look up a taxonomy term by slug or name; create it if missing. Idempotent — safe to call before assigning terms to a post.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'taxonomy' => ['type' => 'string', 'enum' => $allowed_taxonomies],
                    'name'     => ['type' => 'string', 'minLength' => 1],
                    'slug'     => ['type' => 'string', 'description' => 'Defaults to a slugified version of "name" if omitted.'],
                ],
                'required'             => ['taxonomy', 'name'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'       => ['type' => 'integer'],
                    'taxonomy' => ['type' => 'string'],
                    'name'     => ['type' => 'string'],
                    'slug'     => ['type' => 'string'],
                    'created'  => ['type' => 'boolean'],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_taxonomies) {
                $taxonomy = $input['taxonomy'];
                if (!in_array($taxonomy, $allowed_taxonomies, true)) {
                    return new \WP_Error('ddwpt_taxonomy_not_allowed', sprintf('Taxonomy "%s" is not exposed to AI agents.', $taxonomy));
                }

                $name = sanitize_text_field($input['name']);
                $slug = !empty($input['slug']) ? sanitize_title($input['slug']) : sanitize_title($name);

                $term = get_term_by('slug', $slug, $taxonomy) ?: get_term_by('name', $name, $taxonomy);
                if ($term) {
                    return ['id' => $term->term_id, 'taxonomy' => $taxonomy, 'name' => $term->name, 'slug' => $term->slug, 'created' => false];
                }

                $result = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
                if (is_wp_error($result)) {
                    return $result;
                }

                return ['id' => $result['term_id'], 'taxonomy' => $taxonomy, 'name' => $name, 'slug' => $slug, 'created' => true];
            },
            'permission_callback' => static function () use ($write_capability) {
                return current_user_can($write_capability);
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/upload-media', [
            'label'               => 'Upload Media',
            'description'         => 'Sideload an image from a URL, or upload base64-encoded file data, into the media library.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [
                    'url'      => ['type' => 'string', 'format' => 'uri', 'description' => 'Provide either "url" or "base64", not both.'],
                    'base64'   => ['type' => 'string'],
                    'filename' => ['type' => 'string', 'description' => 'Required when using "base64".'],
                    'title'    => ['type' => 'string'],
                    'alt_text' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer'],
                    'url'       => ['type' => 'string'],
                    'mime_type' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => static function ($input) {
                $has_url  = !empty($input['url']);
                $has_b64  = !empty($input['base64']);
                if ($has_url === $has_b64) {
                    return new \WP_Error('ddwpt_media_input_invalid', 'Provide exactly one of "url" or "base64".');
                }

                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $title = !empty($input['title']) ? sanitize_text_field($input['title']) : null;

                if ($has_url) {
                    $attachment_id = media_sideload_image(esc_url_raw($input['url']), 0, $title, 'id');
                } else {
                    if (empty($input['filename'])) {
                        return new \WP_Error('ddwpt_media_filename_required', 'A "filename" is required when uploading base64 data.');
                    }

                    $decoded = base64_decode($input['base64'], true);
                    if ($decoded === false) {
                        return new \WP_Error('ddwpt_media_invalid_base64', 'Could not decode base64 data.');
                    }

                    $upload = wp_upload_bits(sanitize_file_name($input['filename']), null, $decoded);
                    if (!empty($upload['error'])) {
                        return new \WP_Error('ddwpt_media_upload_failed', $upload['error']);
                    }

                    $filetype      = wp_check_filetype($upload['file']);
                    $attachment_id = wp_insert_attachment([
                        'post_mime_type' => $filetype['type'],
                        'post_title'     => $title ?? sanitize_file_name($input['filename']),
                        'post_status'    => 'inherit',
                    ], $upload['file']);

                    if (!is_wp_error($attachment_id)) {
                        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
                    }
                }

                if (is_wp_error($attachment_id)) {
                    return $attachment_id;
                }

                if (!empty($input['alt_text'])) {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($input['alt_text']));
                }

                return [
                    'id'        => $attachment_id,
                    'url'       => wp_get_attachment_url($attachment_id),
                    'mime_type' => get_post_mime_type($attachment_id),
                ];
            },
            'permission_callback' => static function () use ($write_capability) {
                return current_user_can($write_capability) && current_user_can('upload_files');
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/update-option-field', [
            'label'               => 'Update Meta Field',
            'description'         => 'Write a single meta field on a post (via "id"), or on the ACF site options page if "id" is omitted. Writes through ACF when the field is registered there, otherwise falls back to plain post meta.',
            'category'            => 'wp-toolkit-content',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'field_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                    'value'     => ['type' => ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null']],
                    'id'        => ['type' => 'integer', 'minimum' => 1, 'description' => 'Post ID. Omit to write to the ACF site options page instead.'],
                ],
                'required'             => ['field_key', 'value'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'field_key' => ['type' => 'string'],
                    'id'        => ['type' => ['integer', 'null']],
                    'value'     => ['type' => ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null']],
                ],
            ],
            'execute_callback'    => static function ($input) use ($allowed_post_types) {
                $field_key = $input['field_key'];

                if (!empty($input['id'])) {
                    $post = get_post((int) $input['id']);
                    if (!$post instanceof \WP_Post || !in_array($post->post_type, $allowed_post_types, true)) {
                        return new \WP_Error('ddwpt_post_not_found', 'No matching post found among the post types exposed to AI agents.');
                    }

                    ai_content_save_meta($post->ID, [$field_key => $input['value']]);

                    return [
                        'success'   => true,
                        'field_key' => $field_key,
                        'id'        => $post->ID,
                        'value'     => ai_content_get_meta_value($post->ID, $field_key),
                    ];
                }

                if (!function_exists('update_field')) {
                    return new \WP_Error('ddwpt_acf_required', 'Writing to the site options page requires ACF to be active. Provide "id" to update a post\'s meta instead.');
                }

                update_field($field_key, $input['value'], 'option');

                return [
                    'success'   => true,
                    'field_key' => $field_key,
                    'id'        => null,
                    'value'     => get_field($field_key, 'option'),
                ];
            },
            'permission_callback' => static function ($input) use ($write_capability, $allowed_post_types) {
                if (!empty($input['id'])) {
                    $post = get_post((int) $input['id']);
                    return $post instanceof \WP_Post
                        && in_array($post->post_type, $allowed_post_types, true)
                        && current_user_can($write_capability);
                }

                return current_user_can('manage_options');
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        if (defined('SEOPRESS_VERSION')) {
            wp_register_ability('wp-toolkit/set-seo-meta', [
                'label'               => 'Set SEO Meta',
                'description'         => 'Set the SEOPress title, meta description, and/or Open Graph image for a post.',
                'category'            => 'wp-toolkit-content',
                'input_schema'        => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id'          => ['type' => 'integer', 'minimum' => 1],
                        'title'       => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'og_image'    => ['type' => 'string', 'format' => 'uri'],
                    ],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'success'     => ['type' => 'boolean'],
                        'id'          => ['type' => 'integer'],
                        'title'       => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'og_image'    => ['type' => 'string'],
                        'message'     => ['type' => 'string'],
                    ],
                ],
                'execute_callback'    => static function ($input) use ($allowed_post_types) {
                    $post = get_post((int) $input['id']);
                    if (!$post instanceof \WP_Post || !in_array($post->post_type, $allowed_post_types, true)) {
                        return new \WP_Error('ddwpt_post_not_found', 'No matching post found among the post types exposed to AI agents.');
                    }

                    if (!isset($input['title']) && !isset($input['description']) && !isset($input['og_image'])) {
                        return new \WP_Error('ddwpt_seo_meta_empty', 'Provide at least one of "title", "description", or "og_image".');
                    }

                    if (isset($input['title'])) {
                        update_post_meta($post->ID, '_seopress_titles_title', sanitize_text_field($input['title']));
                    }
                    if (isset($input['description'])) {
                        update_post_meta($post->ID, '_seopress_titles_desc', sanitize_textarea_field($input['description']));
                    }
                    if (isset($input['og_image'])) {
                        update_post_meta($post->ID, '_seopress_social_fb_img', esc_url_raw($input['og_image']));
                    }

                    return [
                        'success'     => true,
                        'id'          => $post->ID,
                        'title'       => (string) get_post_meta($post->ID, '_seopress_titles_title', true),
                        'description' => (string) get_post_meta($post->ID, '_seopress_titles_desc', true),
                        'og_image'    => (string) get_post_meta($post->ID, '_seopress_social_fb_img', true),
                        'message'     => sprintf('Updated SEO meta for "%s" (ID %d).', get_the_title($post->ID), $post->ID),
                    ];
                },
                'permission_callback' => static function () use ($write_capability) {
                    return current_user_can($write_capability);
                },
                'meta'                => [
                    'annotations'  => ['destructive' => false, 'idempotent' => true],
                    'show_in_rest' => true,
                    'mcp'          => ['public' => true],
                ],
            ]);
        }
    });
}

return [
    'id'    => 'ddwpt_ai_content_abilities',
    'label' => 'AI Content Abilities',
    'tab'   => 'ai',
    'group' => 'abilities',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Registers read abilities (<code>get-post</code>, <code>list-posts</code>, <code>list-post-types</code>, <code>list-taxonomies</code>, <code>list-terms</code>, <code>get-option-field</code>) via the WordPress Abilities API. <code>get-option-field</code> reads a post\'s meta (ACF or plain) when given an <code>id</code>, or the ACF site options page when omitted. <code>list-post-types</code> and <code>list-taxonomies</code> report on every post type/taxonomy on the site regardless of the whitelist below, so agents always have full context.',
        ],
        [
            'id'          => 'allow_write',
            'type'        => 'checkbox',
            'label'       => 'Allow content creation & updates',
            'description' => 'Also registers write abilities: <code>create-post</code>, <code>update-post</code>, <code>get-or-create-term</code>, <code>upload-media</code>, <code>update-option-field</code> (a post\'s meta via <code>id</code>, or the ACF site options page if omitted), and <code>set-seo-meta</code> (if SEOPress is active). No delete ability is provided.',
        ],
        [
            'id'      => 'post_types',
            'type'    => 'checkboxes',
            'label'   => 'Expose post types',
            'description' => 'Any post type registered on this site can be exposed to AI agents for reading and (if allowed above) writing. Nothing is exposed until checked here, regardless of what <code>list-post-types</code> reports.',
            'options' => function () {
                $options = [];
                foreach (ai_content_site_post_types() as $slug => $obj) {
                    $options[$slug] = $obj->labels->singular_name;
                }
                return $options;
            },
            'default' => '["post","page"]',
        ],
    ],

    'callback' => static function ($settings) {
        // Ability registration is handled at file-load time above.
    },
];

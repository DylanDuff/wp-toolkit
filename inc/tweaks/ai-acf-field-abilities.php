<?php

namespace DDWPTweaks\Tweaks;

// Strips internal/noisy keys and recursively cleans sub_fields (repeater/group/
// flexible_content) so the payload matches what acf_update_field() actually accepts back.
function ai_acf_clean_field(array $field): array
{
    unset($field['_valid'], $field['prefix'], $field['id'], $field['class'], $field['aria-label']);

    if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
        $field['sub_fields'] = array_map(__NAMESPACE__ . '\\ai_acf_clean_field', $field['sub_fields']);
    }

    return $field;
}

function ai_acf_clean_field_group(array $group): array
{
    unset($group['_valid']);
    $group['fields'] = array_map(__NAMESPACE__ . '\\ai_acf_clean_field', acf_get_fields($group));

    return $group;
}

// PHP-registered local groups/fields (and unsynced ACF Local JSON) resolve via
// acf_get_field_group()/acf_get_field() with ID === 0 — they aren't backed by a DB
// post. Writing one back through acf_update_field_group()/acf_update_field() would
// take the insert branch and create a duplicate DB record instead of updating the
// original, so both read and write paths reject these before doing anything.
function ai_acf_is_local_record(array $record): bool
{
    return empty($record['ID']);
}

// ACF field names are meta keys — lowercase, alnum/underscore only, conventionally
// underscore-separated. sanitize_key() strips invalid characters rather than
// replacing them, so "Event Date" would silently become "eventdate"; this collapses
// whitespace/punctuation into underscores instead, matching ACF's own admin behavior.
function ai_acf_sanitize_field_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9_]+/', '_', $name);

    return trim($name, '_');
}

// Walks a field's parent chain (which may pass through other fields for nested
// repeater/group/flexible_content subfields) up to the owning field group, since
// $field['parent'] is only the immediate parent post, not necessarily the group.
function ai_acf_field_belongs_to_group(array $field, int $group_id): bool
{
    $parent_id = (int) $field['parent'];

    for ($i = 0; $i < 10 && $parent_id; $i++) {
        $post = get_post($parent_id);
        if (!$post) {
            return false;
        }

        if ($post->post_type === 'acf-field-group') {
            return (int) $post->ID === $group_id;
        }

        if ($post->post_type !== 'acf-field') {
            return false;
        }

        $parent_field = acf_get_field($post->ID);
        $parent_id    = $parent_field ? (int) $parent_field['parent'] : 0;
    }

    return false;
}

// Runs at plugins_loaded when the file is required — before wp_abilities_api_init fires.
if (get_option('ddwpt_ai_acf_field_abilities_enabled')) {
    add_action('wp_abilities_api_categories_init', static function () {
        wp_register_ability_category('wp-toolkit-acf', [
            'label'       => 'WP Toolkit — ACF Field Groups',
            'description' => 'Abilities for reading and updating existing ACF field groups, exposed by the WP Toolkit plugin.',
        ]);
    });

    add_action('wp_abilities_api_init', static function () {
        if (!function_exists('wp_register_ability') || !function_exists('acf_get_field_groups')) {
            return;
        }

        $allow_write = (bool) get_option('ddwpt_ai_acf_field_abilities_allow_write');

        wp_register_ability('wp-toolkit/list-acf-field-groups', [
            'label'               => 'List ACF Field Groups',
            'description'         => 'List every ACF field group stored in the database (not PHP-registered local groups), with id, key, title, active status, location rules, and field count.',
            'category'            => 'wp-toolkit-acf',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [
                    'active_only' => ['type' => 'boolean', 'default' => false],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'field_groups' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
            ],
            'execute_callback'    => static function ($input) {
                $active_only = !empty($input['active_only']);
                $groups      = acf_get_field_groups();

                $result = [];
                foreach ($groups as $group) {
                    if (ai_acf_is_local_record($group)) {
                        continue;
                    }
                    if ($active_only && empty($group['active'])) {
                        continue;
                    }
                    $result[] = [
                        'id'          => $group['ID'],
                        'key'         => $group['key'],
                        'title'       => $group['title'],
                        'active'      => (bool) $group['active'],
                        'location'    => $group['location'],
                        'field_count' => count(acf_get_fields($group)),
                    ];
                }

                return ['field_groups' => $result];
            },
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/get-acf-field-group', [
            'label'               => 'Get ACF Field Group',
            'description'         => 'Get an ACF field group\'s full settings and its complete field tree (including nested repeater/group/flexible_content sub-fields), by id or key. Call list-acf-field-groups first to find the id/key — the group\'s title alone cannot be used to look it up.',
            'category'            => 'wp-toolkit-acf',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'group' => ['type' => ['string', 'integer'], 'description' => 'The field group\'s numeric ID or key (e.g. "group_abc123").'],
                ],
                'required'             => ['group'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'field_group' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => static function ($input) {
                $group = acf_get_field_group($input['group']);
                if (!$group || ai_acf_is_local_record($group)) {
                    return new \WP_Error('ddwpt_acf_group_not_found', 'No database-stored ACF field group found for the given id/key.');
                }

                return ['field_group' => ai_acf_clean_field_group($group)];
            },
            'permission_callback' => static function () {
                return current_user_can('manage_options');
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

        wp_register_ability('wp-toolkit/update-acf-field-group', [
            'label'               => 'Update ACF Field Group',
            'description'         => 'Update settings on an existing ACF field group (title, location rules, active status, description, etc.) by id or key. Only the properties you provide are changed — everything else on the group is preserved. Use active=false to deactivate a group instead of deleting it; WP Toolkit does not expose field-group deletion to AI agents. Call get-acf-field-group or list-acf-field-groups first to find the group\'s id/key.',
            'category'            => 'wp-toolkit-acf',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'group'                  => ['type' => ['string', 'integer'], 'description' => 'The field group\'s numeric ID or key — not its title.'],
                    'title'                  => ['type' => 'string'],
                    'active'                 => ['type' => 'boolean'],
                    'description'            => ['type' => 'string'],
                    'location'               => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'object']], 'description' => 'ACF location rules: an array of rule groups, each an array of {param, operator, value} rules. Rule groups are OR\'d together; rules within a group are AND\'d. Replaces the entire location array when provided.'],
                    'menu_order'             => ['type' => 'integer'],
                    'style'                  => ['type' => 'string', 'enum' => ['default', 'seamless']],
                    'position'               => ['type' => 'string', 'enum' => ['normal', 'side', 'acf_after_title']],
                    'label_placement'        => ['type' => 'string', 'enum' => ['top', 'left']],
                    'instruction_placement'  => ['type' => 'string', 'enum' => ['label', 'field']],
                    'hide_on_screen'         => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required'             => ['group'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'field_group' => ['type' => 'object'],
                    'message'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => static function ($input) {
                $group = acf_get_field_group($input['group']);
                if (!$group || ai_acf_is_local_record($group)) {
                    return new \WP_Error('ddwpt_acf_group_not_found', 'No database-stored ACF field group found for the given id/key.');
                }

                $patch = $input;
                unset($patch['group']);

                $updated = acf_update_field_group(array_merge($group, $patch));
                if (empty($updated['ID'])) {
                    return new \WP_Error('ddwpt_acf_group_save_failed', 'ACF failed to save the field group.');
                }

                return [
                    'success'     => true,
                    'field_group' => ai_acf_clean_field_group(acf_get_field_group($updated['ID'])),
                    'message'     => sprintf('Updated field group "%s".', $updated['title']),
                ];
            },
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/update-acf-field', [
            'label'               => 'Add or Update ACF Field',
            'description'         => 'Add a new field to an existing ACF field group, or edit an existing field, by key. Provide "key" to edit a field that already exists — only the properties you provide in "field" are changed, the rest of its settings are preserved. Omit "key" to create a new field ("field" must then include label, name, and type). To nest a new field inside a repeater/group/flexible_content field instead of at the top level of the group, set field.parent to that parent field\'s key — this only applies when creating; an existing field\'s parent cannot be changed via update (re-create it in the desired location instead). Field removal is not exposed to AI agents — use the ACF admin UI. Call get-acf-field-group first to see existing field keys, and get-acf-architecture-guide for this site\'s naming/type conventions.',
            'category'            => 'wp-toolkit-acf',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'group' => ['type' => ['string', 'integer'], 'description' => 'The field group\'s numeric ID or key (not its title) that this field belongs to, or should be added to.'],
                    'key'   => ['type' => 'string', 'description' => 'An existing field\'s key (e.g. "field_abc123") to edit it. Omit to create a new field.'],
                    'field' => [
                        'type'                 => 'object',
                        'description'          => 'Field settings to set. New fields require label, name, and type. Accepts any ACF field setting (instructions, required, default_value, choices, min, max, return_format, sub_fields, parent, wrapper, conditional_logic, etc.) — the applicable set depends on the field\'s type.',
                        'additionalProperties' => true,
                    ],
                ],
                'required'             => ['group', 'field'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'created' => ['type' => 'boolean'],
                    'field'   => ['type' => 'object'],
                    'message' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => static function ($input) {
                $group = acf_get_field_group($input['group']);
                if (!$group || ai_acf_is_local_record($group)) {
                    return new \WP_Error('ddwpt_acf_group_not_found', 'No database-stored ACF field group found for the given id/key.');
                }

                $patch = is_array($input['field']) ? $input['field'] : [];
                unset($patch['ID'], $patch['key']);

                $key      = $input['key'] ?? '';
                $creating = empty($key);

                if ($creating) {
                    if (empty($patch['label']) || empty($patch['name']) || empty($patch['type'])) {
                        return new \WP_Error('ddwpt_acf_field_incomplete', 'New fields require "label", "name", and "type" in "field".');
                    }
                    $field_data = array_merge(['key' => 'field_' . uniqid(), 'parent' => $group['ID']], $patch);
                } else {
                    $existing = acf_get_field($key);
                    if (!$existing || ai_acf_is_local_record($existing)) {
                        return new \WP_Error('ddwpt_acf_field_not_found', 'No database-stored ACF field found for the given key.');
                    }
                    if (!ai_acf_field_belongs_to_group($existing, (int) $group['ID'])) {
                        return new \WP_Error('ddwpt_acf_field_group_mismatch', 'That field does not belong to the given field group.');
                    }
                    // Reparenting isn't supported via update — the group-membership check above
                    // runs against the field's *current* parent, so honoring a caller-supplied
                    // "parent" here would let a patch silently move the field out of the group
                    // it was just verified to belong to.
                    unset($patch['parent']);
                    $field_data = array_merge($existing, $patch);
                }

                if (isset($field_data['name'])) {
                    $field_data['name'] = ai_acf_sanitize_field_name($field_data['name']);
                }

                $saved = acf_update_field($field_data);
                if (empty($saved['ID'])) {
                    return new \WP_Error('ddwpt_acf_field_save_failed', 'ACF failed to save the field.');
                }

                return [
                    'success' => true,
                    'created' => $creating,
                    'field'   => ai_acf_clean_field(acf_get_field($saved['key'])),
                    'message' => sprintf('%s field "%s".', $creating ? 'Created' : 'Updated', $saved['label']),
                ];
            },
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    });
}

return [
    'id'    => 'ddwpt_ai_acf_field_abilities',
    'label' => 'AI ACF Field Group Abilities',
    'tab'   => 'ai',
    'group' => 'abilities',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Registers read abilities (<code>list-acf-field-groups</code>, <code>get-acf-field-group</code>) via the WordPress Abilities API, covering database-stored ACF field groups. PHP-registered local field groups are not exposed — they aren\'t safely mutable at runtime.',
        ],
        [
            'id'          => 'allow_write',
            'type'        => 'checkbox',
            'label'       => 'Allow field group updates',
            'description' => 'Also registers write abilities: <code>update-acf-field-group</code> (settings, location rules, active status) and <code>update-acf-field</code> (add a new field, or edit an existing one, in an existing group). No field or field-group deletion ability is provided — deactivate a group instead via <code>update-acf-field-group</code>\'s <code>active</code> flag, or remove fields via the ACF admin UI.',
        ],
    ],

    'callback' => static function ($settings) {
        // Ability registration is handled at file-load time above.
    },
];

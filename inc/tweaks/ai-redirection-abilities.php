<?php

namespace DDWPTweaks\Tweaks;

// Only groups belonging to the WordPress module actually run through Redirection's
// PHP matching — Apache/Nginx-module groups are written to server config instead,
// so exposing them here would let an agent create a redirect that silently never fires.
function ai_redirection_wp_groups(): array
{
    return \Red_Group::get_all_for_module(\WordPress_Module::MODULE_ID);
}

function ai_redirection_default_group_id(): int
{
    $options = \Red_Options::get();
    $default = isset($options['last_group_id']) ? (int) $options['last_group_id'] : 0;
    if ($default && \Red_Group::get($default) !== false) {
        return $default;
    }

    $groups = ai_redirection_wp_groups();
    return isset($groups[0]) ? (int) $groups[0]['id'] : 0;
}

function ai_redirection_can_manage(): bool
{
    return class_exists('Redirection_Capabilities')
        ? \Redirection_Capabilities::has_access(\Redirection_Capabilities::CAP_REDIRECT_MANAGE)
        : current_user_can('manage_options');
}

function ai_redirection_can_add(): bool
{
    return class_exists('Redirection_Capabilities')
        ? \Redirection_Capabilities::has_access(\Redirection_Capabilities::CAP_REDIRECT_ADD)
        : current_user_can('manage_options');
}

function ai_redirection_can_add_group(): bool
{
    return class_exists('Redirection_Capabilities')
        ? \Redirection_Capabilities::has_access(\Redirection_Capabilities::CAP_GROUP_ADD)
        : current_user_can('manage_options');
}

// $json is a RedirectJson array (Red_Item::to_json()) — accepted directly rather than
// a Red_Item so it works for both single lookups and Red_Item::get_filtered() list rows.
function ai_redirection_redirect_payload(array $json): array
{
    $target_url = '';
    if ($json['action_type'] === 'url' && is_array($json['action_data']) && isset($json['action_data']['url'])) {
        $target_url = (string) $json['action_data']['url'];
    }

    $group = \Red_Group::get($json['group_id']);

    return [
        'id'          => $json['id'],
        'source_url'  => $json['url'],
        'target_url'  => $target_url,
        'action_type' => $json['action_type'],
        'action_code' => $json['action_code'],
        'title'       => $json['title'] ?: '',
        'group_id'    => $json['group_id'],
        'group_name'  => $group ? $group->get_name() : '',
        'enabled'     => $json['enabled'],
        'regex'       => $json['regex'],
        'hits'        => $json['hits'],
        'last_access' => $json['last_access'],
    ];
}

function ai_redirection_group_payload(array $group_json): array
{
    return [
        'id'             => $group_json['id'],
        'name'           => $group_json['name'],
        'redirect_count' => $group_json['redirects'],
        'enabled'        => $group_json['enabled'],
    ];
}

const AI_REDIRECTION_ACTION_TYPES = ['url', 'error'];
const AI_REDIRECTION_REDIRECT_CODES = [301, 302, 303, 304, 307, 308];
const AI_REDIRECTION_ERROR_CODES = [400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504];

// Runs at plugins_loaded when the file is required — before wp_abilities_api_init fires.
if (get_option('ddwpt_ai_redirection_abilities_enabled')) {
    add_action('wp_abilities_api_categories_init', static function () {
        wp_register_ability_category('wp-toolkit-redirection', [
            'label'       => 'WP Toolkit — Redirection',
            'description' => 'Abilities for managing redirects via the Redirection plugin, exposed by the WP Toolkit plugin.',
        ]);
    });

    add_action('wp_abilities_api_init', static function () {
        if (!function_exists('wp_register_ability') || !defined('REDIRECTION_VERSION')) {
            return;
        }

        $allow_write = (bool) get_option('ddwpt_ai_redirection_abilities_allow_write');

        wp_register_ability('wp-toolkit/list-redirects', [
            'label'               => 'List Redirects',
            'description'         => 'Query redirects managed by the Redirection plugin, with filtering by status, source URL, or group, and pagination.',
            'category'            => 'wp-toolkit-redirection',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [
                    'status'    => ['type' => 'string', 'enum' => ['enabled', 'disabled', 'any'], 'default' => 'any'],
                    'search'    => ['type' => 'string', 'description' => 'Filter by source URL (substring match).'],
                    'group_id'  => ['type' => 'integer', 'minimum' => 1],
                    'orderby'   => ['type' => 'string', 'enum' => ['source', 'last_count', 'last_access', 'position', 'id'], 'default' => 'id'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                    'per_page'  => ['type' => 'integer', 'minimum' => 5, 'maximum' => 200, 'default' => 25],
                    'page'      => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'redirects'   => [
                        'type'  => 'array',
                        'items' => ['type' => 'object'],
                    ],
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => static function ($input) {
                $filter_by = [];
                if (!empty($input['status']) && $input['status'] !== 'any') {
                    $filter_by['status'] = $input['status'];
                }
                if (!empty($input['search'])) {
                    $filter_by['url'] = sanitize_text_field($input['search']);
                }
                if (!empty($input['group_id'])) {
                    $filter_by['group'] = (int) $input['group_id'];
                }

                $per_page = min(200, max(5, (int) ($input['per_page'] ?? 25)));
                $page     = max(1, (int) ($input['page'] ?? 1));

                $result = \Red_Item::get_filtered([
                    'filterBy'  => $filter_by,
                    'per_page'  => $per_page,
                    'page'      => $page - 1,
                    'orderby'   => $input['orderby'] ?? 'id',
                    'direction' => $input['direction'] ?? 'desc',
                ]);

                return [
                    'redirects'   => array_map('DDWPTweaks\Tweaks\ai_redirection_redirect_payload', $result['items']),
                    'total'       => $result['total'],
                    'total_pages' => (int) ceil($result['total'] / $per_page),
                ];
            },
            'permission_callback' => static function () {
                return ai_redirection_can_manage();
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/get-redirect', [
            'label'               => 'Get Redirect',
            'description'         => 'Fetch a single redirect by ID.',
            'category'            => 'wp-toolkit-redirection',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required'             => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => ['type' => 'object'],
            'execute_callback'    => static function ($input) {
                $item = \Red_Item::get_by_id((int) $input['id']);
                if (!$item) {
                    return new \WP_Error('ddwpt_redirect_not_found', 'No redirect found with that ID.');
                }

                return ai_redirection_redirect_payload($item->to_json());
            },
            'permission_callback' => static function () {
                return ai_redirection_can_manage();
            },
            'meta'                => [
                'annotations'  => ['readonly' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/list-redirect-groups', [
            'label'               => 'List Redirect Groups',
            'description'         => 'List the redirect groups (folders) that redirects can be organized into. Only groups running through WordPress (not Apache/Nginx config) are listed, since only those are usable by create-redirect.',
            'category'            => 'wp-toolkit-redirection',
            'input_schema'        => [
                'type'                 => ['object', 'null'],
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'groups' => [
                        'type'  => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            'execute_callback'    => static function () {
                return ['groups' => array_map('DDWPTweaks\Tweaks\ai_redirection_group_payload', ai_redirection_wp_groups())];
            },
            'permission_callback' => static function () {
                return ai_redirection_can_manage();
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

        wp_register_ability('wp-toolkit/create-redirect', [
            'label'               => 'Create Redirect',
            'description'         => 'Create a new redirect. Use action_type "url" to redirect one URL to another (default 301), or "error" to make a URL return an HTTP error status (default 404) instead of redirecting.',
            'category'            => 'wp-toolkit-redirection',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'source_url'  => ['type' => 'string', 'minLength' => 1, 'description' => 'The URL (path or full URL) to match, e.g. "/old-page".'],
                    'target_url'  => ['type' => 'string', 'description' => 'Required when action_type is "url". Where to send matching requests.'],
                    'action_type' => ['type' => 'string', 'enum' => AI_REDIRECTION_ACTION_TYPES, 'default' => 'url'],
                    'action_code' => ['type' => 'integer', 'description' => 'HTTP status code. Defaults to 301 for "url", 404 for "error". Valid redirect codes: ' . implode(', ', AI_REDIRECTION_REDIRECT_CODES) . '. Valid error codes: ' . implode(', ', AI_REDIRECTION_ERROR_CODES) . '.'],
                    'title'       => ['type' => 'string', 'description' => 'Optional descriptive title.'],
                    'group_id'    => ['type' => 'integer', 'minimum' => 1, 'description' => 'Defaults to the site\'s default redirect group if omitted. See list-redirect-groups.'],
                    'regex'       => ['type' => 'boolean', 'default' => false, 'description' => 'Treat source_url as a regular expression.'],
                ],
                'required'             => ['source_url'],
                'additionalProperties' => false,
            ],
            'output_schema'       => ['type' => 'object'],
            'execute_callback'    => static function ($input) {
                $action_type = $input['action_type'] ?? 'url';

                if ($action_type === 'url' && empty($input['target_url'])) {
                    return new \WP_Error('ddwpt_redirect_target_required', 'A "target_url" is required when action_type is "url".');
                }

                $details = [
                    'url'         => sanitize_text_field($input['source_url']),
                    'match_type'  => 'url',
                    'action_type' => $action_type,
                    'action_code' => (int) ($input['action_code'] ?? ($action_type === 'error' ? 404 : 301)),
                    'regex'       => !empty($input['regex']) ? 1 : 0,
                    'group_id'    => (int) ($input['group_id'] ?? ai_redirection_default_group_id()),
                    'title'       => $input['title'] ?? '',
                ];

                if ($action_type === 'url') {
                    $details['action_data'] = ['url' => esc_url_raw($input['target_url'])];
                }

                $redirect = \Red_Item::create($details);
                if (is_wp_error($redirect)) {
                    return $redirect;
                }

                return ai_redirection_redirect_payload($redirect->to_json());
            },
            'permission_callback' => static function () {
                return ai_redirection_can_add();
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/update-redirect', [
            'label'               => 'Update Redirect',
            'description'         => 'Update an existing redirect\'s source/target URL, action, group, title, or enabled status. Only provided fields are changed.',
            'category'            => 'wp-toolkit-redirection',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'id'          => ['type' => 'integer', 'minimum' => 1],
                    'source_url'  => ['type' => 'string', 'minLength' => 1],
                    'target_url'  => ['type' => 'string', 'description' => 'Required if changing action_type to "url" on a redirect that doesn\'t already have one.'],
                    'action_type' => ['type' => 'string', 'enum' => AI_REDIRECTION_ACTION_TYPES],
                    'action_code' => ['type' => 'integer'],
                    'title'       => ['type' => 'string'],
                    'group_id'    => ['type' => 'integer', 'minimum' => 1],
                    'regex'       => ['type' => 'boolean'],
                    'enabled'     => ['type' => 'boolean', 'description' => 'Enable or disable the redirect.'],
                ],
                'required'             => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => ['type' => 'object'],
            'execute_callback'    => static function ($input) {
                $item = \Red_Item::get_by_id((int) $input['id']);
                if (!$item) {
                    return new \WP_Error('ddwpt_redirect_not_found', 'No redirect found with that ID.');
                }

                $json        = $item->to_json();
                $action_type = $input['action_type'] ?? $json['action_type'];

                $details = [
                    'url'         => isset($input['source_url']) ? sanitize_text_field($input['source_url']) : $json['url'],
                    'match_type'  => $json['match_type'] ?: 'url',
                    'action_type' => $action_type,
                    'action_code' => isset($input['action_code']) ? (int) $input['action_code'] : $json['action_code'],
                    'regex'       => isset($input['regex']) ? (!empty($input['regex']) ? 1 : 0) : ($json['regex'] ? 1 : 0),
                    'group_id'    => isset($input['group_id']) ? (int) $input['group_id'] : $json['group_id'],
                    'title'       => $input['title'] ?? ($json['title'] ?: ''),
                    'position'    => $json['position'],
                ];

                if ($action_type === 'url') {
                    $target = $input['target_url']
                        ?? (($json['action_type'] === 'url' && is_array($json['action_data'])) ? ($json['action_data']['url'] ?? '') : '');

                    if (empty($target)) {
                        return new \WP_Error('ddwpt_redirect_target_required', 'A "target_url" is required when action_type is "url".');
                    }

                    $details['action_data'] = ['url' => esc_url_raw($target)];
                }

                $result = $item->update($details);
                if (is_wp_error($result)) {
                    return $result;
                }

                if (array_key_exists('enabled', $input)) {
                    $input['enabled'] ? $item->enable() : $item->disable();
                }

                $item = \Red_Item::get_by_id($item->get_id());

                return ai_redirection_redirect_payload($item->to_json());
            },
            'permission_callback' => static function () {
                return ai_redirection_can_add();
            },
            'meta'                => [
                'annotations'  => ['destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);

        wp_register_ability('wp-toolkit/create-redirect-group', [
            'label'               => 'Create Redirect Group',
            'description'         => 'Create a new redirect group (folder) to organize redirects into.',
            'category'            => 'wp-toolkit-redirection',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                ],
                'required'             => ['name'],
                'additionalProperties' => false,
            ],
            'output_schema'       => ['type' => 'object'],
            'execute_callback'    => static function ($input) {
                $group = \Red_Group::create(sanitize_text_field($input['name']), \WordPress_Module::MODULE_ID, true);
                if (!$group) {
                    return new \WP_Error('ddwpt_redirect_group_create_failed', 'Could not create the group. Provide a non-empty name.');
                }

                return ai_redirection_group_payload($group->to_json());
            },
            'permission_callback' => static function () {
                return ai_redirection_can_add_group();
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
    'id'    => 'ddwpt_ai_redirection_abilities',
    'label' => 'AI Redirection Abilities',
    'tab'   => 'ai',
    'group' => 'abilities',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Requires the <a href="https://redirection.me/" target="_blank" rel="noopener">Redirection</a> plugin to be active. Registers read abilities (<code>list-redirects</code>, <code>get-redirect</code>, <code>list-redirect-groups</code>) via the WordPress Abilities API.',
        ],
        [
            'id'          => 'allow_write',
            'type'        => 'checkbox',
            'label'       => 'Allow redirect creation & updates',
            'description' => 'Also registers write abilities: <code>create-redirect</code>, <code>update-redirect</code>, and <code>create-redirect-group</code>. No delete ability is provided — disable a redirect instead via <code>update-redirect</code>.',
        ],
    ],

    'callback' => static function ($settings) {
        // Ability registration is handled at file-load time above.
    },
];

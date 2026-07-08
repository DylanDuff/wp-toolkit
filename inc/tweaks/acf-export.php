<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_export',
    'label' => 'Export ACF Presets',
    'tab'   => 'acf',
    'group' => 'general',

    'settings' => [
        [
            'id'          => 'export',
            'type'        => 'acf_export',
            'label'       => 'Export',
            'description' => 'Downloads a .json file importable via ACF → Tools → Import. Includes field groups from all currently enabled ACF preset tweaks.',
        ],
    ],

    'callback' => function ($settings) {
        add_filter('ddwpt_localize_data', function ($data) {
            $data['acfExportNonce'] = wp_create_nonce('ddwpt_acf_export');
            return $data;
        });

        add_action('wp_ajax_ddwpt_acf_export', function () {
            check_ajax_referer('ddwpt_acf_export', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            if (!function_exists('acf_get_field_group') || !function_exists('acf_get_fields')) {
                wp_send_json_error('ACF is not active.');
            }

            // Map of enabled-option → one or more local field group keys per preset.
            $preset_groups = [
                'ddwpt_acf_faq_enabled'          => ['group_695c328871ee2'],
                'ddwpt_acf_locations_enabled'    => ['group_68293c2d5e3b1'],
                'ddwpt_acf_projects_enabled'     => ['group_68293d3e6f4c1', 'group_68293d3e6f4c5'],
                'ddwpt_acf_site_options_enabled' => ['group_66d540426b8e3'],
                'ddwpt_acf_team_members_enabled' => ['group_68293a7c2f1e1', 'group_68293a7c2f1e5'],
                'ddwpt_acf_testimonials_enabled' => ['group_68293b1c4d2a1', 'group_68293b1c4d2a5'],
            ];

            $export = [];
            foreach ($preset_groups as $option => $group_keys) {
                if (!get_option($option)) continue;
                foreach ($group_keys as $group_key) {
                    $group = acf_get_field_group($group_key);
                    if (!$group) continue;
                    $group['fields'] = acf_get_fields($group) ?: [];
                    $export[] = $group;
                }
            }

            if (empty($export)) {
                wp_send_json_error('No enabled ACF presets with field groups found. Enable at least one ACF preset tweak first.');
            }

            wp_send_json_success($export);
        });
    },
];

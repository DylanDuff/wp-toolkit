<?php

namespace DDWPTweaks\Tweaks;

function environment_badge($wp_admin_bar)
{
    $override    = get_option('ddwpt_environment_indicator_environment', '');
    $staging_url = trim(get_option('ddwpt_environment_indicator_staging_url', ''));

    if ($override) {
        $env = $override;
    } elseif ($staging_url && strpos(home_url(), $staging_url) !== false) {
        $env = 'staging';
    } else {
        $env = wp_get_environment_type();
    }

    $colors = [
        'local'       => '#2271b1',
        'development' => '#2271b1',
        'staging'     => '#dba617',
        'production'  => '#00a32a',
    ];

    $color = $colors[$env] ?? '#888';
    $label = ucfirst($env);

    $wp_admin_bar->add_node([
        'id'    => 'ddwpt-env-indicator',
        'title' => '<span style="
            display: inline-block;
            background: ' . esc_attr($color) . ';
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            line-height: 1;
            padding: 4px 8px;
            border-radius: 3px;
            vertical-align: middle;
        ">' . esc_html($label) . '</span>',
        'meta'  => ['tabindex' => -1],
    ]);
}

return [
    'id'    => 'ddwpt_environment_indicator',
    'label' => 'Environment Indicator',
    'tab'   => 'admin-bar',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Show a coloured environment badge in the admin bar.',
        ],
        [
            'id'          => 'staging_url',
            'type'        => 'text',
            'label'       => 'Staging URL fragment',
            'description' => 'If this value appears anywhere in the site URL, the environment will be treated as Staging. E.g. "staging." or "mystagingdomain.com". Only applies when Environment is set to Auto.',
            'default'     => '',
        ],
        [
            'id'          => 'environment',
            'type'        => 'select',
            'label'       => 'Environment',
            'description' => 'Override the detected environment type, or leave as Auto to use wp_get_environment_type().',
            'default'     => '',
            'options'     => [
                ''            => 'Auto (detect)',
                'local'       => 'Local',
                'development' => 'Development',
                'staging'     => 'Staging',
                'production'  => 'Production',
            ],
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('admin_bar_menu', __NAMESPACE__ . '\\environment_badge', 999);
    },
];

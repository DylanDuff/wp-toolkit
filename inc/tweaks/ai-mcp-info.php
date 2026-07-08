<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'     => 'ddwpt_ai_mcp_info',
    'tab'    => 'ai',
    'group'  => 'overview',
    'render' => function (\DDWPTweaks\Plugin $plugin) {
        $mcp_file      = 'mcp-adapter/mcp-adapter.php';
        $mcp_installed = file_exists(WP_PLUGIN_DIR . '/' . $mcp_file);
        $mcp_active    = $mcp_installed && is_plugin_active($mcp_file);

        $badge = $mcp_active
            ? ['label' => 'Active',        'class' => 'is-active']
            : ($mcp_installed
                ? ['label' => 'Inactive',      'class' => 'is-inactive']
                : ['label' => 'Not installed', 'class' => 'is-missing']);

        $status_fields = [];
        if (!$mcp_installed) {
            $status_fields[] = [
                'label' => 'Install',
                'html'  => '<p class="description">Download and activate the <a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noopener">MCP Adapter plugin</a> to expose an MCP endpoint for this site.</p>',
            ];
        } elseif (!$mcp_active) {
            $status_fields[] = [
                'label' => 'Activate',
                'html'  => '<a href="' . esc_url(admin_url('plugins.php')) . '" class="ddwpt-btn">Go to Plugins</a>'
                         . '<p class="description" style="margin-top:8px;">MCP Adapter is installed but not active. Activate it to expose the MCP endpoint.</p>',
            ];
        }

        $plugin->render_info_card(
            'MCP Adapter',
            'The <a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noopener">WordPress MCP Adapter</a> exposes a Model Context Protocol endpoint that AI clients (Claude Desktop, Cursor, etc.) can connect to.',
            $status_fields,
            $badge
        );

        if (!$mcp_active) return;

        $endpoint    = site_url('/wp-json/mcp/mcp-adapter-default-server');
        $profile_url = admin_url('profile.php') . '#application-passwords-section';
        $mcp_json    = wp_json_encode([
            'mcpServers' => [
                'wordpress' => [
                    'command' => 'npx',
                    'args'    => ['-y', '@automattic/mcp-wordpress-remote'],
                    'env'     => [
                        'WP_API_URL'      => $endpoint,
                        'WP_API_USERNAME' => wp_get_current_user()->user_login,
                        'WP_API_PASSWORD' => 'your-application-password',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $copy_url_html = '<div class="ddwpt-copy-field">'
            . '<code class="ddwpt-copy-value">' . esc_html($endpoint) . '</code>'
            . '<button type="button" class="ddwpt-btn ddwpt-copy-btn" data-copy="' . esc_attr($endpoint) . '">Copy</button>'
            . '</div>'
            . '<p class="description">Set this as the MCP server URL in your AI client configuration.</p>';

        $copy_json_html = '<div class="ddwpt-mcp-json-wrap">'
            . '<pre class="ddwpt-mcp-json">' . esc_html($mcp_json) . '</pre>'
            . '<button type="button" class="ddwpt-btn ddwpt-copy-btn" data-copy="' . esc_attr($mcp_json) . '">Copy</button>'
            . '</div>'
            . '<p class="description">Add this to your <code>claude_desktop_config.json</code> or equivalent MCP client config. Replace the password placeholder with your Application Password credentials.</p>';

        $plugin->render_info_card(
            'Connection Details',
            'Use these details to connect an MCP-compatible AI client to this site.',
            [
                ['label' => 'Endpoint URL',          'html' => $copy_url_html],
                ['label' => 'Transport',              'html' => '<code>Streamable HTTP</code><p class="description">Implements the MCP 2025-03-26 Streamable HTTP specification.</p>'],
                ['label' => 'Config (Claude / Cursor)', 'html' => $copy_json_html],
            ]
        );

        $auth_fields = [];
        if (!wp_is_application_passwords_available()) {
            $auth_fields[] = [
                'label' => 'Notice',
                'html'  => '<p class="description">Application Passwords are not currently available on this site. WordPress disables them on non-HTTPS environments by default — enable the <strong>Enable Application Passwords</strong> tweak on the General tab to force them on.</p>',
            ];
        }
        $auth_fields[] = [
            'label' => 'Steps',
            'html'  => '<ol class="ddwpt-ai-steps">'
                . '<li>Go to <a href="' . esc_url($profile_url) . '">Users &rarr; Your Profile &rarr; Application Passwords</a>.</li>'
                . '<li>Enter a name (e.g. <em>Claude Desktop</em>) and click <strong>Add New Application Password</strong>.</li>'
                . '<li>Copy the generated password immediately — it will not be shown again.</li>'
                . '<li>In your MCP client config, provide your WordPress username and the application password.</li>'
                . '</ol>',
        ];

        $plugin->render_info_card(
            'Authentication',
            'MCP Adapter uses WordPress Application Passwords — not your login password.',
            $auth_fields
        );

        if (!function_exists('wp_get_abilities')) return;
        $abilities = wp_get_abilities();
        if (empty($abilities)) return;

        $count      = count($abilities);
        $table_html = '<table class="ddwpt-field-keys-table">'
            . '<thead><tr><th>Name</th><th>Category</th><th>Description</th></tr></thead>'
            . '<tbody>';
        foreach ($abilities as $ability) {
            $table_html .= '<tr>'
                . '<td><code>' . esc_html($ability->get_name()) . '</code></td>'
                . '<td>' . esc_html($ability->get_category()) . '</td>'
                . '<td>' . esc_html($ability->get_description()) . '</td>'
                . '</tr>';
        }
        $table_html .= '</tbody></table>';

        $plugin->render_info_card(
            'Registered Abilities',
            $count . ' ' . ($count === 1 ? 'ability' : 'abilities') . ' currently registered via the WordPress Abilities API.',
            [['full' => true, 'html' => $table_html]]
        );
    },
];

<?php

namespace DDWPTweaks\Tweaks;

/** MCP Adapter state: 'active', 'inactive' (installed only) or 'missing'. */
function ai_mcp_adapter_state(): string
{
    $file = 'mcp-adapter/mcp-adapter.php';
    if (!file_exists(WP_PLUGIN_DIR . '/' . $file)) return 'missing';
    return \DDWPTweaks\Settings_Layout::plugin_active($file) ? 'active' : 'inactive';
}

function ai_mcp_copy_field(string $value, string $label = 'Copy'): string
{
    return '<div class="ddwpt-copy-field">'
        . '<code class="ddwpt-copy-value">' . esc_html($value) . '</code>'
        . '<button type="button" class="ddwpt-btn ddwpt-copy-btn" data-copy="' . esc_attr($value) . '">' . esc_html($label) . '</button>'
        . '</div>';
}

// AI setup card: connection status, a summary of what agents can do, and
// the details an MCP client needs. Ability tweaks follow it as cards.
function ai_mcp_render_setup(\DDWPTweaks\Plugin $plugin): void
{
    $state    = ai_mcp_adapter_state();
    $summary  = $plugin->get_ability_summary();
    $endpoint = site_url('/wp-json/mcp/mcp-adapter-default-server');
    $profile  = admin_url('profile.php') . '#application-passwords-section';
    $app_passwords = wp_is_application_passwords_available();

    $badge = [
        'active'   => ['Connected', 'is-active'],
        'inactive' => ['MCP Adapter inactive', 'is-inactive'],
        'missing'  => ['MCP Adapter missing', 'is-missing'],
    ][$state];

    $config = wp_json_encode([
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

    $steps = [
        [$state === 'active', 'MCP Adapter plugin active'],
        [$app_passwords, 'Application Passwords available'],
        [$summary['live'] > 0, 'At least one ability tweak on'],
    ];
    ?>
    <div class="ddwpt-card ddwpt-ai-setup">
        <div class="ddwpt-card-header">
            <div class="ddwpt-card-info">
                <h3 class="ddwpt-card-title">
                    AI setup
                    <span class="ddwpt-status-badge <?php echo esc_attr($badge[1]); ?>"><?php echo esc_html($badge[0]); ?></span>
                </h3>
                <p class="ddwpt-card-desc">Connect an MCP client and choose what agents can do.</p>
            </div>
            <a href="#ai/registered-abilities" class="ddwpt-btn">Registered Abilities →</a>
        </div>

        <div class="ddwpt-card-body ddwpt-ai-stats">
            <div class="ddwpt-ai-stat"><b><?php echo (int) $summary['live']; ?></b><span>of <?php echo (int) $summary['total']; ?> abilities live</span></div>
            <div class="ddwpt-ai-stat"><b><?php echo (int) $summary['read']; ?></b><span>read</span></div>
            <div class="ddwpt-ai-stat"><b><?php echo (int) $summary['write']; ?></b><span>write</span></div>
            <div class="ddwpt-ai-stat"><b><?php echo (int) $summary['tweaks_on']; ?>/<?php echo (int) $summary['tweaks']; ?></b><span>ability tweaks on</span></div>
        </div>

        <div class="ddwpt-card-body">
            <div class="ddwpt-field-row">
                <span class="ddwpt-field-label">Checklist</span>
                <div class="ddwpt-field-input">
                    <ul class="ddwpt-ai-checklist">
                        <?php foreach ($steps as [$done, $text]): ?>
                        <li class="<?php echo $done ? 'is-done' : ''; ?>"><?php echo $done ? '✓' : '○'; ?> <?php echo esc_html($text); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($state === 'missing'): ?>
                    <p class="description">Install the <a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noopener">MCP Adapter plugin</a> to expose an MCP endpoint for this site.</p>
                    <?php elseif ($state === 'inactive'): ?>
                    <p class="description">MCP Adapter is installed but not active. <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">Go to Plugins</a> to activate it.</p>
                    <?php endif; ?>
                    <?php if (!$app_passwords): ?>
                    <p class="description">WordPress turns Application Passwords off without HTTPS. Turn on <a href="#ddwpt-card-ddwpt_enable_application_passwords" data-goto-card="ddwpt-card-ddwpt_enable_application_passwords">Enable Application Passwords</a> to force them on.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($state === 'active'): ?>
            <div class="ddwpt-field-row">
                <span class="ddwpt-field-label">Endpoint</span>
                <div class="ddwpt-field-input">
                    <?php echo ai_mcp_copy_field($endpoint); ?>
                    <p class="description">Streamable HTTP transport (MCP 2025-03-26).</p>
                </div>
            </div>
            <div class="ddwpt-field-row">
                <span class="ddwpt-field-label">Client config</span>
                <div class="ddwpt-field-input">
                    <button type="button" class="ddwpt-btn ddwpt-copy-btn" data-copy="<?php echo esc_attr($config); ?>" data-copy-label="Copy JSON config">Copy JSON config</button>
                    <p class="description">For <code>claude_desktop_config.json</code> or any MCP client. Replace the password placeholder with an Application Password.</p>
                </div>
            </div>
            <details class="ddwpt-field-accordion">
                <summary class="ddwpt-field-accordion-summary">How to create an Application Password</summary>
                <div class="ddwpt-field-accordion-body">
                    <ol class="ddwpt-ai-steps">
                        <li>Go to <a href="<?php echo esc_url($profile); ?>">Users &rarr; Your Profile &rarr; Application Passwords</a>.</li>
                        <li>Enter a name (e.g. <em>Claude Desktop</em>) and click <strong>Add New Application Password</strong>.</li>
                        <li>Copy the generated password straight away. It is not shown again.</li>
                        <li>In your MCP client config, provide your WordPress username and the application password.</li>
                    </ol>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Every ability registered on the site (not only WP Toolkit's), with a
// namespace filter and text search handled by settings.js.
function ai_mcp_render_registered_abilities(): void
{
    $abilities = function_exists('wp_get_abilities') ? wp_get_abilities() : [];
    $count     = count($abilities);

    $namespaces = [];
    foreach ($abilities as $ability) {
        $namespace = strtok($ability->get_name(), '/');
        $namespaces[$namespace] = ($namespaces[$namespace] ?? 0) + 1;
    }
    ksort($namespaces);
    ?>
    <div class="ddwpt-card ddwpt-registry" data-search="Registered Abilities">
        <div class="ddwpt-card-header">
            <div class="ddwpt-card-info">
                <h3 class="ddwpt-card-title">Registered Abilities</h3>
                <p class="ddwpt-card-desc">
                    <?php if (!function_exists('wp_get_abilities')): ?>
                    The Abilities API needs WordPress 6.9 or later.
                    <?php else: ?>
                    <?php echo esc_html($count . ' ' . ($count === 1 ? 'ability' : 'abilities') . ' currently registered via the WordPress Abilities API.'); ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($count): ?>
            <input type="search" class="ddwpt-registry-search" placeholder="Filter abilities" aria-label="Filter abilities" />
            <?php endif; ?>
        </div>
        <?php if ($count): ?>
        <div class="ddwpt-card-body ddwpt-registry-filters">
            <button type="button" class="ddwpt-chip is-active" data-registry-namespace="">All · <?php echo (int) $count; ?></button>
            <?php foreach ($namespaces as $namespace => $total): ?>
            <button type="button" class="ddwpt-chip" data-registry-namespace="<?php echo esc_attr($namespace); ?>"><?php echo esc_html($namespace); ?> · <?php echo (int) $total; ?></button>
            <?php endforeach; ?>
        </div>
        <div class="ddwpt-card-body ddwpt-card-body-flush">
            <table class="ddwpt-field-keys-table ddwpt-registry-table">
                <thead><tr><th>Name</th><th>Category</th><th>Description</th></tr></thead>
                <tbody>
                    <?php foreach ($abilities as $ability): ?>
                    <tr data-namespace="<?php echo esc_attr(strtok($ability->get_name(), '/')); ?>">
                        <td><code><?php echo esc_html($ability->get_name()); ?></code></td>
                        <td><?php echo esc_html($ability->get_category()); ?></td>
                        <td><?php echo esc_html($ability->get_description()); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

return [
    'id'          => 'ddwpt_ai_mcp_info',
    'label'       => 'AI setup',
    'tab'         => 'ai',
    'description' => 'Connect an MCP client and choose what agents can do.',
    'position'    => 'first',
    'pages'       => [
        [
            'id'     => 'registered-abilities',
            'label'  => 'Registered Abilities',
            'render' => __NAMESPACE__ . '\\ai_mcp_render_registered_abilities',
        ],
    ],
    'render' => __NAMESPACE__ . '\\ai_mcp_render_setup',
];

<?php

namespace DDWPTweaks\Tweaks;

function floating_panel_collect_data(): array
{
    $user = wp_get_current_user();
    $post_id = get_queried_object_id();
    $is_single = is_singular() && $post_id;

    $env = wp_get_environment_type();
    $env_label = ucfirst($env);

    $updates_count = 0;
    $plugin_updates = get_site_transient('update_plugins');
    $theme_updates = get_site_transient('update_themes');
    $core_updates = get_site_transient('update_core');
    if (!empty($plugin_updates->response)) {
        $updates_count += count($plugin_updates->response);
    }
    if (!empty($theme_updates->response)) {
        $updates_count += count($theme_updates->response);
    }
    if (!empty($core_updates->updates)) {
        foreach ($core_updates->updates as $u) {
            if ($u->response === 'upgrade') {
                $updates_count++;
            }
        }
    }

    $edit_url = '';
    $bricks_edit_url = '';
    $is_bricks_page = false;

    if ($is_single && current_user_can('edit_post', $post_id)) {
        $edit_url = (string) get_edit_post_link($post_id, 'raw');

        if (defined('BRICKS_VERSION')) {
            $bricks_types = class_exists('\Bricks\Database')
                ? \Bricks\Database::get_setting('postTypes', ['page'])
                : ['page'];

            if (in_array(get_post_type($post_id), $bricks_types, true)) {
                $bricks_db_key = defined('BRICKS_DB_PAGE_CONTENT')
                    ? BRICKS_DB_PAGE_CONTENT
                    : '_bricks_page_content_2';
                $is_bricks_page = !empty(get_post_meta($post_id, $bricks_db_key, true));
                $bricks_edit_url = (string) add_query_arg('bricks', 'run', get_permalink($post_id));
            }
        }
    }

    $role_labels = [
        'administrator' => 'Administrator',
        'editor'        => 'Editor',
        'author'        => 'Author',
        'contributor'   => 'Contributor',
        'subscriber'    => 'Subscriber',
    ];
    $role = !empty($user->roles) ? $user->roles[0] : '';

    return [
        'site_name'       => get_bloginfo('name'),
        'site_icon_url'   => get_site_icon_url(64) ?: '',
        'env'             => $env,
        'env_label'       => $env_label,
        'wp_version'      => get_bloginfo('version'),
        'updates_count'   => $updates_count,
        'updates_url'     => admin_url('update-core.php'),
        'edit_url'         => $edit_url,
        'bricks_edit_url'  => $bricks_edit_url,
        'wp_icon_svg'      => (function () {
            $path = ABSPATH . 'wp-admin/images/wordpress-logo.svg';
            if (!is_readable($path)) {
                return '';
            }
            $svg = file_get_contents($path);
            $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg);
            $svg = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $svg);
            return trim($svg);
        })(),
        'bricks_icon_svg'  => (function () {
            if (!defined('BRICKS_VERSION')) {
                return '';
            }
            $path = get_theme_root() . '/bricks/assets/images/bricks-favicon-b.svg';
            if (!is_readable($path)) {
                return '';
            }
            // Strip XML declaration; keep the <svg> element so CSS can target its paths.
            return trim(preg_replace('/<\?xml[^>]*\?>/i', '', file_get_contents($path)));
        })(),
        'is_bricks_page'   => $is_bricks_page,
        'customize_url'   => admin_url('customize.php'),
        'admin_url'       => admin_url(),
        'profile_url'     => admin_url('profile.php'),
        'logout_url'      => wp_logout_url((string) get_permalink() ?: home_url('/')),
        'user_name'       => $user->display_name ?: $user->user_login,
        'user_login'      => $user->user_login,
        'user_role'       => $role_labels[$role] ?? ucfirst($role),
        'current_post_id'             => $is_single ? (int) $post_id : 0,
        'simple_history_active'       => class_exists('\Simple_History\Simple_History'),
        'simple_history_page_url'     => admin_url('admin.php?page=simple_history_admin_menu_page'),
        'simple_history_settings_url' => admin_url('options-general.php?page=simple_history_settings_menu_slug'),
    ];
}

function floating_panel_get_nodes(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    global $wp_admin_bar;

    if (!is_object($wp_admin_bar)) {
        return $cache = [];
    }

    try {
        $prop = (new \ReflectionObject($wp_admin_bar))->getProperty('nodes');
        $prop->setAccessible(true);
        $nodes = $prop->getValue($wp_admin_bar);
    } catch (\Throwable $e) {
        return $cache = [];
    }

    if (empty($nodes)) {
        do_action_ref_array('admin_bar_menu', [&$wp_admin_bar]);
        $nodes = $prop->getValue($wp_admin_bar);
    }

    return $cache = (is_array($nodes) ? $nodes : []);
}

function floating_panel_collect_new_items(): array
{
    $all_nodes = floating_panel_get_nodes();
    if (empty($all_nodes)) {
        return [];
    }
    return floating_panel_node_children($all_nodes, 'new-content');
}

function floating_panel_collect_chips(): array
{
    $all_nodes = floating_panel_get_nodes();

    if (empty($all_nodes)) {
        return [];
    }

    $core_ids = [
        'root',
        'wp-logo', 'wp-logo-external',
        'about', 'wporg', 'documentation', 'support-forums', 'feedback', 'learn', 'contribute',
        'site-name', 'view-site', 'edit-site', 'appearance',
        'updates', 'comments',
        'new-content',
        'top-secondary',
        'my-sites', 'network-admin',
        'menu-toggle',
        'customize', 'edit',
        'my-account', 'user-actions', 'user-info', 'logout', 'search',
    ];

    $chips = [];

    foreach ($all_nodes as $node) {
        $id = (string) $node->id;

        if ($node->parent !== false && $node->parent !== null && $node->parent !== 'root' && $node->parent !== 'root-default') {
            continue; // sub-item, silent skip
        }
        if (str_ends_with($id, '-default')) {
            continue; // internal group, silent skip
        }
        if (!empty($node->group)) {
            continue;
        }
        if (in_array($id, $core_ids, true)) {
            continue;
        }
        if (str_starts_with($id, 'ddwpt-')) {
            continue;
        }

        $title = trim(wp_strip_all_tags((string) $node->title));
        if ($title === '') {
            continue;
        }

        $children = floating_panel_node_children($all_nodes, $node->id);

        if ($children === [] && empty($node->href)) {
            continue;
        }

        $chips[] = [
            'title'    => $title,
            'href'     => (string) ($node->href ?: ''),
            'children' => $children,
        ];
    }

    return $chips;
}

function floating_panel_node_children(array $all_nodes, string $parent_id): array
{
    $children = [];

    // After _bind(), WP moves children into a $parent_id-default group node.
    // Search both the original parent ID and its default group to cover both cases.
    $parent_ids = [$parent_id, $parent_id . '-default'];

    foreach ($all_nodes as $node) {
        if (!in_array($node->parent, $parent_ids, true)) {
            continue;
        }

        // Some nodes (e.g. WP-created -default groups) may lack a group property.
        if (isset($node->group) && $node->group) {
            $children = array_merge($children, floating_panel_node_children($all_nodes, $node->id));
            continue;
        }

        $title = trim(wp_strip_all_tags((string) $node->title));
        if ($title === '' || empty($node->href)) {
            continue;
        }

        $children[] = [
            'title' => $title,
            'href'  => (string) $node->href,
        ];
    }

    return $children;
}

function floating_panel_render(array $data, array $chips, array $new_items): void
{
    $env = $data['env'];
    $env_class = in_array($env, ['staging', 'production'], true) ? 'env-' . $env : '';
    ?>
    <div id="ddwpt-fp-root">

    <!-- Tab bar -->
    <div class="ddwpt-tab-bar" id="ddwpt-tab-bar">
        <div class="ddwpt-tab-tabs">
            <button class="ddwpt-tab-btn active" data-tab="overview">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                Overview
            </button>
            <button class="ddwpt-tab-btn" data-tab="history">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                History
            </button>
            <button class="ddwpt-tab-btn" data-tab="seo">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                SEO
            </button>
        </div>
        <div class="ddwpt-tab-avatar">
            <div class="ddwpt-avatar-circle">
                <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div class="ddwpt-avatar-pop">
                <div class="ddwpt-avatar-header">
                    <div class="ddwpt-avatar-name"><?php echo esc_html($data['user_name']); ?></div>
                    <div class="ddwpt-avatar-role"><?php echo esc_html($data['user_role']); ?></div>
                </div>
                <a class="ddwpt-avatar-item" href="<?php echo esc_url($data['profile_url']); ?>">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Edit profile
                </a>
                <div class="ddwpt-avatar-sep"></div>
                <a class="ddwpt-avatar-item" href="<?php echo esc_url($data['admin_url']); ?>">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                    WP Admin
                </a>
                <a class="ddwpt-avatar-item" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    View site
                </a>
                <div class="ddwpt-avatar-sep"></div>
                <a class="ddwpt-avatar-item ddwpt-danger" href="<?php echo esc_url($data['logout_url']); ?>">
                    <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Log out
                </a>
            </div>
        </div>
    </div>

    <!-- Panel -->
    <div class="ddwpt-panel" id="ddwpt-panel">

        <div class="ddwpt-panel-header">
            <div class="ddwpt-ph-left">
                <div class="ddwpt-wp-logo">
                    <?php if ($data['site_icon_url']) : ?>
                    <img src="<?php echo esc_url($data['site_icon_url']); ?>" alt="" aria-hidden="true">
                    <?php else : ?>W<?php endif; ?>
                </div>
                <span class="ddwpt-site-name"><?php echo esc_html($data['site_name']); ?></span>
                <span class="ddwpt-env-tag <?php echo esc_attr($env_class); ?>"><?php echo esc_html($data['env_label']); ?></span>
            </div>
            <span class="ddwpt-ph-right"><?php echo esc_html($data['user_login']); ?></span>
        </div>

        <!-- Overview pane -->
        <div class="ddwpt-pane" id="ddwpt-pane-overview">

            <div class="ddwpt-panel-section">
                <div class="ddwpt-sec-label">WordPress</div>
                <div class="ddwpt-native-row">
                    <?php if (!empty($new_items)) : ?>
                    <div class="ddwpt-pchip">
                        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        New
                        <div class="ddwpt-pop">
                            <?php foreach ($new_items as $item) : ?>
                            <a class="ddwpt-pop-item" href="<?php echo esc_url($item['href']); ?>">
                                <?php echo esc_html($item['title']); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($data['updates_url']); ?>" class="ddwpt-nbtn">
                        <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Updates
                        <?php if ($data['updates_count'] > 0) : ?>
                        <span class="ddwpt-update-badge"><?php echo (int) $data['updates_count']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo esc_url($data['customize_url']); ?>" class="ddwpt-nbtn">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
                        Customise
                    </a>
                </div>
            </div>

            <?php if (!empty($chips)) : ?>
            <div class="ddwpt-panel-section">
                <div class="ddwpt-sec-label">Plugins</div>
                <div class="ddwpt-plugin-chips">
                    <?php foreach ($chips as $chip) : ?>
                        <?php if (!empty($chip['children'])) : ?>
                        <div class="ddwpt-pchip">
                            <?php echo esc_html($chip['title']); ?>
                            <div class="ddwpt-pop">
                                <?php foreach ($chip['children'] as $child) : ?>
                                <a class="ddwpt-pop-item" href="<?php echo esc_url($child['href']); ?>">
                                    <?php echo esc_html($child['title']); ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php elseif (!empty($chip['href'])) : ?>
                        <a class="ddwpt-nbtn" href="<?php echo esc_url($chip['href']); ?>">
                            <?php echo esc_html($chip['title']); ?>
                        </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($data['edit_url'] || $data['bricks_edit_url']) : ?>
            <div class="ddwpt-panel-section">
                <div class="ddwpt-sec-label">Edit</div>
                <div class="ddwpt-action-grid">
                    <?php if ($data['bricks_edit_url']) : ?>
                    <a href="<?php echo esc_url($data['bricks_edit_url']); ?>" class="ddwpt-abtn">
                        <span class="ddwpt-aicon<?php echo $data['bricks_icon_svg'] ? ' ddwpt-aicon--bricks' : ''; ?>">
                            <?php if ($data['bricks_icon_svg']) : ?>
                            <?php echo $data['bricks_icon_svg']; ?>
                            <?php else : ?>
                            <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <?php endif; ?>
                        </span>
                        <span class="ddwpt-atxt">
                            <span class="ddwpt-albl">Edit with Bricks</span>
                            <span class="ddwpt-asub">Visual builder</span>
                        </span>
                    </a>
                    <?php endif; ?>
                    <?php if ($data['edit_url']) : ?>
                    <a href="<?php echo esc_url($data['edit_url']); ?>" class="ddwpt-abtn">
                        <span class="ddwpt-aicon<?php echo $data['wp_icon_svg'] ? ' ddwpt-aicon--wordpress' : ''; ?>">
                            <?php if ($data['wp_icon_svg']) : ?>
                            <?php echo $data['wp_icon_svg']; ?>
                            <?php else : ?>
                            <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            <?php endif; ?>
                        </span>
                        <span class="ddwpt-atxt">
                            <span class="ddwpt-albl">Edit with WordPress</span>
                            <span class="ddwpt-asub">Gutenberg</span>
                        </span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="ddwpt-status-row">
                <span>
                    <span class="ddwpt-sdot"></span>
                    <?php echo $data['is_bricks_page'] ? 'Rendered with Bricks' : 'WordPress'; ?>
                </span>
                <span>WordPress <?php echo esc_html($data['wp_version']); ?></span>
            </div>

        </div><!-- /#ddwpt-pane-overview -->

        <!-- History pane -->
        <div class="ddwpt-pane" id="ddwpt-pane-history">
            <?php if ($data['simple_history_active']) : ?>
            <div class="ddwpt-hist-toolbar">
                <div class="ddwpt-hist-filter">
                    <button class="ddwpt-hist-filter-btn active" data-filter="all">All history</button>
                    <?php if ($data['current_post_id']) : ?>
                    <button class="ddwpt-hist-filter-btn" data-filter="page">This page</button>
                    <?php endif; ?>
                </div>
                <button class="ddwpt-hist-reload" id="ddwpt-hist-reload">
                    <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    Reload
                </button>
            </div>
            <div class="ddwpt-hist-list" id="ddwpt-hist-list">
                <div class="ddwpt-hist-loading">
                    <svg class="ddwpt-spin" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="rgba(255,255,255,0.12)" stroke-width="2"/>
                        <path d="M12 3a9 9 0 0 1 9 9" stroke="rgba(255,255,255,0.45)" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>
            <div class="ddwpt-hist-footer">
                <a class="ddwpt-hist-footer-btn" href="<?php echo esc_url($data['simple_history_page_url']); ?>">View full</a>
                <a class="ddwpt-hist-footer-btn" href="<?php echo esc_url($data['simple_history_settings_url']); ?>">Settings</a>
            </div>
            <?php else : ?>
            <div class="ddwpt-hist-inactive">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Activity log requires the <a href="https://wordpress.org/plugins/simple-history/" target="_blank" rel="noopener">Simple History</a> plugin.
            </div>
            <?php endif; ?>
        </div><!-- /#ddwpt-pane-history -->

        <!-- SEO pane (dummy data — SEOPress integration pending) -->
        <div class="ddwpt-pane" id="ddwpt-pane-seo">
            <div class="ddwpt-seo-score-row">
                <div class="ddwpt-seo-score-ring">
                    <svg viewBox="0 0 44 44">
                        <circle cx="22" cy="22" r="18" fill="none" stroke="rgba(255,255,255,0.07)" stroke-width="4"/>
                        <circle cx="22" cy="22" r="18" fill="none" stroke="#4ade80" stroke-width="4"
                            stroke-dasharray="113" stroke-dashoffset="28"
                            stroke-linecap="round" transform="rotate(-90 22 22)"/>
                        <text x="22" y="26" text-anchor="middle" font-size="11" font-weight="500" fill="#fff">75</text>
                    </svg>
                </div>
                <div class="ddwpt-seo-score-info">
                    <div class="ddwpt-seo-score-label">Current page</div>
                    <div class="ddwpt-seo-score-title">Commercial Pool Heating</div>
                    <div class="ddwpt-seo-score-url">/commercial-pool-heating/</div>
                </div>
            </div>
            <div class="ddwpt-seo-checks">
                <div class="ddwpt-seo-check ddwpt-pass">
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Title tag set
                </div>
                <div class="ddwpt-seo-check ddwpt-pass">
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Meta description set
                </div>
                <div class="ddwpt-seo-check ddwpt-warn">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>No focus keyword set
                </div>
                <div class="ddwpt-seo-check ddwpt-fail">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>No canonical URL
                </div>
            </div>
            <div class="ddwpt-status-row">
                <span><span class="ddwpt-sdot" style="background:#facc15;"></span>SEOPress integration coming soon</span>
            </div>
        </div><!-- /#ddwpt-pane-seo -->

    </div><!-- /.ddwpt-panel -->

    <!-- Trigger row -->
    <div class="ddwpt-trigger-row" id="ddwpt-trigger-row">
        <a class="ddwpt-dash-btn" href="<?php echo esc_url($data['admin_url']); ?>" title="Dashboard">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
        </a>
        <button class="ddwpt-trigger" id="ddwpt-trigger" type="button" aria-label="WP Toolkit panel">
            <span class="ddwpt-wp-logo">
                <?php if ($data['site_icon_url']) : ?>
                <img src="<?php echo esc_url($data['site_icon_url']); ?>" alt="" aria-hidden="true">
                <?php else : ?>W<?php endif; ?>
            </span>
            <span class="ddwpt-env-badge"><?php echo esc_html($data['site_name']); ?></span>
            <svg class="ddwpt-chevron" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
        </button>
    </div>

    </div><!-- /#ddwpt-fp-root -->
    <?php
}

return [
    'id'    => 'ddwpt_floating_admin_panel',
    'label' => 'Floating Admin Panel',
    'tab'   => 'admin-bar',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Show a floating panel on the frontend as an alternative to the WordPress admin bar.',
        ],
        [
            'id'          => 'hide_native_bar',
            'type'        => 'checkbox',
            'label'       => 'Hide native admin bar',
            'description' => 'Hide the WordPress admin bar when the floating panel is shown.',
            'default'     => '1',
        ],
        [
            'id'          => 'open_on_hover',
            'type'        => 'checkbox',
            'label'       => 'Open on hover',
            'description' => 'Open the panel on hover rather than requiring a click.',
            'default'     => '1',
        ],
        [
            'id'          => 'min_capability',
            'type'        => 'select',
            'label'       => 'Show to',
            'description' => 'Only show the panel to users with this capability.',
            'default'     => 'edit_posts',
            'options'     => [
                'read'           => 'All logged-in users',
                'edit_posts'     => 'Authors and above',
                'manage_options' => 'Administrators only',
            ],
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $min_cap       = $settings['min_capability'] ?? 'edit_posts';
        $hide_bar      = !empty($settings['hide_native_bar']);
        $open_on_hover = !empty($settings['open_on_hover']);

        // Hide the native bar via CSS, keeping the bar object alive so plugins
        // still register their admin_bar_menu nodes (which we read for chips).
        if ($hide_bar) {
            add_action('wp_head', function () use ($min_cap) {
                if (!is_user_logged_in() || !current_user_can($min_cap)) {
                    return;
                }
                echo '<style>#wpadminbar{display:none!important}html{margin-top:0!important}*html body{margin-top:0!important}</style>';
            }, 100);
        }

        add_action('wp_enqueue_scripts', function () use ($min_cap) {
            if (!is_user_logged_in() || !current_user_can($min_cap)) {
                return;
            }

            wp_enqueue_style(
                'ddwpt-floating-panel',
                DDWPT_URL . 'assets/css/floating-panel.css',
                [],
                DDWPT_VERSION
            );
            wp_enqueue_script(
                'ddwpt-floating-panel',
                DDWPT_URL . 'assets/js/floating-panel.js',
                [],
                DDWPT_VERSION,
                true
            );

            // Defer execution until after the full DOM is parsed — the panel HTML
            // is output at wp_footer priority 1001, after the script tag at priority 20.
            add_filter('script_loader_tag', function ($tag, $handle) {
                if ($handle === 'ddwpt-floating-panel') {
                    return str_replace(' src=', ' defer src=', $tag);
                }
                return $tag;
            }, 10, 2);
        });

        // Output the JS config before footer scripts (priority 20) so the deferred
        // script can read it once the DOM is ready.
        add_action('wp_footer', function () use ($min_cap, $open_on_hover) {
            if (!is_user_logged_in() || !current_user_can($min_cap)) {
                return;
            }
            $js = 'window.ddwptPanelOpenOnHover=' . ($open_on_hover ? 'true' : 'false') . ';';
            if (class_exists('\Simple_History\Simple_History')) {
                $js .= 'window.ddwptHistoryConfig=' . wp_json_encode([
                    'nonce'  => wp_create_nonce('wp_rest'),
                    'apiUrl' => rest_url('simple-history/v1/events'),
                    'postId' => is_singular() ? (int) get_queried_object_id() : 0,
                ]) . ';';
            }
            echo '<script>' . $js . '</script>';
        }, 5);

        // Reflection bypasses WP_Admin_Bar's bound check, so timing relative to
        // wp_admin_bar_render (priority 1000) no longer matters.
        add_action('wp_footer', function () use ($min_cap) {
            if (!is_user_logged_in() || !current_user_can($min_cap)) {
                return;
            }
            $data      = \DDWPTweaks\Tweaks\floating_panel_collect_data();
            $chips     = \DDWPTweaks\Tweaks\floating_panel_collect_chips();
            $new_items = \DDWPTweaks\Tweaks\floating_panel_collect_new_items();
            \DDWPTweaks\Tweaks\floating_panel_render($data, $chips, $new_items);
        }, 1001);
    },
];

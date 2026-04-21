<?php

namespace DDWPTweaks;

defined('ABSPATH') || exit;

class Knowledge_Base
{
    private string $dir;
    private string $mode;

    public function __construct(string $mode = 'sidebar')
    {
        $this->dir  = __DIR__ . '/knowledge/';
        $this->mode = $mode;

        add_action('admin_menu', [$this, 'register_menu']);

        if ($mode === 'dashboard') {
            add_action('load-index.php', [$this, 'setup_dashboard']);
        }
    }

    // ── Sidebar mode ─────────────────────────────────────────────────────────

    public function register_menu()
    {
        if ($this->mode === 'dashboard') {
            add_submenu_page(
                'index.php',
                'Knowledge Base',
                'Knowledge Base',
                'read',
                'ddwpt-knowledge',
                [$this, 'render_page']
            );
        } else {
            add_menu_page(
                'Knowledge Base',
                'Knowledge Base',
                'read',
                'ddwpt-knowledge',
                [$this, 'render_page'],
                'dashicons-book-alt',
                3
            );
        }
    }

    // ── Dashboard mode ────────────────────────────────────────────────────────

    public function setup_dashboard()
    {
        remove_action('welcome_panel', 'wp_welcome_panel');
        add_action('welcome_panel', [$this, 'render_dashboard_panel']);
        add_action('admin_head',    [$this, 'render_dashboard_styles']);
    }

    public function render_dashboard_styles()
    {
        ?>
        <style>
            /* Force the welcome panel to always show and hide the dismiss link */
            #welcome-panel,
            #welcome-panel.hidden { display: block !important; }
            .welcome-panel-close { display: none !important; }

            /* Override WP welcome panel defaults */
            #welcome-panel { background-color: transparent !important; overflow: visible !important; margin: 0 !important; font-size: inherit !important; line-height: inherit !important; }

            /* ── Banner ── */
            #ddwpt-dashboard { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
            #ddwpt-banner { background: linear-gradient(135deg, #1d2327 0%, #2c3338 100%); border-radius: 8px; padding: 32px 40px; margin-bottom: 28px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
            #ddwpt-banner-text {}
            #ddwpt-banner h2 { color: #fff; font-size: 22px; margin: 0 0 6px; font-weight: 600; }
            #ddwpt-banner p { color: rgba(255,255,255,.65); margin: 0; font-size: 14px; line-height: 1.6; }
            #ddwpt-banner-logo { flex-shrink: 0; }
            #ddwpt-banner-logo svg { display: block; }

            /* ── KB section ── */
            #ddwpt-kb-section h3 { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #50575e; margin: 0 0 14px; }

            /* ── Hub grid ── */
            #ddwpt-kb-hub {}
            .ddwpt-db-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
            .ddwpt-db-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 18px 20px; text-decoration: none; color: inherit; transition: border-color .15s, box-shadow .15s; display: flex; flex-direction: column; }
            .ddwpt-db-card:hover { border-color: #2271b1; box-shadow: 0 2px 10px rgba(0,0,0,.07); color: inherit; }
            .ddwpt-db-card-title { font-size: 14px; font-weight: 600; color: #1d2327; margin: 0 0 6px; }
            .ddwpt-db-card-excerpt { font-size: 12px; color: #50575e; margin: 0 0 12px; line-height: 1.55; flex: 1; }
            .ddwpt-db-card-cta { font-size: 12px; color: #2271b1; font-weight: 500; }
            .ddwpt-db-card:hover .ddwpt-db-card-cta { text-decoration: underline; }
        </style>
        <?php
    }

    public function render_dashboard_panel()
    {
        $docs      = $this->get_docs();
        $site_name = get_bloginfo('name') ?: get_bloginfo('url');
        $user      = wp_get_current_user();
        $greeting  = $user->first_name ? 'Welcome back, ' . esc_html($user->first_name) . '.' : 'Welcome back.';
        ?>
        <div id="ddwpt-dashboard">

            <div id="ddwpt-banner">
                <div id="ddwpt-banner-text">
                    <h2><?php echo esc_html($greeting); ?></h2>
                    <p>You're managing <strong style="color:#fff;"><?php echo esc_html($site_name); ?></strong>. Use the articles below if you need a hand with anything.</p>
                </div>
                <?php $favicon = get_site_icon_url(64); ?>
                <?php if ($favicon): ?>
                <div id="ddwpt-banner-logo" aria-hidden="true">
                    <img src="<?php echo esc_url($favicon); ?>" width="64" height="64" alt="" style="border-radius: 8px;" />
                </div>
                <?php endif; ?>
            </div>

            <?php if ($docs): ?>
            <div id="ddwpt-kb-section">
                <h3>Knowledge Base</h3>
                <div class="ddwpt-db-grid">
                    <?php foreach ($docs as $slug => $title):
                        $url     = admin_url('admin.php?page=ddwpt-knowledge&doc=' . $slug);
                        $excerpt = $this->get_excerpt($slug);
                    ?>
                        <a href="<?php echo esc_url($url); ?>" class="ddwpt-db-card">
                            <div class="ddwpt-db-card-title"><?php echo esc_html($title); ?></div>
                            <?php if ($excerpt): ?>
                                <div class="ddwpt-db-card-excerpt"><?php echo esc_html($excerpt); ?></div>
                            <?php endif; ?>
                            <div class="ddwpt-db-card-cta">Read article →</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    // ── Sidebar page rendering ────────────────────────────────────────────────

    public function render_page()
    {
        $docs    = $this->get_docs();
        $current = isset($_GET['doc']) ? sanitize_key($_GET['doc']) : null;

        if ($current && !isset($docs[$current])) {
            $current = null;
        }

        $raw_content = '';
        if ($current) {
            $file = $this->dir . $current . '.md';
            if (file_exists($file)) {
                $raw_content = file_get_contents($file);
            }
        }

        $hub_url = admin_url('admin.php?page=ddwpt-knowledge');

        $this->render_styles();

        if ($current && $raw_content) {
            $this->render_article($docs, $current, $raw_content, $hub_url);
        } else {
            $this->render_hub($docs, $hub_url);
        }
    }

    private function render_hub(array $docs, string $hub_url)
    {
        $count = count($docs);
        ?>
        <div class="wrap ddwpt-kb-wrap">
            <div class="ddwpt-kb-header">
                <div>
                    <h1 class="ddwpt-kb-title">Knowledge Base</h1>
                    <p class="ddwpt-kb-subtitle"><?php echo esc_html($count); ?> article<?php echo $count !== 1 ? 's' : ''; ?> available</p>
                </div>
            </div>

            <div class="ddwpt-kb-grid">
                <?php foreach ($docs as $slug => $title):
                    $excerpt = $this->get_excerpt($slug);
                    $url     = admin_url('admin.php?page=ddwpt-knowledge&doc=' . $slug);
                ?>
                    <a href="<?php echo esc_url($url); ?>" class="ddwpt-kb-card">
                        <div class="ddwpt-kb-card-icon">
                            <span class="dashicons dashicons-media-text"></span>
                        </div>
                        <h3 class="ddwpt-kb-card-title"><?php echo esc_html($title); ?></h3>
                        <?php if ($excerpt): ?>
                            <p class="ddwpt-kb-card-excerpt"><?php echo esc_html($excerpt); ?></p>
                        <?php endif; ?>
                        <span class="ddwpt-kb-card-read">Read article <span aria-hidden="true">→</span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_article(array $docs, string $current, string $raw_content, string $hub_url)
    {
        ?>
        <div class="wrap ddwpt-kb-wrap">
            <div class="ddwpt-kb-article-layout">

                <nav class="ddwpt-kb-nav">
                    <a href="<?php echo esc_url($hub_url); ?>" class="ddwpt-kb-nav-back">
                        <span class="dashicons dashicons-arrow-left-alt2"></span> All articles
                    </a>
                    <div class="ddwpt-kb-nav-list">
                        <?php foreach ($docs as $slug => $title): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=ddwpt-knowledge&doc=' . $slug)); ?>"
                               class="ddwpt-kb-nav-item <?php echo $slug === $current ? 'is-active' : ''; ?>">
                                <?php echo esc_html($title); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </nav>

                <div class="ddwpt-kb-content">
                    <div class="ddwpt-kb-body" id="ddwpt-kb-body"></div>
                    <script id="ddwpt-kb-raw" type="text/plain"><?php echo wp_kses_post($raw_content); ?></script>
                    <script src="https://unpkg.com/showdown/dist/showdown.min.js"></script>
                    <script>
                        (function () {
                            var raw = document.getElementById('ddwpt-kb-raw').textContent;
                            var converter = new showdown.Converter({ tables: true, strikethrough: true, ghCodeBlocks: true });
                            document.getElementById('ddwpt-kb-body').innerHTML = converter.makeHtml(raw);
                        })();
                    </script>
                </div>

            </div>
        </div>
        <?php
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function get_docs(): array
    {
        $files = glob($this->dir . '*.md') ?: [];
        $docs  = [];

        foreach ($files as $file) {
            $slug        = basename($file, '.md');
            $docs[$slug] = ucwords(str_replace('-', ' ', $slug));
        }

        return $docs;
    }

    private function get_excerpt(string $slug): string
    {
        $file = $this->dir . $slug . '.md';
        if (!file_exists($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6}\s|---|===|>|[-*+]\s|\d+\.\s|```)/', $line)) {
                continue;
            }

            $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $line);
            $text = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $text);
            $text = preg_replace('/(\*|_)(.*?)\1/', '$2', $text);
            $text = preg_replace('/`[^`]+`/', '', $text);
            $text = trim($text);

            if (strlen($text) > 20) {
                return mb_strimwidth($text, 0, 130, '…');
            }
        }

        return '';
    }

    private function render_styles()
    {
        ?>
        <style>
            /* ── Shared ── */
            .ddwpt-kb-wrap { max-width: 1200px; padding-top: 24px; }

            /* ── Hub header ── */
            .ddwpt-kb-header { margin-bottom: 28px; }
            .ddwpt-kb-title { font-size: 26px; margin: 0 0 4px; }
            .ddwpt-kb-subtitle { color: #50575e; margin: 0; font-size: 14px; }

            /* ── Card grid ── */
            .ddwpt-kb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
            .ddwpt-kb-card { display: flex; flex-direction: column; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 24px; text-decoration: none; color: inherit; transition: border-color .15s, box-shadow .15s; }
            .ddwpt-kb-card:hover { border-color: #2271b1; box-shadow: 0 2px 12px rgba(0,0,0,.08); color: inherit; }
            .ddwpt-kb-card-icon { width: 36px; height: 36px; background: #f0f6fc; border-radius: 6px; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; }
            .ddwpt-kb-card-icon .dashicons { color: #2271b1; font-size: 18px; width: 18px; height: 18px; }
            .ddwpt-kb-card-title { font-size: 15px; font-weight: 600; margin: 0 0 8px; color: #1d2327; }
            .ddwpt-kb-card-excerpt { font-size: 13px; color: #50575e; margin: 0 0 16px; line-height: 1.6; flex: 1; }
            .ddwpt-kb-card-read { font-size: 13px; color: #2271b1; font-weight: 500; margin-top: auto; }
            .ddwpt-kb-card:hover .ddwpt-kb-card-read { text-decoration: underline; }

            /* ── Article layout ── */
            .ddwpt-kb-article-layout { display: flex; gap: 0; align-items: flex-start; }

            /* ── Article sidebar nav ── */
            .ddwpt-kb-nav { width: 220px; flex-shrink: 0; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; overflow: hidden; position: sticky; top: 32px; }
            .ddwpt-kb-nav-back { display: flex; align-items: center; gap: 4px; padding: 11px 14px; font-size: 13px; font-weight: 600; color: #2271b1; text-decoration: none; border-bottom: 1px solid #c3c4c7; }
            .ddwpt-kb-nav-back:hover { background: #f0f6fc; color: #2271b1; }
            .ddwpt-kb-nav-back .dashicons { font-size: 14px; width: 14px; height: 14px; margin-top: 1px; }
            .ddwpt-kb-nav-list { padding: 6px 0; }
            .ddwpt-kb-nav-item { display: block; padding: 7px 14px; color: #1d2327; text-decoration: none; font-size: 13px; border-left: 3px solid transparent; }
            .ddwpt-kb-nav-item:hover { background: #f6f7f7; color: #2271b1; }
            .ddwpt-kb-nav-item.is-active { border-left-color: #2271b1; color: #2271b1; background: #f0f6fc; font-weight: 600; }

            /* ── Article content ── */
            .ddwpt-kb-content { flex: 1; min-width: 0; margin-left: 24px; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 36px 44px; }
            .ddwpt-kb-body h1 { font-size: 24px; margin-top: 0; }
            .ddwpt-kb-body h2 { font-size: 18px; border-bottom: 1px solid #e0e0e0; padding-bottom: 6px; }
            .ddwpt-kb-body h3 { font-size: 15px; }
            .ddwpt-kb-body table { border-collapse: collapse; width: 100%; margin: 1em 0; }
            .ddwpt-kb-body th, .ddwpt-kb-body td { border: 1px solid #c3c4c7; padding: 8px 12px; font-size: 13px; }
            .ddwpt-kb-body th { background: #f6f7f7; font-weight: 600; }
            .ddwpt-kb-body blockquote { border-left: 4px solid #2271b1; margin: 1em 0; padding: 8px 16px; background: #f0f6fc; color: #1d2327; }
            .ddwpt-kb-body blockquote p { margin: 0; }
            .ddwpt-kb-body code { background: #f6f7f7; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
            .ddwpt-kb-body pre { background: #1d2327; color: #f6f7f7; padding: 16px; border-radius: 4px; overflow-x: auto; }
            .ddwpt-kb-body pre code { background: none; color: inherit; padding: 0; }
            .ddwpt-kb-body hr { border: none; border-top: 1px solid #e0e0e0; margin: 2em 0; }
            .ddwpt-kb-body ol, .ddwpt-kb-body ul { padding-left: 1.5em; }
            .ddwpt-kb-body li { margin-bottom: 4px; }
            .ddwpt-kb-body a { color: #2271b1; }
        </style>
        <?php
    }
}

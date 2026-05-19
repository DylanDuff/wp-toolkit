<?php

namespace DDWPTweaks;

defined("ABSPATH") || exit();

class Knowledge_Base
{
  private string $dir;
  private string $mode;

  public function __construct(string $mode = "sidebar")
  {
    $this->dir = __DIR__ . "/knowledge/";
    $this->mode = $mode;

    add_action("admin_menu", [$this, "register_menu"]);

    if ($mode === "dashboard") {
      add_action("load-index.php", [$this, "setup_dashboard"]);
    }
  }

  // ── Sidebar mode ─────────────────────────────────────────────────────────

  public function register_menu()
  {
    if ($this->mode === "dashboard") {
      add_submenu_page(
        "index.php",
        "Knowledge Base",
        "Knowledge Base",
        "read",
        "ddwpt-knowledge",
        [$this, "render_page"],
      );
    } else {
      add_menu_page(
        "Knowledge Base",
        "Knowledge Base",
        "read",
        "ddwpt-knowledge",
        [$this, "render_page"],
        "dashicons-book-alt",
        3,
      );
    }
  }

  // ── Dashboard mode ────────────────────────────────────────────────────────

  public function setup_dashboard()
  {
    add_action("admin_notices", [$this, "render_dashboard_panel"], 1);
    add_action("admin_head", [$this, "render_dashboard_styles"]);
  }

  public function render_dashboard_styles()
  {
    ?>
        <style>
            #ddwpt-dashboard { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin-bottom: 20px; }
            #ddwpt-banner { background: linear-gradient(135deg, #1d2327 0%, #2c3338 100%);  width: calc(100% - 100px); border-radius: 8px; padding: 32px 40px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
            #ddwpt-banner h2 { color: #fff; font-size: 22px; margin: 0 0 6px; font-weight: 600; }
            #ddwpt-banner p { color: rgba(255,255,255,.65); margin: 0; font-size: 14px; line-height: 1.6; }
            #ddwpt-banner-logo { flex-shrink: 0; }
        </style>
        <?php
  }

  public function render_dashboard_panel()
  {
    $site_name = get_bloginfo("name") ?: get_bloginfo("url");
    $user = wp_get_current_user();
    $greeting = $user->first_name
      ? "Welcome back, " . esc_html($user->first_name) . "."
      : "Welcome back.";
    $kb_url = admin_url("admin.php?page=ddwpt-knowledge");
    ?>
        <div id="ddwpt-dashboard">

            <div id="ddwpt-banner">
                <div id="ddwpt-banner-text">
                    <h2><?php echo esc_html($greeting); ?></h2>
                    <p>You're managing <strong style="color:#fff;"><?php echo esc_html(
                      $site_name,
                    ); ?></strong>. Need help with something? <a href="<?php echo esc_url(
  $kb_url,
); ?>" style="color:rgba(255,255,255,.75);text-decoration:underline;">Browse the knowledge base</a>.</p>
                </div>
                <?php $favicon = get_site_icon_url(64); ?>
                <?php if ($favicon): ?>
                <div id="ddwpt-banner-logo" aria-hidden="true">
                    <img src="<?php echo esc_url(
                      $favicon,
                    ); ?>" width="64" height="64" alt="" style="border-radius: 8px;" />
                </div>
                <?php endif; ?>
            </div>

        </div>
        <?php
  }

  // ── Sidebar page rendering ────────────────────────────────────────────────

  public function render_page()
  {
    $docs = $this->get_docs();
    $current = isset($_GET["doc"]) ? sanitize_key($_GET["doc"]) : null;

    if ($current && !isset($docs[$current])) {
      $current = null;
    }

    $raw_content = "";
    if ($current) {
      $file = $this->dir . $current . ".md";
      if (file_exists($file)) {
        $raw_content = file_get_contents($file);
      }
    }

    $hub_url = admin_url("admin.php?page=ddwpt-knowledge");

    $this->render_styles();

    if ($current && $raw_content) {
      $this->render_article(
        $this->get_groups(),
        $current,
        $raw_content,
        $hub_url,
      );
    } else {
      $this->render_hub($this->get_groups(), $hub_url);
    }
  }

  private function render_hub(array $groups, string $hub_url)
  {
    $count = count($this->get_docs());
    $first = true;
    ?>
        <div class="wrap ddwpt-kb-wrap ddwpt-kb-hub">
            <div class="ddwpt-kb-header">
                <div>
                    <h1 class="ddwpt-kb-title">Knowledge Base</h1>
                    <p class="ddwpt-kb-subtitle"><?php echo esc_html(
                      $count,
                    ); ?> article<?php echo $count !== 1 ? "s" : ""; ?> available</p>
                </div>
            </div>

            <?php foreach ($groups as $group_name => $docs):

              $open = $first;
              $first = false;
              $id = "ddwpt-group-" . sanitize_title($group_name);
              ?>
            <div class="ddwpt-kb-accordion <?php echo $open
              ? "is-open"
              : ""; ?>">
                <button class="ddwpt-kb-accordion-trigger" aria-expanded="<?php echo $open
                  ? "true"
                  : "false"; ?>" aria-controls="<?php echo esc_attr($id); ?>">
                    <span><?php echo esc_html($group_name); ?></span>
                    <svg class="ddwpt-kb-accordion-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="ddwpt-kb-accordion-body" id="<?php echo esc_attr(
                  $id,
                ); ?>">
                    <div class="ddwpt-kb-grid">
                        <?php foreach ($docs as $slug => $item):

                          $excerpt = $this->get_excerpt($slug);
                          $url = admin_url(
                            "admin.php?page=ddwpt-knowledge&doc=" . $slug,
                          );
                          ?>
                            <a href="<?php echo esc_url(
                              $url,
                            ); ?>" class="ddwpt-kb-card">
                                <div class="ddwpt-kb-card-icon">
                                    <span class="dashicons <?php echo esc_attr(
                                      $item["icon"],
                                    ); ?>"></span>
                                </div>
                                <div class="ddwpt-kb-card-text">
                                    <h3 class="ddwpt-kb-card-title"><?php echo esc_html(
                                      $item["title"],
                                    ); ?></h3>
                                    <?php if ($excerpt): ?>
                                        <p class="ddwpt-kb-card-excerpt"><?php echo esc_html(
                                          $excerpt,
                                        ); ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="ddwpt-kb-card-read">Read article <span aria-hidden="true">→</span></span>
                            </a>
                        <?php
                        endforeach; ?>
                    </div>
                </div>
            </div>
            <?php
            endforeach; ?>
        </div>
        <script>
            document.querySelectorAll('.ddwpt-kb-accordion-trigger').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var accordion = btn.closest('.ddwpt-kb-accordion');
                    var isOpen = accordion.classList.contains('is-open');
                    accordion.classList.toggle('is-open', !isOpen);
                    btn.setAttribute('aria-expanded', !isOpen);
                });
            });
        </script>
        <?php
  }

  private function render_article(
    array $groups,
    string $current,
    string $raw_content,
    string $hub_url,
  ) {
    ?>
        <div class="wrap ddwpt-kb-wrap">
            <div class="ddwpt-kb-article-layout">

                <nav class="ddwpt-kb-nav">
                    <a href="<?php echo esc_url(
                      $hub_url,
                    ); ?>" class="ddwpt-kb-nav-back">
                        <span class="dashicons dashicons-arrow-left-alt2"></span> All articles
                    </a>
                    <div class="ddwpt-kb-nav-list">
                        <?php foreach ($groups as $group_name => $docs): ?>
                            <div class="ddwpt-kb-nav-group-title"><?php echo esc_html(
                              $group_name,
                            ); ?></div>
                            <?php foreach ($docs as $slug => $item): ?>
                                <a href="<?php echo esc_url(
                                  admin_url(
                                    "admin.php?page=ddwpt-knowledge&doc=" .
                                      $slug,
                                  ),
                                ); ?>"
                                   class="ddwpt-kb-nav-item <?php echo $slug ===
                                   $current
                                     ? "is-active"
                                     : ""; ?>">
                                    <?php echo esc_html($item["title"]); ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </nav>

                <div class="ddwpt-kb-content">
                    <div class="ddwpt-kb-body" id="ddwpt-kb-body"></div>
                    <script src="https://unpkg.com/showdown/dist/showdown.min.js"></script>
                    <script>
                        (function () {
                            var raw = <?php echo wp_json_encode(
                              $raw_content,
                            ); ?>;
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

  private function get_groups(): array
  {
    $manifest = $this->dir . "manifest.php";

    if (file_exists($manifest)) {
      $raw = require $manifest;
      $groups = [];

      foreach ($raw as $group_name => $slugs) {
        foreach ($slugs as $slug => $icon) {
          if (file_exists($this->dir . $slug . ".md")) {
            $title_slug = preg_replace("/^\d+-/", "", $slug);
            $groups[$group_name][$slug] = [
              "title" => ucwords(str_replace("-", " ", $title_slug)),
              "icon" => $icon ?: "dashicons-media-text",
            ];
          }
        }
      }

      return $groups;
    }

    // Fallback: single group from glob
    $docs = [];
    foreach (glob($this->dir . "*.md") ?: [] as $file) {
      $slug = basename($file, ".md");
      $title_slug = preg_replace("/^\d+-/", "", $slug);
      $docs[$slug] = [
        "title" => ucwords(str_replace("-", " ", $title_slug)),
        "icon" => "dashicons-media-text",
      ];
    }

    return $docs ? ["Articles" => $docs] : [];
  }

  private function get_docs(): array
  {
    $docs = [];
    foreach ($this->get_groups() as $group_docs) {
      foreach ($group_docs as $slug => $item) {
        $docs[$slug] = $item["title"];
      }
    }
    return $docs;
  }

  private function get_excerpt(string $slug): string
  {
    $file = $this->dir . $slug . ".md";
    if (!file_exists($file)) {
      return "";
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
      if (preg_match("/^(#{1,6}\s|---|===|>|[-*+]\s|\d+\.\s|```)/", $line)) {
        continue;
      }

      $text = preg_replace("/!?\[([^\]]*)\]\([^)]*\)/", '$1', $line);
      $text = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $text);
      $text = preg_replace('/(\*|_)(.*?)\1/', '$2', $text);
      $text = preg_replace("/`[^`]+`/", "", $text);
      $text = trim($text);

      if (strlen($text) > 20) {
        return mb_strimwidth($text, 0, 130, "…");
      }
    }

    return "";
  }

  private function render_styles()
  {
    ?>
        <style>
            /* ── Shared ── */
            .ddwpt-kb-wrap { max-width: 1200px; padding-top: 24px; }
            .ddwpt-kb-hub { max-width: 800px; margin-inline: auto; }

            /* ── Hub header ── */
            .ddwpt-kb-header { margin-bottom: 28px; }
            .ddwpt-kb-title { font-size: 26px; margin: 0 0 4px; }
            .ddwpt-kb-subtitle { color: #50575e; margin: 0; font-size: 14px; }
            .ddwpt-kb-hub .ddwpt-kb-title,
            .ddwpt-kb-hub .ddwpt-kb-subtitle { text-align: center; }

            /* ── Accordion groups ── */
            .ddwpt-kb-accordion { border: 1px solid #c3c4c7; border-radius: 6px; margin-bottom: 8px; background: #fff; overflow: hidden; }
            .ddwpt-kb-accordion-trigger { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 14px 18px; background: none; border: none; cursor: pointer; font-size: 13px; font-weight: 600; color: #1d2327; text-align: left; gap: 8px; }
            .ddwpt-kb-accordion-trigger:hover { background: #f6f7f7; }
            .ddwpt-kb-accordion-icon { width: 16px; height: 16px; flex-shrink: 0; color: #50575e; transition: transform 0.2s ease; }
            .ddwpt-kb-accordion.is-open .ddwpt-kb-accordion-icon { transform: rotate(180deg); }
            .ddwpt-kb-accordion { background: #f6f7f7; }
            .ddwpt-kb-accordion-trigger { background: #f6f7f7; }
            .ddwpt-kb-accordion-trigger:hover { background: #f0f0f1; }
            .ddwpt-kb-accordion-body { display: none; padding: 12px 18px 18px; background: transparent; }
            .ddwpt-kb-accordion.is-open .ddwpt-kb-accordion-body { display: block; }
            .ddwpt-kb-accordion-body .ddwpt-kb-grid { display: flex; flex-direction: column; gap: 8px; }
            .ddwpt-kb-accordion-body .ddwpt-kb-card { flex-direction: row; align-items: center; padding: 12px 14px; background: #fff; gap: 12px; }
            .ddwpt-kb-accordion-body .ddwpt-kb-card:hover { background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
            .ddwpt-kb-accordion-body .ddwpt-kb-card-icon { margin-bottom: 0; flex-shrink: 0; }
            .ddwpt-kb-accordion-body .ddwpt-kb-card-text { flex: 1; min-width: 0; flex-direction: column; }
            .ddwpt-kb-accordion-body .ddwpt-kb-card-title { font-size: 13px; margin: 0 0 2px; }
            .ddwpt-kb-accordion-body .ddwpt-kb-card-excerpt { font-size: 12px; margin: 0; color: #9ca3af; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: unset; }
            .ddwpt-kb-accordion-body .ddwpt-kb-card-read { display: none; }

            /* ── Card grid ── */
            .ddwpt-kb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
            .ddwpt-kb-card { display: flex; flex-direction: column; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 24px; text-decoration: none; color: inherit; transition: border-color .15s, box-shadow .15s; }
            .ddwpt-kb-card:hover { border-color: #2271b1; box-shadow: 0 2px 12px rgba(0,0,0,.08); color: inherit; }
            .ddwpt-kb-card-icon { width: 36px; height: 36px; background: #f0f6fc; border-radius: 6px; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; flex-shrink: 0; }
            .ddwpt-kb-card-icon .dashicons { color: #2271b1; font-size: 18px; width: 18px; height: 18px; }
            .ddwpt-kb-card-text { flex: 1; display: flex; flex-direction: column; }
            .ddwpt-kb-card-title { font-size: 15px; font-weight: 600; margin: 0 0 8px; color: #1d2327; }
            .ddwpt-kb-card-excerpt { font-size: 13px; color: #50575e; margin: 0 0 16px; line-height: 1.6; flex: 1; }
            .ddwpt-kb-card-read { font-size: 13px; color: #2271b1; font-weight: 500; }
            .ddwpt-kb-card:hover .ddwpt-kb-card-read { text-decoration: underline; }

            /* ── Article layout ── */
            .ddwpt-kb-article-layout { display: flex; gap: 0; align-items: flex-start; }

            /* ── Article sidebar nav ── */
            .ddwpt-kb-nav { width: 220px; flex-shrink: 0; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; overflow: hidden; position: sticky; top: 32px; max-height: calc(100vh - 60px); overflow-y: auto; }
            .ddwpt-kb-nav-back { display: flex; align-items: center; gap: 4px; padding: 11px 14px; font-size: 13px; font-weight: 600; color: #2271b1; text-decoration: none; border-bottom: 1px solid #c3c4c7; }
            .ddwpt-kb-nav-back:hover { background: #f0f6fc; color: #2271b1; }
            .ddwpt-kb-nav-back .dashicons { font-size: 14px; width: 14px; height: 14px; margin-top: 1px; }
            .ddwpt-kb-nav-list { padding: 6px 0; }
            .ddwpt-kb-nav-group-title { padding: 10px 14px 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; }
            .ddwpt-kb-nav-item { display: block; padding: 6px 14px; color: #1d2327; text-decoration: none; font-size: 13px; border-left: 3px solid transparent; }
            .ddwpt-kb-nav-item:hover { background: #f6f7f7; color: #2271b1; }
            .ddwpt-kb-nav-item.is-active { border-left-color: #2271b1; color: #2271b1; background: #f0f6fc; font-weight: 600; }

            /* ── Article content ── */
            .ddwpt-kb-content { flex: 1; min-width: 0; margin-left: 24px; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 36px 44px; }
            .ddwpt-kb-body h1 { font-size: 24px; margin-top: 0; }
            .ddwpt-kb-body h2 { font-size: 18px; padding-bottom: 6px; }
            .ddwpt-kb-body h3 { font-size: 15px; }
            .ddwpt-kb-body table { border-collapse: collapse; width: 100%; margin: 1em 0; }
            .ddwpt-kb-body th, .ddwpt-kb-body td { border: 1px solid #c3c4c7; padding: 8px 12px; font-size: 13px; }
            .ddwpt-kb-body th { background: #f6f7f7; font-weight: 600; }
            .ddwpt-kb-body blockquote { border: 1px solid #f0c060; border-radius: 6px; margin: 1.25em 0; padding: 10px 14px; background: #fffbeb; color: #1d2327; }
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

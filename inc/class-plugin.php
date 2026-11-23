<?php

namespace DDWPTweaks;

defined("ABSPATH") || exit();

class Plugin
{
    private $tweaks = [];
    private $settings_group = "ddwptweaks_options";
    private $page_hook = "tools_page_ddwptweaks";

    public function __construct(array $tweaks)
    {
        $this->tweaks = $tweaks;

        add_action("admin_menu",            [$this, "register_menu"]);
        add_action("admin_init",            [$this, "register_settings"]);
        add_action("admin_enqueue_scripts", [$this, "enqueue_assets"]);
        add_filter("admin_body_class",      [$this, "add_body_class"]);
        add_action("tool_box",              [$this, "render_toolbox_card"]);
        add_action("wp_ajax_ddwpt_export_settings", [$this, "export_settings"]);
        add_action("wp_ajax_ddwpt_import_settings", [$this, "import_settings"]);
    }

    public function register_menu()
    {
        add_management_page(
            "WP Toolkit",
            "WP Toolkit",
            "manage_options",
            "ddwptweaks",
            [$this, "render_settings_page"],
        );
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== $this->page_hook) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script("jquery-ui-sortable");

        wp_enqueue_style(
            "ddwpt-settings",
            DDWPT_URL . "assets/css/settings.css",
            [],
            DDWPT_VERSION,
        );

        wp_enqueue_script(
            "ddwpt-settings",
            DDWPT_URL . "assets/js/settings.js",
            ["jquery", "jquery-ui-sortable"],
            DDWPT_VERSION,
            true,
        );

        $localize_data = apply_filters("ddwpt_localize_data", [
            "ajaxUrl"     => admin_url("admin-ajax.php"),
            "exportNonce" => wp_create_nonce("ddwpt_export"),
            "importNonce" => wp_create_nonce("ddwpt_import"),
        ]);
        wp_localize_script("ddwpt-settings", "ddwptSettings", $localize_data);
    }

    public function add_body_class($classes)
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === $this->page_hook) {
            $classes .= " ddwpt-page";
        }
        return $classes;
    }

    public function render_toolbox_card()
    {
        if (!current_user_can("manage_options")) {
            return;
        }

        $active_count = $this->get_active_tweak_count();
        $total_count  = count($this->tweaks);
        $url          = admin_url("tools.php?page=ddwptweaks");
        ?>
        <div class="card">
            <h2 class="title"><?php esc_html_e("WP Toolkit", "wp-toolkit"); ?></h2>
            <p><?php printf(
                esc_html__("Manage modular admin tweaks for this site. %d of %d tweaks currently active.", "wp-toolkit"),
                $active_count,
                $total_count,
            ); ?></p>
            <p><a href="<?php echo esc_url($url); ?>" class="button"><?php esc_html_e("Manage Tweaks", "wp-toolkit"); ?></a></p>
        </div>
        <?php
    }

    private function get_sanitize_map()
    {
        $sanitize_json_array = function ($val) {
            if (is_array($val)) $val = wp_json_encode($val);
            $decoded = json_decode($val, true);
            if (!is_array($decoded)) return "";
            return wp_json_encode(array_map("sanitize_text_field", $decoded));
        };

        return [
            "wysiwyg"     => "wp_kses_post",
            "textarea"    => "sanitize_textarea_field",
            "text"        => "sanitize_text_field",
            "url"         => function ($val) {
                $val = trim($val);
                if (preg_match('/^data:image\/(svg\+xml|png|jpeg|gif|webp|ico)(;base64)?,/', $val)) {
                    return $val;
                }
                return esc_url_raw($val);
            },
            "select"      => "sanitize_text_field",
            "checkbox"    => "absint",
            "color"       => "sanitize_hex_color",
            "media"       => "esc_url_raw",
            "multiselect" => $sanitize_json_array,
            "checkboxes"  => $sanitize_json_array,
            "sortable"    => function ($val) {
                if (is_array($val)) $val = wp_json_encode($val);
                $decoded = json_decode($val, true);
                if (!is_array($decoded)) return "";
                return wp_json_encode([
                    "order"  => array_map("sanitize_key", $decoded["order"]  ?? []),
                    "hidden" => array_map("sanitize_key", $decoded["hidden"] ?? []),
                ]);
            },
        ];
    }

    // A setting may bring its own `sanitize` callable (custom field types
    // registered by a tweak); otherwise its type decides.
    private function get_sanitizer(array $setting, array $sanitize_map)
    {
        if (!empty($setting["sanitize"]) && is_callable($setting["sanitize"])) {
            return $setting["sanitize"];
        }
        return $sanitize_map[$setting["type"]] ?? "sanitize_text_field";
    }

    public function register_settings()
    {
        $sanitize_map = $this->get_sanitize_map();

        $no_persist = ["faq_import", "acf_export", "service_area_import", "team_member_import", "testimonial_import", "location_import", "project_import", "acf_keys"];

        foreach ($this->tweaks as $tweak) {
            foreach ($tweak["settings"] ?? [] as $setting) {
                if (in_array($setting["type"], $no_persist, true)) continue;
                register_setting($this->settings_group, $setting["id"], [
                    "sanitize_callback" => $this->get_sanitizer($setting, $sanitize_map),
                ]);
            }
        }
    }

    public function export_settings()
    {
        check_ajax_referer("ddwpt_export", "nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error("Unauthorized");
        }

        $data = [];
        foreach ($this->tweaks as $tweak) {
            foreach ($tweak["settings"] ?? [] as $setting) {
                $data[$setting["id"]] = get_option($setting["id"], $setting["default"] ?? "");
            }
        }

        wp_send_json_success($data);
    }

    public function import_settings()
    {
        check_ajax_referer("ddwpt_import", "nonce");
        if (!current_user_can("manage_options")) {
            wp_send_json_error("Unauthorized");
        }

        $raw  = isset($_POST["settings"]) ? wp_unslash($_POST["settings"]) : "";
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            wp_send_json_error("Invalid data");
        }

        // Exports from older releases may still use renamed option keys.
        $data = Migrations::translate_keys($data);

        $sanitize_map   = $this->get_sanitize_map();
        $valid_settings = [];
        foreach ($this->tweaks as $tweak) {
            foreach ($tweak["settings"] ?? [] as $setting) {
                $valid_settings[$setting["id"]] = $setting;
            }
        }

        foreach ($data as $key => $value) {
            if (!isset($valid_settings[$key])) continue;
            $sanitize = $this->get_sanitizer($valid_settings[$key], $sanitize_map);
            update_option($key, call_user_func($sanitize, $value));
        }

        wp_send_json_success();
    }

    public function render_settings_page()
    {
        $areas      = Settings_Layout::areas($this->tweaks);
        $labs_on    = (bool) get_option("ddwpt_experiments_enabled");
        $wrap_class = "ddwpt-wrap" . ($labs_on ? " is-labs-on" : "");
        $has_any_settings = (bool) array_filter($this->tweaks, fn($t) => !isset($t["render"]));
        ?>
        <form method="post" action="options.php">
            <?php settings_fields($this->settings_group); ?>

            <div class="<?php echo esc_attr($wrap_class); ?>"<?php echo $this->white_label_style(); ?>>
                <div class="ddwpt-body">

                    <nav class="ddwpt-tabs-nav">
                        <div class="ddwpt-tabs-nav-sticky">
                            <div class="ddwpt-tabs-nav-scroll">
                                <button type="button" class="ddwpt-nav-search" data-palette-open>
                                    <?php echo $this->inline_icon("Search-Lucide.svg"); ?>
                                    <span><?php esc_html_e("Search", "wp-toolkit"); ?></span>
                                    <kbd>/</kbd>
                                </button>
                                <a href="#overview" class="ddwpt-tab" data-area="overview">
                                    <?php echo $this->inline_icon("House-Lucide.svg"); ?>
                                    <?php esc_html_e("Overview", "wp-toolkit"); ?>
                                </a>
                                <?php foreach ($areas as $area_id => $area): ?>
                                <?php if (!empty($area["utility"])): ?><div class="ddwpt-nav-utility"><?php endif; ?>
                                <a href="#<?php echo esc_attr($area_id); ?>" class="ddwpt-tab" data-area="<?php echo esc_attr($area_id); ?>">
                                    <?php echo $this->inline_icon($area["icon"]); ?>
                                    <?php echo esc_html($area["label"]); ?>
                                </a>
                                <?php if (!empty($area["utility"])): ?></div><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($has_any_settings): ?>
                            <div class="ddwpt-tabs-nav-actions">
                                <button type="submit" class="ddwpt-save-btn"><?php esc_html_e("Save Changes", "wp-toolkit"); ?></button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </nav>

                    <div class="ddwpt-content">
                        <?php $this->render_overview($areas); ?>
                        <?php foreach ($areas as $area_id => $area): ?>
                        <?php $this->render_area($area_id, $area); ?>
                        <?php endforeach; ?>
                    </div>

                </div>

                <div class="ddwpt-palette-backdrop" data-palette hidden>
                    <div class="ddwpt-palette" role="dialog" aria-label="<?php esc_attr_e("Search settings", "wp-toolkit"); ?>">
                        <input type="text" class="ddwpt-palette-input" placeholder="<?php esc_attr_e("Search tweaks, areas or plugins…", "wp-toolkit"); ?>" autocomplete="off" aria-label="<?php esc_attr_e("Search", "wp-toolkit"); ?>" />
                        <ul class="ddwpt-palette-results" role="listbox"></ul>
                        <div class="ddwpt-palette-hint"><?php esc_html_e("↑↓ to move, Enter to open, Esc to close", "wp-toolkit"); ?></div>
                    </div>
                </div>
            </div>
        </form>
        <?php
    }

    /** Inline CSS variables from the White Label settings, if enabled. */
    private function white_label_style(): string
    {
        if (!get_option("ddwpt_plugin_settings_enabled")) return "";

        $map = [
            "--ddwpt-hero-from"    => ["ddwpt_plugin_settings_gradient_from",  "sanitize_hex_color"],
            "--ddwpt-hero-to"      => ["ddwpt_plugin_settings_gradient_to",    "sanitize_hex_color"],
            "--ddwpt-hero-angle"   => ["ddwpt_plugin_settings_gradient_angle", "sanitize_text_field"],
            "--ddwpt-accent"       => ["ddwpt_plugin_settings_accent",         "sanitize_hex_color"],
            "--ddwpt-accent-hover" => ["ddwpt_plugin_settings_accent_hover",   "sanitize_hex_color"],
            "--ddwpt-accent-light" => ["ddwpt_plugin_settings_accent_light",   "sanitize_hex_color"],
        ];

        $vars = [];
        foreach ($map as $var => [$option, $sanitize]) {
            $value = get_option($option, "");
            if ($value) $vars[] = $var . ":" . call_user_func($sanitize, $value);
        }
        return $vars ? ' style="' . esc_attr(implode(";", $vars)) . '"' : "";
    }

    private function hero_icon(): string
    {
        $icon = get_option("ddwpt_plugin_settings_enabled") ? get_option("ddwpt_plugin_settings_icon", "") : "";
        return $icon ?: DDWPT_URL . "assets/icons/wptk-logo.svg";
    }

    private function render_overview(array $areas)
    {
        $version = defined("DDWPT_VERSION") ? DDWPT_VERSION : "";
        ?>
        <section class="ddwpt-area" data-area="overview" hidden>
            <div class="ddwpt-header ddwpt-hero-card">
                <?php if ($version): ?>
                <span class="ddwpt-version-pill">v<?php echo esc_html($version); ?></span>
                <?php endif; ?>
                <div class="ddwpt-header-center">
                    <img src="<?php echo esc_url($this->hero_icon()); ?>" alt="WP Toolkit" class="ddwpt-logo" />
                    <span class="ddwpt-active-count" data-active-count></span>
                </div>
            </div>

            <div class="ddwpt-hub-tiles">
                <?php foreach ($areas as $area_id => $area): ?>
                <?php if (!empty($area["utility"])) continue; ?>
                <a href="#<?php echo esc_attr($area_id); ?>" class="ddwpt-card ddwpt-hub-tile">
                    <span class="ddwpt-card-title"><?php echo $this->inline_icon($area["icon"]); ?> <?php echo esc_html($area["label"]); ?></span>
                    <span class="ddwpt-card-desc" data-area-count-text="<?php echo esc_attr($area_id); ?>"></span>
                    <span class="ddwpt-meter"><i data-area-meter="<?php echo esc_attr($area_id); ?>"></i></span>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="ddwpt-card">
                <div class="ddwpt-card-header">
                    <div class="ddwpt-card-info">
                        <h3 class="ddwpt-card-title"><?php esc_html_e("Running now", "wp-toolkit"); ?></h3>
                        <p class="ddwpt-card-desc"><?php esc_html_e("Click a tweak to jump to its card.", "wp-toolkit"); ?></p>
                    </div>
                </div>
                <div class="ddwpt-card-body">
                    <?php
                    $any = false;
                    foreach ($areas as $area_id => $area):
                        if (!empty($area["utility"])) continue;
                        $running = array_filter($this->tweaks_in_area($area_id), [$this, "is_tweak_on"]);
                        if (!$running) continue;
                        $any = true;
                    ?>
                    <div class="ddwpt-field-row">
                        <span class="ddwpt-field-label"><?php echo esc_html($area["label"]); ?></span>
                        <div class="ddwpt-field-input ddwpt-chip-list">
                            <?php foreach ($running as $tweak): ?>
                            <a href="#<?php echo esc_attr($this->card_id($tweak)); ?>" class="ddwpt-chip" data-goto-card="<?php echo esc_attr($this->card_id($tweak)); ?>"><?php echo esc_html($tweak["label"]); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$any): ?>
                    <div class="ddwpt-field-row"><?php esc_html_e("Nothing enabled yet.", "wp-toolkit"); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    private function render_area(string $area_id, array $area)
    {
        $tweaks   = $this->tweaks_in_area($area_id);
        $sections = Settings_Layout::sections($area_id, $tweaks);
        $pages    = $this->get_area_pages($tweaks);
        // Single-section areas skip the switcher; their nested pages are
        // reached through the buttons on their cards instead.
        $switcher = count($sections) > 1;
        ?>
        <section class="ddwpt-area" data-area="<?php echo esc_attr($area_id); ?>" hidden>
            <div class="ddwpt-crumbs">
                <a href="#overview"><?php esc_html_e("Overview", "wp-toolkit"); ?></a>
                / <a href="#<?php echo esc_attr($area_id); ?>" data-crumb-area><?php echo esc_html($area["label"]); ?></a>
                <span data-crumb-page></span>
            </div>
            <h2 class="ddwpt-page-title"><?php echo esc_html($area["label"]); ?></h2>
            <?php if (!empty($area["lede"])): ?>
            <p class="ddwpt-page-lede"><?php echo esc_html($area["lede"]); ?></p>
            <?php endif; ?>

            <?php if ($switcher): ?>
            <div class="ddwpt-segmented" role="tablist">
                <?php foreach ($sections as $section_id => $section_label): ?>
                <a href="#<?php echo esc_attr($area_id . "/" . $section_id); ?>" role="tab" data-pane="<?php echo esc_attr($section_id); ?>"><?php echo esc_html($section_label); ?></a>
                <?php endforeach; ?>
                <?php if ($pages): ?>
                <span class="ddwpt-segmented-divider"></span>
                <?php foreach ($pages as $page_id => $page): ?>
                <a href="#<?php echo esc_attr($area_id . "/" . $page_id); ?>" role="tab" class="is-page" data-pane="<?php echo esc_attr($page_id); ?>" data-page-owner="<?php echo esc_attr($page["owner"] ?? ""); ?>"><?php echo esc_html($page["label"]); ?> ›</a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php foreach ($sections as $section_id => $section_label): ?>
            <div class="ddwpt-pane" data-pane="<?php echo esc_attr($section_id); ?>" data-pane-label="<?php echo esc_attr($section_label); ?>">
                <?php $this->render_tweak_group(array_filter($tweaks, fn($t) => Settings_Layout::section_of($t) === $section_id)); ?>
            </div>
            <?php endforeach; ?>

            <?php foreach ($pages as $page_id => $page): ?>
            <div class="ddwpt-pane" data-pane="<?php echo esc_attr($page_id); ?>" data-pane-label="<?php echo esc_attr($page["label"]); ?>" data-page>
                <?php call_user_func($page["render"], $this); ?>
            </div>
            <?php endforeach; ?>

            <div class="ddwpt-empty" data-area-empty hidden><?php esc_html_e("Nothing here until the required plugins are active.", "wp-toolkit"); ?></div>
            <div class="ddwpt-unavailable-note" data-unavailable-note hidden>
                <span></span>
                <button type="button" class="ddwpt-link-btn" data-show-unavailable><?php esc_html_e("Show anyway", "wp-toolkit"); ?></button>
            </div>
        </section>
        <?php
    }

    private function tweaks_in_area(string $area_id): array
    {
        return array_values(array_filter($this->tweaks, fn($t) => Settings_Layout::area_of($t) === $area_id));
    }

    /**
     * Nested pages in an area: a tweak's own `pages` (render callables) plus
     * any setting with a `page` key, which renders there instead of in the card.
     */
    private function get_area_pages(array $tweaks): array
    {
        $pages = [];
        foreach ($tweaks as $tweak) {
            foreach ($tweak["pages"] ?? [] as $page) {
                $pages[$page["id"]] = $page;
            }
            foreach ($tweak["settings"] ?? [] as $setting) {
                if (empty($setting["page"])) continue;
                $page = $setting["page"];
                $pages[$page["id"]] = $page + [
                    "owner"  => $this->card_id($tweak),
                    "render" => fn() => $this->render_setting_page($tweak, $setting),
                ];
            }
        }
        return $pages;
    }

    private function render_setting_page(array $tweak, array $setting)
    {
        $value = get_option($setting["id"], $setting["default"] ?? "");
        ?>
        <div class="ddwpt-card" data-search="<?php echo esc_attr($setting["page"]["label"] . " " . $tweak["label"]); ?>">
            <div class="ddwpt-card-header">
                <div class="ddwpt-card-info">
                    <h3 class="ddwpt-card-title"><?php echo esc_html($setting["page"]["label"]); ?></h3>
                    <?php if (!empty($setting["description"])): ?>
                    <p class="ddwpt-card-desc"><?php echo $this->kses_desc($setting["description"]); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ddwpt-card-body ddwpt-card-body-flush">
                <?php $this->render_field($setting, $value, $setting["id"]); ?>
            </div>
        </div>
        <?php
    }

    private function card_id(array $tweak): string
    {
        return "ddwpt-card-" . sanitize_html_class($tweak["id"]);
    }

    private function is_tweak_on(array $tweak): bool
    {
        $toggle = $this->find_toggle_setting($tweak);
        return $toggle && get_option($toggle["id"]);
    }

    /** The checkbox ending in _enabled that drives the card header switch. */
    private function find_toggle_setting(array $tweak): ?array
    {
        foreach ($tweak["settings"] ?? [] as $setting) {
            if ($setting["type"] === "checkbox" && str_ends_with($setting["id"], "_enabled")) {
                return $setting;
            }
        }
        return null;
    }

    /** Short description: the tweak's own, else its main switch's. */
    private function tweak_description(array $tweak): string
    {
        if (!empty($tweak["description"])) return $tweak["description"];
        $toggle = $this->find_toggle_setting($tweak);
        return $toggle["description"] ?? "";
    }

    /**
     * Wrapper attributes shared by settings cards and render-only panels:
     * the id the palette jumps to, search text, and Labs / missing plugin
     * state that settings.js uses to show or hide the card.
     */
    private function card_attributes(array $tweak, string $class): string
    {
        $area_id  = Settings_Layout::area_of($tweak);
        $area     = Settings_Layout::AREAS[$area_id] ?? ["label" => Settings_Layout::label_from_id($area_id)];
        $missing  = Settings_Layout::missing_requirements($tweak);

        if (!empty($tweak["labs"])) $class .= " is-labs";
        if ($missing) $class .= " is-unavailable";

        $search = implode(" ", array_merge(
            [$tweak["label"] ?? "", wp_strip_all_tags($this->tweak_description($tweak)), $area["label"]],
            $tweak["tags"] ?? [],
            $tweak["requires"] ?? []
        ));

        $attributes = [
            "class"         => $class,
            "id"            => $this->card_id($tweak),
            "data-tweak"    => $tweak["id"],
            "data-label"    => $tweak["label"] ?? "",
            "data-search"   => $search,
            "data-missing"  => implode(", ", $missing),
        ];

        $html = "";
        foreach ($attributes as $name => $value) {
            $html .= " " . $name . '="' . esc_attr($value) . '"';
        }
        // Cards start hidden when they would be hidden anyway, so nothing
        // flashes before settings.js runs. Hidden cards still submit.
        $labs_hidden = !empty($tweak["labs"]) && !get_option("ddwpt_experiments_enabled");
        return $html . ($labs_hidden || $missing ? " hidden" : "");
    }

    private function render_badges(array $tweak)
    {
        if (!empty($tweak["labs"])) {
            echo '<span class="ddwpt-badge is-labs">Labs</span>';
        }
        foreach ($tweak["tags"] ?? [] as $tag) {
            echo '<span class="ddwpt-badge">' . esc_html($tag) . "</span>";
        }
        foreach ($tweak["requires"] ?? [] as $name) {
            if (Settings_Layout::requirement_met($name) === false) {
                echo '<span class="ddwpt-badge is-missing">' . esc_html(sprintf(__("Needs %s", "wp-toolkit"), $name)) . "</span>";
            } else {
                echo '<span class="ddwpt-badge is-plugin">' . esc_html($name) . "</span>";
            }
        }
    }

    private function render_tweak_card($tweak)
    {
        $enabled_setting = $this->find_toggle_setting($tweak);
        $opt_in_ids      = array_filter(array_column($tweak["abilities"] ?? [], "setting"));
        $other_settings  = [];
        $page_links      = [];

        foreach ($tweak["settings"] as $setting) {
            if (($enabled_setting && $setting["id"] === $enabled_setting["id"]) || in_array($setting["id"], $opt_in_ids, true)) continue;
            if (!empty($setting["page"])) {
                $page_links[] = $setting["page"];
                continue;
            }
            $other_settings[] = $setting;
        }

        $is_enabled = $enabled_setting ? (bool) get_option($enabled_setting["id"]) : true;
        $card_class = "ddwpt-card" . ($enabled_setting && !$is_enabled ? " is-disabled" : "");
        $area_id    = Settings_Layout::area_of($tweak);
        ?>
        <div<?php echo $this->card_attributes($tweak, $card_class); ?>>
            <div class="ddwpt-card-header">
                <div class="ddwpt-card-info">
                    <h3 class="ddwpt-card-title"><?php echo esc_html($tweak["label"]); ?> <?php $this->render_badges($tweak); ?></h3>
                    <?php $description = $this->tweak_description($tweak); ?>
                    <?php if ($description): ?>
                    <p class="ddwpt-card-desc"><?php echo $this->kses_desc($description); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($enabled_setting): ?>
                <?php $this->render_switch($enabled_setting["id"], $is_enabled); ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($tweak["abilities"]) || $other_settings): ?>
            <div class="ddwpt-card-body">
                <?php $this->render_ability_rows($tweak); ?>
                <?php foreach ($other_settings as $setting) $this->render_setting_row($setting); ?>
            </div>
            <?php endif; ?>

            <?php foreach ($page_links as $page): ?>
            <div class="ddwpt-card-body ddwpt-card-link-row">
                <span><?php echo esc_html($page["summary"] ?? ""); ?></span>
                <a href="#<?php echo esc_attr($area_id . "/" . $page["id"]); ?>" class="ddwpt-btn"><?php echo esc_html($page["link"] ?? $page["label"]); ?> →</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** The plugin's switch control, full size for card headers or small for opt-ins. */
    private function render_switch(string $field_id, bool $checked, bool $small = false)
    {
        ?>
        <label class="ddwpt-toggle<?php echo $small ? " ddwpt-toggle-sm" : ""; ?>">
            <input type="checkbox"
                   id="<?php echo esc_attr($field_id); ?>"
                   name="<?php echo esc_attr($field_id); ?>"
                   value="1"
                   <?php checked($checked, true); ?> />
            <span class="ddwpt-toggle-track">
                <span class="ddwpt-toggle-thumb"></span>
            </span>
        </label>
        <?php
    }

    /**
     * Ability tweaks list what they register as chips. Read abilities come
     * with the main switch; other rows carry their own opt-in switch, which
     * is the tweak's existing checkbox setting (same option key).
     */
    private function render_ability_rows(array $tweak)
    {
        $settings = array_column($tweak["settings"], null, "id");

        foreach ($tweak["abilities"] ?? [] as $row) {
            $kind    = $row["kind"] ?? "";
            $names   = is_callable($row["names"] ?? null) ? call_user_func($row["names"]) : ($row["names"] ?? []);
            $setting = !empty($row["setting"]) ? ($settings[$row["setting"]] ?? null) : null;
            $title   = $row["title"] ?? ucfirst($kind);
            $is_on   = $setting ? (bool) get_option($setting["id"]) : true;

            $class = "ddwpt-field-row ddwpt-ability-row";
            if (!$is_on) $class .= " is-off";
            if (!empty($row["independent"])) $class .= " is-independent";
            ?>
            <div class="<?php echo esc_attr($class); ?>">
                <span class="ddwpt-field-label">
                    <?php echo esc_html($title); ?>
                    <?php if ($kind && $names): ?>
                    <span class="ddwpt-badge is-<?php echo esc_attr($kind); ?>"><?php echo count($names); ?></span>
                    <?php endif; ?>
                </span>
                <div class="ddwpt-field-input<?php echo $setting ? " ddwpt-ability-optin" : ""; ?>">
                    <?php if ($setting): ?>
                    <?php $this->render_switch($setting["id"], $is_on, true); ?>
                    <div>
                        <div class="ddwpt-ability-optin-label"<?php echo !empty($setting["description"]) ? ' title="' . esc_attr(wp_strip_all_tags($setting["description"])) . '"' : ""; ?>>
                            <?php echo esc_html($setting["label"]); ?>
                            <?php if ($kind === "write" && !preg_grep("/delete/", $names)): ?>
                            <span class="ddwpt-badge"><?php esc_html_e("No delete", "wp-toolkit"); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php $this->render_ability_chips($names); ?>
                    <?php if ($setting): ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    public function render_ability_chips(array $names)
    {
        if (!$names) return;
        echo '<div class="ddwpt-ability-chips">';
        foreach ($names as $name) {
            $short = str_starts_with($name, "wp-toolkit/") ? substr($name, strlen("wp-toolkit/")) : $name;
            echo '<code title="' . esc_attr($name) . '">' . esc_html($short) . "</code>";
        }
        echo "</div>";
    }

    private function render_setting_row(array $setting)
    {
        $options = $setting["options"] ?? [];
        if (is_callable($options)) {
            $setting["options"] = call_user_func($options);
        }
        $value = get_option($setting["id"], $setting["default"] ?? "");
        ?>
        <?php if (!empty($setting["accordion"])): ?>
        <details class="ddwpt-field-accordion"<?php echo $this->show_if_attribute($setting); ?>>
            <summary class="ddwpt-field-accordion-summary">
                <?php echo esc_html($setting["label"]); ?>
            </summary>
            <div class="ddwpt-field-accordion-body">
                <?php $this->render_field($setting, $value, $setting["id"]); ?>
                <?php if (!empty($setting["description"])): ?>
                <p class="description"><?php echo $this->kses_desc($setting["description"]); ?></p>
                <?php endif; ?>
            </div>
        </details>
        <?php else: ?>
        <div class="ddwpt-field-row"<?php echo $this->show_if_attribute($setting); ?>>
            <label class="ddwpt-field-label" for="<?php echo esc_attr($setting["id"]); ?>">
                <?php echo esc_html($setting["label"]); ?>
            </label>
            <div class="ddwpt-field-input">
                <?php $this->render_field($setting, $value, $setting["id"]); ?>
                <?php if (!empty($setting["description"])): ?>
                <p class="description"><?php echo $this->kses_desc($setting["description"]); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    private function render_field($field, $value, $field_id)
    {
        switch ($field["type"]) {
            case "textarea":
                $safe_id = esc_attr($field_id);
                echo '<textarea class="large-text" rows="8" id="' . $safe_id . '" name="' . $safe_id . '" style="font-family:monospace;font-size:12px;">' . esc_textarea($value) . '</textarea>';
                if (!empty($field["resettable"]) && isset($field["default"])) {
                    echo '<button type="button" class="ddwpt-btn ddwpt-reset-btn" data-input="' . $safe_id . '" data-default="' . esc_attr($field["default"]) . '">Reset to Default</button>';
                }
                break;

            case "url":
            case "text":
                echo '<input type="text" class="regular-text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="' . esc_attr($value) . '" />';
                break;

            case "color":
                $hex = $value ?: ($field["default"] ?? "#000000");
                echo '<input type="color" class="ddwpt-color-input" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="' . esc_attr($hex) . '" />';
                break;

            case "checkbox":
                echo '<label class="ddwpt-checkbox-label">';
                echo '<input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="1" ' . checked($value, 1, false) . " />";
                if (!empty($field["label_inline"])) {
                    echo "<span>" . esc_html($field["label_inline"]) . "</span>";
                }
                echo "</label>";
                break;

            case "select":
                echo '<select id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '">';
                foreach ($field["options"] ?? [] as $opt_value => $opt_label) {
                    echo '<option value="' . esc_attr($opt_value) . '" ' . selected($value, $opt_value, false) . ">" . esc_html($opt_label) . "</option>";
                }
                echo "</select>";
                break;

            case "multiselect":
                $options  = $field["options"] ?? [];
                $selected = $value ? json_decode($value, true) : [];
                if (!is_array($selected)) $selected = [];

                $safe_id = esc_attr($field_id);
                echo '<input type="hidden" id="' . $safe_id . '" name="' . $safe_id . '" value="' . esc_attr(wp_json_encode($selected)) . '" />';
                echo '<select class="ddwpt-multiselect" data-input="' . $safe_id . '" multiple style="min-width:280px;min-height:100px;">';
                foreach ($options as $opt_value => $opt_label) {
                    $is_sel = in_array((string) $opt_value, array_map("strval", $selected), true);
                    echo '<option value="' . esc_attr($opt_value) . '"' . ($is_sel ? " selected" : "") . ">" . esc_html($opt_label) . "</option>";
                }
                echo "</select>";
                break;

            case "media":
                $safe_id = esc_attr($field_id);
                $preview = $value
                    ? '<img src="' . esc_url($value) . '" style="max-width:200px;max-height:60px;display:block;margin-bottom:8px;" />'
                    : "";
                echo '<div class="ddwpt-media-field" data-field="' . $safe_id . '">';
                echo '<div class="ddwpt-media-preview">' . $preview . "</div>";
                echo '<input type="hidden" id="' . $safe_id . '" name="' . $safe_id . '" value="' . esc_attr($value) . '" />';
                echo '<button type="button" class="ddwpt-btn ddwpt-media-select">Select Image</button> ';
                echo '<button type="button" class="ddwpt-btn ddwpt-media-remove"' . ($value ? "" : ' style="display:none;"') . ">Remove</button>";
                echo "</div>";
                break;

            case "sortable":
                $items = $field["items"] ?? [];
                if (is_callable($items)) {
                    $items = call_user_func($items);
                }

                $saved       = $value ? json_decode($value, true) : [];
                $saved_order  = $saved["order"]  ?? [];
                $saved_hidden = $saved["hidden"] ?? [];

                $visible = [];
                foreach ($saved_order as $key) {
                    if (isset($items[$key])) $visible[$key] = $items[$key];
                }
                foreach ($items as $key => $label) {
                    if (!isset($visible[$key]) && !in_array($key, $saved_hidden, true)) {
                        $visible[$key] = $label;
                    }
                }

                $hidden = [];
                foreach ($saved_hidden as $key) {
                    $hidden[$key] = $items[$key] ?? ucfirst(str_replace(["-", ".php"], [" ", ""], $key));
                }

                $safe_id = esc_attr($field_id);
                $initial = wp_json_encode([
                    "order"  => array_keys($visible),
                    "hidden" => array_keys($hidden),
                ]);
                echo '<input type="hidden" class="ddwpt-sortable-input" id="' . $safe_id . '" name="' . $safe_id . '" value="' . esc_attr($initial) . '" />';
                echo '<div class="ddwpt-sortable-wrap" data-input="' . $safe_id . '">';
                echo '<p class="ddwpt-sortable-label">Visible</p>';
                echo '<ul class="ddwpt-sortable ddwpt-sortable-visible">';
                foreach ($visible as $key => $label) {
                    echo '<li data-key="' . esc_attr($key) . '"><span class="dashicons dashicons-menu"></span><span>' . esc_html($label) . "</span></li>";
                }
                echo "</ul>";
                echo '<p class="ddwpt-sortable-label">Hidden</p>';
                echo '<ul class="ddwpt-sortable ddwpt-sortable-hidden">';
                foreach ($hidden as $key => $label) {
                    echo '<li data-key="' . esc_attr($key) . '"><span class="dashicons dashicons-menu"></span><span>' . esc_html($label) . "</span></li>";
                }
                echo "</ul>";
                echo "</div>";
                break;

            case "checkboxes":
                $options  = $field["options"] ?? [];
                $selected = $value ? json_decode($value, true) : [];
                if (!is_array($selected)) $selected = [];

                $safe_id = esc_attr($field_id);
                echo '<input type="hidden" id="' . $safe_id . '" name="' . $safe_id . '" value="' . esc_attr(wp_json_encode($selected)) . '" />';
                echo '<div class="ddwpt-checkboxes-wrap" data-input="' . $safe_id . '">';
                foreach ($options as $opt_value => $opt_label) {
                    $is_checked = in_array((string) $opt_value, array_map("strval", $selected), true);
                    echo '<label class="ddwpt-checkbox-item">';
                    echo '<input type="checkbox" value="' . esc_attr($opt_value) . '"' . ($is_checked ? " checked" : "") . " />";
                    echo "<span>" . esc_html($opt_label) . "</span>";
                    echo "</label>";
                }
                echo "</div>";
                break;

            case "wysiwyg":
                $editor_id = preg_replace("/[^a-z0-9_]/", "_", strtolower($field_id));
                wp_editor($value, $editor_id, [
                    "textarea_name" => $field_id,
                    "textarea_rows" => $field["rows"] ?? 8,
                    "media_buttons" => $field["media_buttons"] ?? false,
                    "teeny"         => $field["teeny"] ?? false,
                ]);
                break;

            case "acf_export":
                echo '<div class="ddwpt-acf-export-wrap">';
                echo '<button type="button" class="ddwpt-btn ddwpt-acf-export-btn">Export ACF Presets</button>';
                echo ' <span class="ddwpt-acf-export-result"></span>';
                echo '</div>';
                break;

            case "faq_import":
                $example = wp_json_encode(
                    [
                        [
                            "question" => "What is your return policy?",
                            "answer"   => "We accept returns within 30 days of purchase. Items must be unused and in original packaging.",
                            "taxonomy" => "returns",
                        ],
                        [
                            "question" => "How do I track my order?",
                            "answer"   => "You will receive a tracking link by email once your order ships. You can also log in to your account to view order status.",
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                echo '<div class="ddwpt-json-import" data-action="ddwpt_faq_import" data-nonce-key="faqImportNonce">';
                echo '<textarea class="ddwpt-json-import-editor" rows="12" style="width:100%;"></textarea>';
                echo '<div style="margin-top:8px;">';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-import-btn">Run Import</button>';
                echo ' <span class="ddwpt-json-import-result"></span>';
                echo '</div>';
                echo '<div class="ddwpt-json-schema" style="margin-top:20px;">';
                echo '<p class="description" style="margin-bottom:6px;font-weight:600;">Schema</p>';
                echo '<p class="description" style="margin-bottom:8px;">';
                echo '<strong>Required:</strong> <code>question</code> (string), <code>answer</code> (string, HTML allowed)<br>';
                echo '<strong>Optional:</strong> <code>taxonomy</code> (string) — slug of an existing <code>faq-tag</code> term; silently skipped if not found';
                echo '</p>';
                echo '<pre class="ddwpt-json-schema-example">' . esc_html($example) . '</pre>';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-copy-schema" style="margin-top:6px;">Copy schema to clipboard</button>';
                echo '</div>';
                echo '</div>';
                break;

            case "acf_keys":
                $keys = $field["keys"] ?? [];
                if (empty($keys)) break;
                echo '<table class="ddwpt-field-keys-table">';
                echo '<thead><tr><th>Field name</th><th>Type</th><th>Description</th></tr></thead>';
                echo '<tbody>';
                foreach ($keys as $key) {
                    echo '<tr>';
                    echo '<td><code>' . esc_html($key['name']) . '</code></td>';
                    echo '<td>' . esc_html($key['type']) . '</td>';
                    echo '<td>' . esc_html($key['label']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                break;

            case "team_member_import":
                $example = wp_json_encode(
                    [
                        [
                            "name"     => "Jane Smith",
                            "bio"      => "Jane leads our design team with over **10 years** of experience.\n\n- Brand strategy\n- Visual identity\n- UX design",
                            "taxonomy" => "creative-director",
                            "email"    => "jane@example.com",
                            "phone"    => "+1 555 000 0000",
                            "linkedin" => "https://linkedin.com/in/janesmith",
                        ],
                        [
                            "name"     => "Tom Davies",
                            "bio"      => "Tom manages client relationships across all accounts.",
                            "taxonomy" => "account-manager",
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                echo '<div class="ddwpt-json-import" data-action="ddwpt_tm_import" data-nonce-key="tmImportNonce">';
                echo '<textarea class="ddwpt-json-import-editor" rows="12" style="width:100%;"></textarea>';
                echo '<div style="margin-top:8px;">';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-import-btn">Run Import</button>';
                echo ' <span class="ddwpt-json-import-result"></span>';
                echo '</div>';
                echo '<div class="ddwpt-json-schema" style="margin-top:20px;">';
                echo '<p class="description" style="margin-bottom:6px;font-weight:600;">Schema</p>';
                echo '<p class="description" style="margin-bottom:8px;">';
                echo '<strong>Required:</strong> <code>name</code> (string) — person\'s full name<br>';
                echo '<strong>Optional:</strong> <code>bio</code> (string, Markdown) — converted to Gutenberg blocks<br>';
                echo '<strong>Optional:</strong> <code>taxonomy</code> (string) — slug of an existing <code>job-title</code> term; silently skipped if not found<br>';
                echo '<strong>Optional:</strong> <code>email</code>, <code>phone</code>, <code>linkedin</code> (strings) — stored as ACF profile fields';
                echo '</p>';
                echo '<pre class="ddwpt-json-schema-example">' . esc_html($example) . '</pre>';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-copy-schema" style="margin-top:6px;">Copy schema to clipboard</button>';
                echo '</div>';
                echo '</div>';
                break;

            case "testimonial_import":
                $example = wp_json_encode(
                    [
                        [
                            "name"     => "Sarah Johnson",
                            "quote"    => "Working with this team was an incredible experience. They delivered beyond our expectations.",
                            "taxonomy" => "web-design",
                            "company"  => "Acme Corp",
                            "role"     => "Marketing Director",
                            "rating"   => 5,
                        ],
                        [
                            "name"  => "Tom Davies",
                            "quote" => "Professional, communicative, and fast. Highly recommended.",
                            "company" => "Davies & Co",
                            "role"    => "Founder",
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                echo '<div class="ddwpt-json-import" data-action="ddwpt_testimonial_import" data-nonce-key="testimonialImportNonce">';
                echo '<textarea class="ddwpt-json-import-editor" rows="12" style="width:100%;"></textarea>';
                echo '<div style="margin-top:8px;">';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-import-btn">Run Import</button>';
                echo ' <span class="ddwpt-json-import-result"></span>';
                echo '</div>';
                echo '<div class="ddwpt-json-schema" style="margin-top:20px;">';
                echo '<p class="description" style="margin-bottom:6px;font-weight:600;">Schema</p>';
                echo '<p class="description" style="margin-bottom:8px;">';
                echo '<strong>Required:</strong> <code>name</code> (string) — person\'s name<br>';
                echo '<strong>Optional:</strong> <code>quote</code> (string) — testimonial text<br>';
                echo '<strong>Optional:</strong> <code>taxonomy</code> (string) — slug of an existing <code>testimonial-category</code> term<br>';
                echo '<strong>Optional:</strong> <code>company</code>, <code>role</code> (strings), <code>rating</code> (integer 1–5)';
                echo '</p>';
                echo '<pre class="ddwpt-json-schema-example">' . esc_html($example) . '</pre>';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-copy-schema" style="margin-top:6px;">Copy schema to clipboard</button>';
                echo '</div>';
                echo '</div>';
                break;

            case "location_import":
                $example = wp_json_encode(
                    [
                        [
                            "name"        => "City Branch",
                            "description" => "Our main city location, open six days a week.",
                            "taxonomy"    => "branch",
                            "address"     => "123 Main St\nSydney NSW 2000",
                            "phone"       => "+61 2 0000 0000",
                            "email"       => "city@example.com",
                            "hours"       => "Mon–Fri 9am–5pm\nSat 10am–2pm",
                        ],
                        [
                            "name"    => "Suburbs Outpost",
                            "taxonomy" => "outpost",
                            "address" => "45 Park Rd\nParramatta NSW 2150",
                            "phone"   => "+61 2 0000 0001",
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                echo '<div class="ddwpt-json-import" data-action="ddwpt_location_import" data-nonce-key="locationImportNonce">';
                echo '<textarea class="ddwpt-json-import-editor" rows="12" style="width:100%;"></textarea>';
                echo '<div style="margin-top:8px;">';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-import-btn">Run Import</button>';
                echo ' <span class="ddwpt-json-import-result"></span>';
                echo '</div>';
                echo '<div class="ddwpt-json-schema" style="margin-top:20px;">';
                echo '<p class="description" style="margin-bottom:6px;font-weight:600;">Schema</p>';
                echo '<p class="description" style="margin-bottom:8px;">';
                echo '<strong>Required:</strong> <code>name</code> (string) — location name<br>';
                echo '<strong>Optional:</strong> <code>description</code> (string) — plain text or HTML<br>';
                echo '<strong>Optional:</strong> <code>taxonomy</code> (string) — slug of an existing <code>location-type</code> term<br>';
                echo '<strong>Optional:</strong> <code>address</code>, <code>phone</code>, <code>email</code>, <code>hours</code>, <code>map_embed</code> (strings)';
                echo '</p>';
                echo '<pre class="ddwpt-json-schema-example">' . esc_html($example) . '</pre>';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-copy-schema" style="margin-top:6px;">Copy schema to clipboard</button>';
                echo '</div>';
                echo '</div>';
                break;

            case "project_import":
                $example = wp_json_encode(
                    [
                        [
                            "name"        => "Brand Refresh",
                            "description" => "## Overview\n\nA complete visual identity overhaul for a national retail brand.\n\n- Logo redesign\n- Brand guidelines\n- Digital asset library",
                            "taxonomy"    => "branding",
                            "client"      => "Acme Corp",
                            "url"         => "https://acmecorp.com",
                            "year"        => 2024,
                        ],
                        [
                            "name"   => "E-commerce Replatform",
                            "taxonomy" => "web-development",
                            "client"   => "Retail Co",
                            "year"     => 2023,
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                echo '<div class="ddwpt-json-import" data-action="ddwpt_project_import" data-nonce-key="projectImportNonce">';
                echo '<textarea class="ddwpt-json-import-editor" rows="12" style="width:100%;"></textarea>';
                echo '<div style="margin-top:8px;">';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-import-btn">Run Import</button>';
                echo ' <span class="ddwpt-json-import-result"></span>';
                echo '</div>';
                echo '<div class="ddwpt-json-schema" style="margin-top:20px;">';
                echo '<p class="description" style="margin-bottom:6px;font-weight:600;">Schema</p>';
                echo '<p class="description" style="margin-bottom:8px;">';
                echo '<strong>Required:</strong> <code>name</code> (string) — project name<br>';
                echo '<strong>Optional:</strong> <code>description</code> (string, Markdown) — converted to Gutenberg blocks<br>';
                echo '<strong>Optional:</strong> <code>taxonomy</code> (string) — slug of an existing <code>project-type</code> term<br>';
                echo '<strong>Optional:</strong> <code>client</code> (string), <code>url</code> (string), <code>year</code> (integer)';
                echo '</p>';
                echo '<pre class="ddwpt-json-schema-example">' . esc_html($example) . '</pre>';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-copy-schema" style="margin-top:6px;">Copy schema to clipboard</button>';
                echo '</div>';
                echo '</div>';
                break;

            case "service_area_import":
                $example = wp_json_encode(
                    [
                        [
                            "title"    => "Sunnybrook",
                            "content"  => "## About Sunnybrook\n\nWe serve the **Sunnybrook** area with comprehensive lawn care.\n\n- Mowing\n- Edging\n- Fertilization",
                            "taxonomy" => "north-region",
                        ],
                        [
                            "title"   => "Riverside",
                            "content" => "Full-service coverage for the Riverside district.",
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                echo '<div class="ddwpt-json-import" data-action="ddwpt_sa_import" data-nonce-key="saImportNonce">';
                echo '<textarea class="ddwpt-json-import-editor" rows="12" style="width:100%;"></textarea>';
                echo '<div style="margin-top:8px;">';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-import-btn">Run Import</button>';
                echo ' <span class="ddwpt-json-import-result"></span>';
                echo '</div>';
                echo '<div class="ddwpt-json-schema" style="margin-top:20px;">';
                echo '<p class="description" style="margin-bottom:6px;font-weight:600;">Schema</p>';
                echo '<p class="description" style="margin-bottom:8px;">';
                echo '<strong>Required:</strong> <code>title</code> (string) — location name<br>';
                echo '<strong>Optional:</strong> <code>content</code> (string, Markdown) — converted to Gutenberg blocks<br>';
                echo '<strong>Optional:</strong> <code>taxonomy</code> (string) — slug of an existing <code>region</code> term; silently skipped if not found';
                echo '</p>';
                echo '<pre class="ddwpt-json-schema-example">' . esc_html($example) . '</pre>';
                echo '<button type="button" class="ddwpt-btn ddwpt-json-copy-schema" style="margin-top:6px;">Copy schema to clipboard</button>';
                echo '</div>';
                echo '</div>';
                break;

            default:
                // Custom field types are rendered by the tweak that defines them.
                do_action("ddwpt_render_field_" . $field["type"], $field, $value, $field_id);
                break;
        }
    }

    public function render_info_card(string $title, string $desc = '', array $fields = [], array $badge = []): void
    {
        ?>
        <div class="ddwpt-card">
            <div class="ddwpt-card-header">
                <div class="ddwpt-card-info">
                    <h3 class="ddwpt-card-title"><?php echo esc_html($title); ?></h3>
                    <?php if ($desc): ?>
                    <p class="ddwpt-card-desc"><?php echo wp_kses_post($desc); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($badge): ?>
                <span class="ddwpt-status-badge <?php echo esc_attr($badge['class'] ?? ''); ?>">
                    <?php echo esc_html($badge['label'] ?? ''); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if (!empty($fields)): ?>
            <div class="ddwpt-card-body">
                <?php foreach ($fields as $field): ?>
                <?php if (!empty($field['full'])): ?>
                <div class="ddwpt-field-full"><?php echo $field['html'] ?? ''; ?></div>
                <?php else: ?>
                <div class="ddwpt-field-row">
                    <label class="ddwpt-field-label"><?php echo esc_html($field['label'] ?? ''); ?></label>
                    <div class="ddwpt-field-input"><?php echo $field['html'] ?? ''; ?></div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /** data-show-if for fields that only apply while a sibling has given values. */
    private function show_if_attribute(array $setting): string
    {
        if (empty($setting["show_if"])) return "";
        return ' data-show-if="' . esc_attr(wp_json_encode($setting["show_if"])) . '"';
    }

    private function kses_desc(string $text): string
    {
        return wp_kses($text, [
            'code'   => [],
            'strong' => [],
            'em'     => [],
            'a'      => ['href' => [], 'target' => [], 'rel' => []],
        ]);
    }

    private function get_active_tweak_count()
    {
        $count = 0;
        foreach ($this->tweaks as $tweak) {
            foreach ($tweak["settings"] ?? [] as $setting) {
                if (str_ends_with($setting["id"], "_enabled") && get_option($setting["id"])) {
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }

    /**
     * Settings cards first, then render-only panels, except panels marked
     * 'position' => 'first' (the AI setup card), which lead the group.
     * Render panels are wrapped so search can find and reveal them.
     */
    private function render_tweak_group(array $tweaks)
    {
        $is_render = fn($t) => isset($t["render"]);
        $leading   = array_filter($tweaks, fn($t) => $is_render($t) && ($t["position"] ?? "") === "first");
        $settings  = array_filter($tweaks, fn($t) => !$is_render($t));
        $trailing  = array_filter($tweaks, fn($t) => $is_render($t) && ($t["position"] ?? "") !== "first");

        foreach ($leading as $tweak) $this->render_panel($tweak);
        foreach ($settings as $tweak) $this->render_tweak_card($tweak);
        foreach ($trailing as $tweak) $this->render_panel($tweak);
    }

    private function render_panel(array $tweak)
    {
        $tweak += ["label" => ""];
        echo "<div" . $this->card_attributes($tweak, "ddwpt-render-panel") . ">";
        call_user_func($tweak["render"], $this);
        echo "</div>";
    }

    public function get_tweaks(): array
    {
        return $this->tweaks;
    }

    /**
     * Counts across every tweak that declares `abilities`, for the AI setup
     * card. An ability is live when its tweak is on, its plugins are active
     * and, for opt-in rows, the opt-in is on.
     */
    public function get_ability_summary(): array
    {
        $summary = ["total" => 0, "read" => 0, "write" => 0, "tweaks" => 0, "tweaks_on" => 0];

        foreach ($this->tweaks as $tweak) {
            if (empty($tweak["abilities"])) continue;
            $summary["tweaks"]++;
            $tweak_live = $this->is_tweak_on($tweak) && !Settings_Layout::missing_requirements($tweak);
            if ($tweak_live) $summary["tweaks_on"]++;

            foreach ($tweak["abilities"] as $row) {
                $kind = $row["kind"] ?? "";
                if (!in_array($kind, ["read", "write"], true)) continue;
                $count = count($row["names"]);
                $summary["total"] += $count;
                if ($tweak_live && (empty($row["setting"]) || get_option($row["setting"]))) {
                    $summary[$kind] += $count;
                }
            }
        }

        $summary["live"] = $summary["read"] + $summary["write"];
        return $summary;
    }

    public function inline_icon($filename)
    {
        $path = plugin_dir_path(__DIR__) . "assets/icons/" . $filename;
        if (!file_exists($path)) {
            return "";
        }
        $svg = file_get_contents($path);
        // Strip XML comments and <desc> blocks, then add aria-hidden
        $svg = preg_replace('/<desc>.*?<\/desc>/s', '', $svg);
        $svg = str_replace("<svg ", '<svg aria-hidden="true" focusable="false" ', $svg);
        return $svg;
    }
}

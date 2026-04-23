<?php

namespace DDWPTweaks;

defined("ABSPATH") || exit();

class Plugin
{
    private $tweaks = [];
    private $settings_group = "ddwptweaks_options";
    private $page_hook = "tools_page_ddwptweaks";

    public function __construct()
    {
        require_once __DIR__ . "/class-tweak-loader.php";
        $this->tweaks = (new Tweak_Loader())->load_all();

        add_action("admin_menu",            [$this, "register_menu"]);
        add_action("admin_init",            [$this, "register_settings"]);
        add_action("admin_enqueue_scripts", [$this, "enqueue_assets"]);
        add_filter("admin_body_class",      [$this, "add_body_class"]);
        add_action("tool_box",              [$this, "render_toolbox_card"]);
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

    public function register_settings()
    {
        $sanitize_map = [
            "wysiwyg"     => "wp_kses_post",
            "text"        => "sanitize_text_field",
            "select"      => "sanitize_text_field",
            "checkbox"    => "absint",
            "media"       => "esc_url_raw",
            "multiselect" => function ($val) {
                $decoded = json_decode($val, true);
                if (!is_array($decoded)) return "";
                return wp_json_encode(array_map("sanitize_text_field", $decoded));
            },
            "sortable"    => function ($val) {
                $decoded = json_decode($val, true);
                if (!is_array($decoded)) return "";
                return wp_json_encode([
                    "order"  => array_map("sanitize_key", $decoded["order"]  ?? []),
                    "hidden" => array_map("sanitize_key", $decoded["hidden"] ?? []),
                ]);
            },
        ];

        foreach ($this->tweaks as $tweak) {
            foreach ($tweak["settings"] as $setting) {
                register_setting($this->settings_group, $setting["id"], [
                    "sanitize_callback" => $sanitize_map[$setting["type"]] ?? "sanitize_text_field",
                ]);
            }
        }
    }

    public function render_settings_page()
    {
        $tabs         = $this->get_all_tabs();
        $version      = defined("DDWPT_VERSION") ? DDWPT_VERSION : "";
        $active_count = $this->get_active_tweak_count();
        $total_count  = count($this->tweaks);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields($this->settings_group); ?>

            <div class="ddwpt-wrap">

                <div class="ddwpt-header">
                    <div class="ddwpt-header-left">
                        <img src="<?php echo esc_url(DDWPT_URL . 'assets/icons/wptk-logo.svg'); ?>"
                             alt="WP Toolkit"
                             class="ddwpt-logo" />
                        <?php if ($version): ?>
                        <span class="ddwpt-version-pill">v<?php echo esc_html($version); ?></span>
                        <?php endif; ?>
                        <span class="ddwpt-active-count">
                            <?php echo esc_html($active_count); ?> / <?php echo esc_html($total_count); ?> active
                        </span>
                    </div>
                    <button type="submit" class="ddwpt-save-btn">Save Changes</button>
                </div>

                <?php if (count($tabs) > 1): ?>
                <div class="ddwpt-tabs-nav">
                    <?php foreach ($tabs as $tab_id => $tab_label): ?>
                    <a href="#<?php echo esc_attr($tab_id); ?>"
                       class="ddwpt-tab"
                       data-tab="<?php echo esc_attr($tab_id); ?>">
                        <?php echo $this->get_tab_icon($tab_id); ?>
                        <?php echo esc_html($tab_label); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="ddwpt-content">
                    <?php foreach ($tabs as $tab_id => $tab_label): ?>
                    <div class="ddwpt-panel" data-tab="<?php echo esc_attr($tab_id); ?>" style="display:none;">
                        <?php foreach ($this->get_tweaks_for_tab($tab_id) as $tweak):
                            $this->render_tweak_card($tweak);
                        endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </form>
        <?php
    }

    private function render_tweak_card($tweak)
    {
        $enabled_setting = null;
        $other_settings  = [];

        foreach ($tweak["settings"] as $setting) {
            if (
                !$enabled_setting
                && $setting["type"] === "checkbox"
                && str_ends_with($setting["id"], "_enabled")
            ) {
                $enabled_setting = $setting;
            } else {
                $other_settings[] = $setting;
            }
        }

        $is_enabled  = $enabled_setting ? (bool) get_option($enabled_setting["id"]) : true;
        $description = $enabled_setting["description"] ?? "";

        $card_class = "ddwpt-card";
        if ($enabled_setting && !$is_enabled) {
            $card_class .= " is-disabled";
        }
        ?>
        <div class="<?php echo esc_attr($card_class); ?>">
            <div class="ddwpt-card-header">
                <div class="ddwpt-card-info">
                    <h3 class="ddwpt-card-title"><?php echo esc_html($tweak["label"]); ?></h3>
                    <?php if ($description): ?>
                    <p class="ddwpt-card-desc"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($enabled_setting): ?>
                <label class="ddwpt-toggle" title="<?php echo $is_enabled ? "Enabled" : "Disabled"; ?>">
                    <input type="checkbox"
                           id="<?php echo esc_attr($enabled_setting["id"]); ?>"
                           name="<?php echo esc_attr($enabled_setting["id"]); ?>"
                           value="1"
                           <?php checked($is_enabled, true); ?> />
                    <span class="ddwpt-toggle-track">
                        <span class="ddwpt-toggle-thumb"></span>
                    </span>
                </label>
                <?php endif; ?>
            </div>

            <?php if (!empty($other_settings)): ?>
            <div class="ddwpt-card-body">
                <?php foreach ($other_settings as $setting):
                    $options = $setting["options"] ?? [];
                    if (is_callable($options)) {
                        $setting["options"] = call_user_func($options);
                    }
                    $value = get_option($setting["id"], $setting["default"] ?? "");
                ?>
                <div class="ddwpt-field-row">
                    <label class="ddwpt-field-label" for="<?php echo esc_attr($setting["id"]); ?>">
                        <?php echo esc_html($setting["label"]); ?>
                    </label>
                    <div class="ddwpt-field-input">
                        <?php $this->render_field($setting, $value, $setting["id"]); ?>
                        <?php if (!empty($setting["description"])): ?>
                        <p class="description"><?php echo esc_html($setting["description"]); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_field($field, $value, $field_id)
    {
        switch ($field["type"]) {
            case "text":
                echo '<input type="text" class="regular-text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="' . esc_attr($value) . '" />';
                break;

            case "checkbox":
                echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
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
                echo '<button type="button" class="button ddwpt-media-select">Select Image</button> ';
                echo '<button type="button" class="button ddwpt-media-remove"' . ($value ? "" : ' style="display:none;"') . ">Remove</button>";
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

            case "wysiwyg":
                $editor_id = preg_replace("/[^a-z0-9_]/", "_", strtolower($field_id));
                wp_editor($value, $editor_id, [
                    "textarea_name" => $field_id,
                    "textarea_rows" => $field["rows"] ?? 8,
                    "media_buttons" => $field["media_buttons"] ?? false,
                    "teeny"         => $field["teeny"] ?? false,
                ]);
                break;
        }
    }

    private function get_active_tweak_count()
    {
        $count = 0;
        foreach ($this->tweaks as $tweak) {
            foreach ($tweak["settings"] as $setting) {
                if (str_ends_with($setting["id"], "_enabled") && get_option($setting["id"])) {
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }

    private function get_all_tabs()
    {
        $preferred = ["general", "dashboard", "admin-bar", "admin-tables", "sidebar", "bricks"];

        $tabs       = [];
        $has_general = false;

        foreach ($this->tweaks as $tweak) {
            $tab = !empty($tweak["tab"]) ? $tweak["tab"] : null;
            if ($tab) {
                $tabs[$tab] = ucfirst(str_replace("-", " ", $tab));
            } else {
                $has_general = true;
            }
        }

        if ($has_general) {
            $tabs["general"] = "General";
        }

        $sorted = [];
        foreach ($preferred as $key) {
            if (isset($tabs[$key])) $sorted[$key] = $tabs[$key];
        }
        foreach ($tabs as $key => $label) {
            if (!isset($sorted[$key])) $sorted[$key] = $label;
        }

        return $sorted;
    }

    private function get_tweaks_for_tab($tab_id)
    {
        return array_filter($this->tweaks, function ($tweak) use ($tab_id) {
            return (!empty($tweak["tab"]) ? $tweak["tab"] : "general") === $tab_id;
        });
    }

    private function get_tab_icon($tab_id)
    {
        $icons = [
            "general"      => "Boxes-Lucide.svg",
            "dashboard"    => "Gauge-Lucide.svg",
            "admin-bar"    => "Credit-Card-Lucide.svg",
            "admin-tables" => "Table-Properties-Lucide.svg",
            "sidebar"      => "Panel-Left-Lucide.svg",
            "bricks"       => "Layout-Dashboard-Lucide.svg",
            "footer"       => "Dock-Lucide.svg",
            "media"        => "Image-Lucide.svg",
            "notifications"       => "Message-Square-Warning-Lucide.svg",
            "themes"       => "Swatch-Book-Lucide.svg",
            "security"     => "Globe-Lock-Lucide.svg",
            "debug"        => "Bug-Play-Lucide.svg",
        ];
        $file = $icons[$tab_id] ?? "Boxes-Lucide.svg";
        return $this->inline_icon($file);
    }

    private function inline_icon($filename)
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

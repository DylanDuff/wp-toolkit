<?php

namespace DDWPTweaks;

defined('ABSPATH') || exit;

class Plugin
{
    private $tweaks = [];
    private $settings_group = 'ddwptweaks_options';

    public function __construct()
    {
        require_once __DIR__ . '/class-tweak-loader.php';
        $this->tweaks = (new Tweak_Loader())->load_all();

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_media']);
    }

    public function register_menu()
    {
        add_management_page(
            'WP Toolkit',
            'WP Toolkit',
            'manage_options',
            'ddwptweaks',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_media($hook)
    {
        if ($hook !== 'tools_page_ddwptweaks') return;
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
    }

    public function register_settings()
    {
        foreach ($this->tweaks as $tweak) {
            // Create a section per tweak under the tweak ID
            add_settings_section(
                $tweak['id'],
                $tweak['label'],
                '__return_empty_string',
                $tweak['id']
            );

            foreach ($tweak['settings'] as $setting) {
                $sanitize = $setting['type'] === 'wysiwyg' ? 'wp_kses_post' : null;
                register_setting($this->settings_group, $setting['id'], [
                    'sanitize_callback' => $sanitize,
                ]);

                add_settings_field(
                    $setting['id'],
                    $setting['label'],
                    function () use ($setting) {
                        $val = get_option($setting['id'], $setting['default'] ?? '');
                        $this->render_field($setting, $val, $setting['id']);
                    },
                    $tweak['id'],
                    $tweak['id']
                );
            }
        }
    }

    private function render_field($field, $value, $field_id)
    {
        switch ($field['type']) {
            case 'text':
                echo '<input type="text" class="regular-text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="' . esc_attr($value) . '" />';
                break;

            case 'checkbox':
                echo '<label for="' . esc_attr($field_id) . '">';
                echo '<input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="1" ' . checked($value, 1, false) . ' />';
                if (!empty($field['description'])) {
                    echo ' ' . esc_html($field['description']);
                }
                echo '</label>';
                break;

            case 'select':
                echo '<select id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '">';
                foreach (($field['options'] ?? []) as $opt_value => $opt_label) {
                    echo '<option value="' . esc_attr($opt_value) . '" ' . selected($value, $opt_value, false) . '>' . esc_html($opt_label) . '</option>';
                }
                echo '</select>';
                break;

            case 'multiselect':
                $options = $field['options'] ?? [];
                if (is_callable($options)) {
                    $options = call_user_func($options);
                }
                $selected = $value ? json_decode($value, true) : [];
                if (!is_array($selected)) $selected = [];

                $safe_id = esc_attr($field_id);
                echo '<input type="hidden" id="' . $safe_id . '" name="' . $safe_id . '" value="' . esc_attr(wp_json_encode($selected)) . '" />';
                echo '<select class="ddwpt-multiselect" data-input="' . $safe_id . '" multiple style="min-width: 300px; min-height: 120px;">';
                foreach ($options as $opt_value => $opt_label) {
                    $is_selected = in_array((string) $opt_value, array_map('strval', $selected), true);
                    echo '<option value="' . esc_attr($opt_value) . '"' . ($is_selected ? ' selected' : '') . '>' . esc_html($opt_label) . '</option>';
                }
                echo '</select>';
                break;

            case 'media':
                $safe_id = esc_attr($field_id);
                $preview = $value ? '<img src="' . esc_url($value) . '" style="max-width: 200px; max-height: 60px; display: block; margin-bottom: 8px;" />' : '';
                echo '<div class="ddwpt-media-field" data-field="' . $safe_id . '">';
                echo '<div class="ddwpt-media-preview">' . $preview . '</div>';
                echo '<input type="hidden" id="' . $safe_id . '" name="' . $safe_id . '" value="' . esc_attr($value) . '" />';
                echo '<button type="button" class="button ddwpt-media-select">Select Image</button> ';
                echo '<button type="button" class="button ddwpt-media-remove"' . ($value ? '' : ' style="display:none;"') . '>Remove</button>';
                echo '</div>';
                break;

            case 'sortable':
                $items = $field['items'] ?? [];
                if (is_callable($items)) {
                    $items = call_user_func($items);
                }

                $saved = $value ? json_decode($value, true) : [];
                if (!is_array($saved)) $saved = [];
                $saved_order = $saved['order'] ?? [];
                $saved_hidden = $saved['hidden'] ?? [];

                // Build visible list: saved order first, then new items
                $visible = [];
                foreach ($saved_order as $key) {
                    if (isset($items[$key])) {
                        $visible[$key] = $items[$key];
                    }
                }
                foreach ($items as $key => $label) {
                    if (!isset($visible[$key]) && !in_array($key, $saved_hidden, true)) {
                        $visible[$key] = $label;
                    }
                }

                // Build hidden list (items may no longer be in $items if removed by the tweak)
                $hidden = [];
                foreach ($saved_hidden as $key) {
                    $hidden[$key] = $items[$key] ?? ucfirst(str_replace(['-', '.php'], [' ', ''], $key));
                }

                $safe_id = esc_attr($field_id);
                $initial = wp_json_encode(['order' => array_keys($visible), 'hidden' => array_keys($hidden)]);
                echo '<input type="hidden" class="ddwpt-sortable-input" id="' . $safe_id . '" name="' . $safe_id . '" value="' . esc_attr($initial) . '" />';

                echo '<div class="ddwpt-sortable-wrap" data-input="' . $safe_id . '" style="max-width: 400px;">';

                echo '<p class="description" style="margin: 0 0 4px;">Visible</p>';
                echo '<ul class="ddwpt-sortable ddwpt-sortable-visible" style="margin: 0; min-height: 38px;">';
                foreach ($visible as $key => $label) {
                    echo '<li data-key="' . esc_attr($key) . '">';
                    echo '<span class="dashicons dashicons-menu"></span>';
                    echo '<span>' . esc_html($label) . '</span>';
                    echo '</li>';
                }
                echo '</ul>';

                echo '<p class="description" style="margin: 12px 0 4px;">Hidden</p>';
                echo '<ul class="ddwpt-sortable ddwpt-sortable-hidden" style="margin: 0; min-height: 38px;">';
                foreach ($hidden as $key => $label) {
                    echo '<li data-key="' . esc_attr($key) . '">';
                    echo '<span class="dashicons dashicons-menu"></span>';
                    echo '<span>' . esc_html($label) . '</span>';
                    echo '</li>';
                }
                echo '</ul>';

                echo '</div>';

                echo '<style>
                    .ddwpt-sortable li {
                        display: flex; align-items: center; gap: 8px;
                        padding: 8px 12px; margin: 0;
                        background: #fff; border: 1px solid #c3c4c7; border-bottom: none;
                        cursor: grab; user-select: none;
                    }
                    .ddwpt-sortable li .dashicons { color: #999; flex-shrink: 0; }
                    .ddwpt-sortable li:last-child { border-bottom: 1px solid #c3c4c7; }
                    .ddwpt-sortable li.ui-sortable-helper { box-shadow: 0 2px 8px rgba(0,0,0,0.15); border-bottom: 1px solid #c3c4c7; }
                    .ddwpt-sortable .ui-sortable-placeholder { visibility: visible !important; background: #f0f6fc; border: 1px dashed #72aee6; border-bottom: none; height: 38px; }
                    .ddwpt-sortable-hidden { background: #f9f0f0; border: 1px dashed #c3c4c7; border-radius: 3px; }
                    .ddwpt-sortable-hidden li { background: #fcf0f0; opacity: 0.7; }
                    .ddwpt-sortable-hidden:empty::after { content: "Drag items here to hide them"; display: block; padding: 10px 12px; color: #999; font-style: italic; }
                    .ddwpt-sortable-visible:empty::after { content: "No visible items"; display: block; padding: 10px 12px; color: #999; font-style: italic; }
                </style>';
                break;

            case 'wysiwyg':
                $editor_id = preg_replace('/[^a-z0-9_]/', '_', strtolower($field_id));
                wp_editor($value, $editor_id, [
                    'textarea_name' => $field_id,
                    'textarea_rows' => $field['rows'] ?? 8,
                    'media_buttons' => $field['media_buttons'] ?? false,
                    'teeny'         => $field['teeny'] ?? false,
                ]);
                break;
        }

        if (!empty($field['description']) && $field['type'] !== 'checkbox') {
            echo '<p class="description">' . esc_html($field['description']) . '</p>';
        }
    }

    public function render_settings_page()
    {
        $tabs = $this->get_all_tabs();
        $default_tab = array_key_first($tabs) ?: 'general';
        $active_count = $this->get_active_tweak_count();
        $total_count = count($this->tweaks);

        ?>
        <style>
            .ddwpt-tabs .nav-tab { text-transform: capitalize; }
        </style>

        <div class="wrap">

            <form method="post" action="options.php">
                <?php settings_fields($this->settings_group); ?>

                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;">
                    <div>
                        <h1 style="margin-bottom: 0;"><?php echo esc_html(get_admin_page_title()); ?></h1>
                        <p class="description">
                            Toggle individual admin tweaks on or off. Each tweak is a small, self-contained
                            modification to your WordPress experience.
                            Currently <strong><?php echo esc_html($active_count); ?></strong> of
                            <strong><?php echo esc_html($total_count); ?></strong> tweaks active.
                        </p>
                    </div>
                    <?php submit_button('Save Changes', 'primary', 'submit', false); ?>
                </div>

                <?php if (count($tabs) > 1) : ?>
                    <nav class="nav-tab-wrapper ddwpt-tabs" style="margin-bottom: 0;">
                        <?php foreach ($tabs as $tab_id => $tab_label) : ?>
                            <a href="#<?php echo esc_attr($tab_id); ?>"
                               class="nav-tab ddwpt-tab"
                               data-tab="<?php echo esc_attr($tab_id); ?>">
                                <?php echo esc_html($tab_label); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>

                <?php foreach ($tabs as $tab_id => $tab_label) : ?>
                    <div class="ddwpt-tab-panel" data-tab="<?php echo esc_attr($tab_id); ?>" style="display: none;">
                        <?php
                        $tab_tweaks = $this->get_tweaks_for_tab($tab_id);
                        foreach ($tab_tweaks as $tweak) {
                            do_settings_sections($tweak['id']);
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
            </form>

            <hr />
            <p class="description" style="margin-top: 1rem;">
                WP Toolkit &mdash;
                <?php echo esc_html($total_count); ?> tweak<?php echo $total_count !== 1 ? 's' : ''; ?> loaded.
                Add new tweaks by dropping a PHP file into <code>inc/tweaks/</code>.
            </p>

        </div>

        <script>
        (function () {
            var tabs = document.querySelectorAll('.ddwpt-tab');
            var panels = document.querySelectorAll('.ddwpt-tab-panel');
            var defaultTab = '<?php echo esc_js($default_tab); ?>';
            var initialized = {};

            function initEditors(panel) {
                if (initialized[panel.dataset.tab]) return;
                initialized[panel.dataset.tab] = true;

                panel.querySelectorAll('.wp-editor-wrap').forEach(function (wrap) {
                    var id = wrap.id.replace(/^wp-/, '').replace(/-wrap$/, '');
                    if (typeof tinymce !== 'undefined' && tinymce.get(id)) return;
                    if (typeof tinyMCEPreInit !== 'undefined' && tinyMCEPreInit.mceInit[id] && typeof tinymce !== 'undefined') {
                        tinymce.init(tinyMCEPreInit.mceInit[id]);
                    }
                    if (typeof tinyMCEPreInit !== 'undefined' && tinyMCEPreInit.qtInit[id] && typeof quicktags !== 'undefined') {
                        quicktags(tinyMCEPreInit.qtInit[id]);
                        QTags._buttonsInit();
                    }
                });
            }

            function activate(tabId) {
                tabs.forEach(function (t) {
                    t.classList.toggle('nav-tab-active', t.dataset.tab === tabId);
                });
                panels.forEach(function (p) {
                    var active = p.dataset.tab === tabId;
                    p.style.display = active ? '' : 'none';
                    if (active) initEditors(p);
                });
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function (e) {
                    e.preventDefault();
                    var id = this.dataset.tab;
                    history.replaceState(null, '', '#' + id);
                    activate(id);
                });
            });

            // Persist active tab through save by appending hash to the referer field
            var form = document.querySelector('form[action="options.php"]');
            if (form) {
                form.addEventListener('submit', function () {
                    var referer = form.querySelector('input[name="_wp_http_referer"]');
                    if (referer) {
                        referer.value = referer.value.replace(/#.*$/, '') + location.hash;
                    }
                });
            }

            // Activate from hash or fall back to default
            var hash = location.hash.replace('#', '');
            activate(hash && document.querySelector('.ddwpt-tab-panel[data-tab="' + hash + '"]') ? hash : defaultTab);

            // Media picker fields
            document.querySelectorAll('.ddwpt-media-field').forEach(function (wrap) {
                var fieldName = wrap.dataset.field;
                var input = wrap.querySelector('input[type="hidden"]');
                var preview = wrap.querySelector('.ddwpt-media-preview');
                var removeBtn = wrap.querySelector('.ddwpt-media-remove');

                wrap.querySelector('.ddwpt-media-select').addEventListener('click', function () {
                    var frame = wp.media({
                        title: 'Select Image',
                        multiple: false,
                        library: { type: 'image' }
                    });
                    frame.on('select', function () {
                        var url = frame.state().get('selection').first().toJSON().url;
                        input.value = url;
                        preview.innerHTML = '<img src="' + url + '" style="max-width: 200px; max-height: 60px; display: block; margin-bottom: 8px;" />';
                        removeBtn.style.display = '';
                    });
                    frame.open();
                });

                removeBtn.addEventListener('click', function () {
                    input.value = '';
                    preview.innerHTML = '';
                    this.style.display = 'none';
                });
            });

            // Multiselect fields — sync selected values to hidden input as JSON
            document.querySelectorAll('.ddwpt-multiselect').forEach(function (sel) {
                var input = document.getElementById(sel.dataset.input);
                sel.addEventListener('change', function () {
                    var values = [];
                    for (var i = 0; i < sel.options.length; i++) {
                        if (sel.options[i].selected) values.push(sel.options[i].value);
                    }
                    input.value = JSON.stringify(values);
                });
            });

            // Sortable fields — deferred until jQuery UI is loaded
            if (typeof jQuery !== 'undefined') {
                jQuery(function ($) {
                    $('.ddwpt-sortable-wrap').each(function () {
                        var $wrap = $(this);
                        var inputId = $wrap.data('input');
                        var $visible = $wrap.find('.ddwpt-sortable-visible');
                        var $hidden = $wrap.find('.ddwpt-sortable-hidden');

                        function sync() {
                            var order = [];
                            var hiddenKeys = [];
                            $visible.children('li').each(function () { order.push($(this).data('key')); });
                            $hidden.children('li').each(function () { hiddenKeys.push($(this).data('key')); });
                            $('#' + inputId).val(JSON.stringify({ order: order, hidden: hiddenKeys }));
                        }

                        $visible.sortable({
                            connectWith: $hidden,
                            placeholder: 'ui-sortable-placeholder',
                            cursor: 'grabbing',
                            update: sync,
                            receive: sync
                        });

                        $hidden.sortable({
                            connectWith: $visible,
                            placeholder: 'ui-sortable-placeholder',
                            cursor: 'grabbing',
                            update: sync,
                            receive: sync
                        });
                    });
                });
            }
        })();
        </script>
        <?php
    }

    private function get_active_tweak_count()
    {
        $count = 0;
        foreach ($this->tweaks as $tweak) {
            foreach ($tweak['settings'] as $setting) {
                if (str_ends_with($setting['id'], '_enabled') && get_option($setting['id'])) {
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }

    private function get_all_tabs()
    {
        $preferred_order = ['general', 'dashboard', 'admin-bar', 'admin-tables', 'sidebar', 'bricks'];

        $tabs = [];
        $has_general = false;

        foreach ($this->tweaks as $tweak) {
            $tab = !empty($tweak['tab']) ? $tweak['tab'] : null;
            if ($tab) {
                $tabs[$tab] = ucfirst(str_replace('-', ' ', $tab));
            } else {
                $has_general = true;
            }
        }

        if ($has_general) {
            $tabs['general'] = 'General';
        }

        $sorted = [];
        foreach ($preferred_order as $key) {
            if (isset($tabs[$key])) {
                $sorted[$key] = $tabs[$key];
            }
        }
        foreach ($tabs as $key => $label) {
            if (!isset($sorted[$key])) {
                $sorted[$key] = $label;
            }
        }

        return $sorted;
    }

    private function get_tweaks_for_tab($tab_id)
    {
        return array_filter($this->tweaks, function ($tweak) use ($tab_id) {
            $tweak_tab = !empty($tweak['tab']) ? $tweak['tab'] : 'general';
            return $tweak_tab === $tab_id;
        });
    }
}

<?php

namespace DDWPTweaks\Tweaks;

return [
  "id" => "ddwpt_bricks_combine_panels",
  "label" => "Compact Editor Experience",
  "tab" => "bricks",

  "settings" => [
    [
      "id" => "enabled",
      "type" => "checkbox",
      "label" => "Enable tweak",
      "description" =>
        "Stacks the elements panel below the structure panel in a single resizable sidebar. Only applied for users with the Editor role — administrators see the default Bricks layout.",
    ],
    [
      "id"          => "accent_color",
      "type"        => "text",
      "label"       => "Builder accent colour",
      "default"     => "var(--accent)",
      "description" => "Overrides <code>--builder-color-accent</code>. Accepts any CSS value — hex, hsl, or a CSS variable reference.",
    ],
    [
      "id"          => "bg_accent_color",
      "type"        => "text",
      "label"       => "Builder accent background",
      "default"     => "var(--primary-trans-10)",
      "description" => "Overrides <code>--builder-bg-accent</code>. Accepts any CSS value — hex, hsl, or a CSS variable reference.",
    ],
  ],

  "callback" => function ($settings) {
    if (empty($settings["enabled"])) {
      return;
    }

    if (!defined("BRICKS_VERSION")) {
      return;
    }

    add_action("wp_head", function () use ($settings) {
      if (!function_exists("bricks_is_builder") || !bricks_is_builder()) {
        return;
      }

      $roles = wp_get_current_user()->roles ?? [];
      if (!in_array("editor", $roles, true)) {
        return;
      }

      $accent    = sanitize_text_field($settings['accent_color']    ?? 'var(--accent)');
      $bg_accent = sanitize_text_field($settings['bg_accent_color'] ?? 'var(--primary-trans-10)');
      ?>
            <style>
            #bricks-preview,
            body[data-builder-window="main"]{
              background-color: #161a1d;
              --builder-color-accent: <?php echo esc_attr($accent); ?>;
              --builder-bg-accent: <?php echo esc_attr($bg_accent); ?>;
            }
            #bricks-structure {
                display: flex;
                flex-direction: column;
                overflow: hidden;
                max-height: calc(100vh - var(--builder-toolbar-height));
                border-right: 0px;
            }
            #bricks-structure .bricks-panel {
              top: 0;
            }
            #bricks-structure .panel-content {
                flex: 1;
                overflow-y: auto;
                min-height: 0;
                margin-top: 10px;
            }
            #bricks-structure > #bricks-panel-header {
              display: none;
            }

            #bricks-panel {
                position: relative;
                width: 100% !important;
                flex-shrink: 0;
                border-inline: 0;
                border-top: 1px solid var(--builder-border-color);
                padding-inline: 8px;
            }

            #bricks-toolbar .active {
              background-color: rgb(255 255 255 / 10%);
              border: 1px solid background-color: rgb(255 255 255 / 10%);
              color: var(--builder-color-accent);
            }

            #bricks-structure .element.active>.structure-item,
            #bricks-panel-pages .results>li.active {
              background-color: var(--builder-bg-accent);
              box-shadow: inset 0 0 0 1px var(--builder-color-accent);
              color: var(--builder-color-accent);
            }

            .group-wrapper.breakpoints {
              border: 1px solid var(--builder-border-color);
              margin: 8px!important;
              border-radius: 5rem;
              overflow: clip;
            }

            .ddwpt-panel-handle {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 6px;
                cursor: ns-resize;
                z-index: 100;
                margin-top: -3px;
            }

            .ddwpt-panel-handle:hover,
            .ddwpt-panel-handle.is-dragging {
                background: var(--builder-color-accent, rgba(255,255,255,0.15));
                opacity: 0.5;
            }
            </style>
            <script>
            window.addEventListener('load', function () {
                setTimeout(function () {
                    var panel     = document.getElementById('bricks-panel');
                    var structure = document.getElementById('bricks-structure');
                    if (!panel || !structure) return;

                    var panelContent = structure.querySelector('.panel-content');
                    if (!panelContent) return;

                    panelContent.after(panel);

                    // Cookie helpers
                    var COOKIE = 'ddwpt_panel_height';
                    function getCookie(name) {
                        var match = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
                        return match ? parseInt(match[1], 10) : null;
                    }
                    function setCookie(name, value) {
                        document.cookie = name + '=' + value + ';path=/;max-age=' + (60 * 60 * 24 * 365);
                    }

                    // Restore saved height or fall back to 40% of container
                    var saved = getCookie(COOKIE);
                    panel.style.height = (saved || Math.round(structure.offsetHeight * 0.4)) + 'px';

                    // Drag handle
                    var handle = document.createElement('div');
                    handle.className = 'ddwpt-panel-handle';
                    panel.prepend(handle);

                    handle.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        handle.classList.add('is-dragging');

                        var startY      = e.clientY;
                        var startHeight = panel.offsetHeight;

                        function onMouseMove(e) {
                            var height = Math.max(80, startHeight + (startY - e.clientY));
                            panel.style.height = height + 'px';
                        }

                        function onMouseUp() {
                            handle.classList.remove('is-dragging');
                            setCookie(COOKIE, panel.offsetHeight);
                            document.removeEventListener('mousemove', onMouseMove);
                            document.removeEventListener('mouseup', onMouseUp);
                        }

                        document.addEventListener('mousemove', onMouseMove);
                        document.addEventListener('mouseup', onMouseUp);
                    });
                }, 300);
            });
            </script>
            <?php
    });
  },
];

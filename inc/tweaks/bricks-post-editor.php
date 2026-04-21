<?php

namespace DDWPTweaks\Tweaks;

return [
  "id" => "ddwpt_bricks_post_editor",
  "label" => "Bricks Post Editor",
  "tab" => "bricks",

  "settings" => [
    [
      "id" => "enabled",
      "type" => "checkbox",
      "label" => "Enable tweak",
      "description" =>
        'Hide the block editor and show an "Edit with Bricks" button for post types managed by Bricks Builder.',
    ],
    [
      "id" => "acf_post_types",
      "type" => "multiselect",
      "label" => "ACF-managed post types",
      "description" => "Hide the block editor and show an ACF notice for these post types.",
      "default" => "",
      "options" => function () {
        $post_types = get_post_types(["public" => true, "show_ui" => true], "objects");
        $bricks_types = class_exists("\Bricks\Database")
          ? \Bricks\Database::get_setting("postTypes", [])
          : ["page"];

        $options = [];
        foreach ($post_types as $slug => $obj) {
          if (in_array($slug, $bricks_types, true)) {
            continue;
          }
          $options[$slug] = $obj->label ?: $slug;
        }
        return $options;
      },
    ],
  ],

  "callback" => function ($settings) {
    if (empty($settings["enabled"])) {
      return;
    }

    $get_bricks_types = function () {
      if (class_exists("\Bricks\Database")) {
        return \Bricks\Database::get_setting("postTypes", []);
      }
      return ["page"];
    };

    $raw_acf = $settings["acf_post_types"] ?? "";
    $acf_types = is_array($raw_acf)
      ? $raw_acf
      : (json_decode($raw_acf, true) ?: []);

    add_action(
      "admin_enqueue_scripts",
      function ($hook) use ($get_bricks_types, $acf_types) {
        global $post;

        if ($hook !== "post.php" && $hook !== "post-new.php") {
          return;
        }

        if (!isset($post)) {
          return;
        }

        $is_bricks = in_array($post->post_type, $get_bricks_types(), true);
        $is_acf = in_array($post->post_type, $acf_types, true);

        if (!$is_bricks && !$is_acf) {
          return;
        }

        wp_add_inline_style(
          "wp-admin",
          '
                .block-editor-writing-flow,
                .edit-post-visual-editor__post-title-wrapper,
                .edit-post-visual-editor,
                .editor-document-tools__left,
                .editor-post-card-panel,
                .edit-post-meta-boxes-main__presenter,
                .components-panel__header.interface-complementary-area-header.editor-sidebar__panel-tabs {
                  display: none !important;
                }
                .admin-ui-navigable-region.edit-post-meta-boxes-main {
                  min-height: 100%!important;
                  padding-top: 0;
                }
                #bricks_edit_button .postbox-header {
                  border-top: 0px;
                }
            ',
        );

        if ($is_bricks) {
          wp_add_inline_script(
            "wp-admin",
            '
                document.addEventListener( "DOMContentLoaded", function() {
                    var box = document.getElementById( "bricks_edit_button" );
                    if ( box ) {
                        box.classList.remove( "closed" );
                    }
                } );
            ',
          );
        }
      },
    );

    add_action("add_meta_boxes", function () use ($get_bricks_types, $acf_types) {
      foreach ($get_bricks_types() as $post_type) {
        add_meta_box(
          "bricks_edit_button",
          "Page Content",
          function ($post) {
            echo '<div style="padding: 10px 0; text-align: center;">';
            echo '<p style="margin-bottom: 12px; color: #666;">This page is managed with Bricks Builder.<br><br>Edit using the button in the top left</p>';
            echo "</div>";
          },
          $post_type,
          "normal",
          "high",
        );
      }

    });
  },
];

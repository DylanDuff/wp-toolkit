<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_bricks_post_editor',
    'label' => 'Bricks Post Editor',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Hide the block editor and show an "Edit with Bricks" button for post types managed by Bricks Builder.',
        ],
    ],

    'callback' => function ( $settings ) {
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $get_post_types = function () {
            if ( class_exists( '\Bricks\Database' ) ) {
                return \Bricks\Database::get_setting( 'postTypes', [] );
            }
            return [ 'page' ];
        };

        add_action( 'admin_enqueue_scripts', function ( $hook ) use ( $get_post_types ) {
            global $post;

            if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
                return;
            }

            if ( ! isset( $post ) || ! in_array( $post->post_type, $get_post_types(), true ) ) {
                return;
            }

            wp_add_inline_style( 'wp-admin', '
                .block-editor-writing-flow,
                .edit-post-visual-editor__post-title-wrapper,
                .edit-post-visual-editor,
                .editor-document-tools__left,
                .components-panel__header.interface-complementary-area-header.editor-sidebar__panel-tabs {
                    display: none !important;
                }
                #bricks_edit_button { order: -1; }
                #bricks_edit_button .postbox-header { display: none; }
                #bricks_edit_button.closed .inside { display: block; }
            ' );

            wp_add_inline_script( 'wp-admin', '
                document.addEventListener( "DOMContentLoaded", function() {
                    var box = document.getElementById( "bricks_edit_button" );
                    if ( box ) {
                        box.classList.remove( "closed" );
                    }
                } );
            ' );
        } );

        add_action( 'add_meta_boxes', function () use ( $get_post_types ) {
            foreach ( $get_post_types() as $post_type ) {
                add_meta_box(
                    'bricks_edit_button',
                    'Page Builder',
                    function ( $post ) {
                        $bricks_url = add_query_arg( [ 'bricks' => 'run' ], get_permalink( $post->ID ) );
                        echo '<div style="padding: 10px 0; text-align: center;">';
                        echo '<p style="margin-bottom: 12px; color: #666;">This page is managed with Bricks Builder.</p>';
                        echo '<a href="' . esc_url( $bricks_url ) . '" style="background-color:#ffd64f; border:none; box-shadow:none; color:#000; font-weight:700; height:auto; line-height:1; margin-left:10px; padding:10px; text-shadow:none; text-transform:uppercase; vertical-align:baseline;" class="button button-hero">Edit with Bricks</a>';
                        echo '</div>';
                    },
                    $post_type,
                    'normal',
                    'high'
                );
            }
        } );
    },
];

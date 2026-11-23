<?php

namespace DDWPTweaks\Tweaks;

// Mirrors each Fluent Forms form into one post of a non-routable CPT so forms
// can drive Bricks query loops and act as popup context. The CPT is public
// only so Bricks lists it; every frontend route is switched off.
// See docs/fluent-forms-mirror.md.

const FLUENTFORMS_MIRROR_POST_TYPE = 'ff_form';
const FLUENTFORMS_MIRROR_META_KEY  = 'fluentform_id';
const FLUENTFORMS_MIRROR_SYNCED    = 'ddwpt_fluentforms_mirror_synced';

function fluentforms_mirror_register()
{
    register_post_type(FLUENTFORMS_MIRROR_POST_TYPE, [
        'labels' => [
            'name'          => 'Form Mirrors',
            'singular_name' => 'Form Mirror',
        ],
        // `public` stays true so Bricks lists it in Query Loop post types,
        // everything below switches off the frontend.
        'public'              => true,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'show_in_nav_menus'   => false,
        'show_in_admin_bar'   => false,
        'show_ui'             => true,
        'show_in_menu'        => 'fluent_forms',
        'show_in_rest'        => true,
        'supports'            => ['title', 'custom-fields'],
        'map_meta_cap'        => true,
        'capabilities'        => [
            // Posts are created by the sync only.
            'create_posts' => 'do_not_allow',
        ],
    ]);

    register_post_meta(FLUENTFORMS_MIRROR_POST_TYPE, FLUENTFORMS_MIRROR_META_KEY, [
        'type'         => 'integer',
        'single'       => true,
        'show_in_rest' => true,
    ]);
}

/**
 * Reconcile the CPT against the Fluent Forms table: create missing mirrors,
 * update titles and status, delete orphans and duplicates.
 */
function fluentforms_mirror_sync()
{
    global $wpdb;

    $table = $wpdb->prefix . 'fluentform_forms';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return;
    }

    $forms = $wpdb->get_results("SELECT id, title, status FROM {$table}", OBJECT_K);

    $mirrors = get_posts([
        'post_type'      => FLUENTFORMS_MIRROR_POST_TYPE,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ]);

    $existing = [];
    foreach ($mirrors as $post) {
        $form_id = (int) get_post_meta($post->ID, FLUENTFORMS_MIRROR_META_KEY, true);

        if (!$form_id || !isset($forms[$form_id]) || isset($existing[$form_id])) {
            wp_delete_post($post->ID, true);
            continue;
        }

        $existing[$form_id] = $post;
    }

    foreach ($forms as $form_id => $form) {
        $data = [
            'post_type'   => FLUENTFORMS_MIRROR_POST_TYPE,
            'post_title'  => $form->title,
            'post_status' => $form->status === 'published' ? 'publish' : 'draft',
            'menu_order'  => (int) $form_id,
        ];

        if (isset($existing[$form_id])) {
            $post = $existing[$form_id];
            if ($post->post_title === $data['post_title'] && $post->post_status === $data['post_status']) {
                continue;
            }
            $data['ID'] = $post->ID;
            wp_update_post($data);
            continue;
        }

        $data['meta_input'] = [FLUENTFORMS_MIRROR_META_KEY => (int) $form_id];
        wp_insert_post($data);
    }
}

/**
 * Queue a sync for the end of the request so multiple triggers run it once.
 */
function fluentforms_mirror_queue_sync()
{
    $callback = __NAMESPACE__ . '\\fluentforms_mirror_sync';
    if (!has_action('shutdown', $callback)) {
        add_action('shutdown', $callback);
    }
}

return [
    'id'    => 'ddwpt_fluentforms_mirror',
    'label' => 'Fluent Forms Mirror',
    'tab'   => 'integrations',
    'group' => 'fluent-forms',
    'description' => 'Mirror Fluent Forms as posts for Bricks loops and popups.',
    'tags'  => ['Not public', 'Bricks loops'],
    'requires' => ['Fluent Forms'],

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Mirror every Fluent Forms form into a non-routable <code>ff_form</code> post (meta <code>fluentform_id</code>) so forms can be used in Bricks query loops and as popup context. Requires Fluent Forms. No archive, single pages or rewrite rules; excluded from search.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled']) || !defined('FLUENTFORM')) {
            return;
        }

        fluentforms_mirror_register();

        // Belt and braces: stop any direct ?p= or preview access on the frontend.
        add_action('template_redirect', function () {
            if (is_singular(FLUENTFORMS_MIRROR_POST_TYPE) || is_post_type_archive(FLUENTFORMS_MIRROR_POST_TYPE)) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
            }
        });

        // Fluent Forms lifecycle hooks (current and legacy names).
        foreach ([
            'fluentform/inserted_new_form',
            'fluentform_inserted_new_form',
            'fluentform/form_duplicated',
            'fluentform_form_duplicated',
            'fluentform/after_form_delete',
            'fluentform_after_form_delete',
        ] as $hook) {
            add_action($hook, __NAMESPACE__ . '\\fluentforms_mirror_queue_sync');
        }

        add_action('admin_init', function () {
            // Initial fill the first time the tweak runs.
            if (!get_option(FLUENTFORMS_MIRROR_SYNCED)) {
                fluentforms_mirror_queue_sync();
                update_option(FLUENTFORMS_MIRROR_SYNCED, 1, false);
                return;
            }

            // Renames and status changes have no reliable hook, so catch them
            // whenever a Fluent Forms or mirror admin screen loads.
            $page      = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';

            if (str_starts_with($page, 'fluent_forms') || $post_type === FLUENTFORMS_MIRROR_POST_TYPE) {
                fluentforms_mirror_queue_sync();
            }
        });

        // WP adds CPT submenus before Fluent Forms adds its own, so the mirror
        // list lands first and hijacks the top-level Fluent Forms link (WP
        // points it at the first submenu item). Move it to the end.
        add_action('admin_menu', function () {
            global $submenu;

            $parent = 'fluent_forms';
            $slug   = 'edit.php?post_type=' . FLUENTFORMS_MIRROR_POST_TYPE;
            if (empty($submenu[$parent])) {
                return;
            }

            foreach ($submenu[$parent] as $position => $item) {
                if (($item[2] ?? '') === $slug) {
                    unset($submenu[$parent][$position]);
                    $submenu[$parent][] = $item;
                    break;
                }
            }
        }, PHP_INT_MAX);

        // Form ID column in the mirror list table.
        add_filter('manage_' . FLUENTFORMS_MIRROR_POST_TYPE . '_posts_columns', function ($columns) {
            $columns[FLUENTFORMS_MIRROR_META_KEY] = 'Form ID';
            return $columns;
        });

        add_action('manage_' . FLUENTFORMS_MIRROR_POST_TYPE . '_posts_custom_column', function ($column, $post_id) {
            if ($column === FLUENTFORMS_MIRROR_META_KEY) {
                echo (int) get_post_meta($post_id, FLUENTFORMS_MIRROR_META_KEY, true);
            }
        }, 10, 2);
    },
];

<?php

namespace DDWPTweaks\Tweaks;

function handle_duplicate_post()
{
    $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

    if (!$post_id || !current_user_can('edit_posts')) {
        wp_die('Unauthorized.');
    }

    check_admin_referer('ddwpt_duplicate_' . $post_id);

    $original = get_post($post_id);

    if (!$original) {
        wp_die('Post not found.');
    }

    $new_id = wp_insert_post([
        'post_title'   => $original->post_title . ' (Copy)',
        'post_content' => $original->post_content,
        'post_excerpt' => $original->post_excerpt,
        'post_status'  => 'draft',
        'post_type'    => $original->post_type,
        'post_author'  => get_current_user_id(),
        'post_parent'  => $original->post_parent,
        'menu_order'   => $original->menu_order,
    ]);

    if (is_wp_error($new_id)) {
        wp_die('Could not duplicate post.');
    }

    // Clone all post meta
    $meta = get_post_meta($post_id);
    foreach ($meta as $key => $values) {
        foreach ($values as $value) {
            add_post_meta($new_id, $key, maybe_unserialize($value));
        }
    }

    // Clone taxonomies
    $taxonomies = get_object_taxonomies($original->post_type);
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (!is_wp_error($terms)) {
            wp_set_object_terms($new_id, $terms, $taxonomy);
        }
    }

    wp_safe_redirect(admin_url('edit.php?post_type=' . $original->post_type));
    exit;
}

return [
    'id'    => 'ddwpt_duplicate_post',
    'label' => 'Duplicate Post',
    'tab'   => 'admin-tables',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Add a "Duplicate" link to post, page, and custom post type row actions.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Add the row action link
        $add_link = function ($actions, $post) {
            if (!current_user_can('edit_posts')) return $actions;

            $url = wp_nonce_url(
                admin_url('admin-post.php?action=ddwpt_duplicate_post&post_id=' . $post->ID),
                'ddwpt_duplicate_' . $post->ID
            );

            $actions['ddwpt_duplicate'] = '<a href="' . esc_url($url) . '">Duplicate</a>';
            return $actions;
        };

        add_filter('post_row_actions', $add_link, 10, 2);
        add_filter('page_row_actions', $add_link, 10, 2);

        // Handle the duplication
        add_action('admin_post_ddwpt_duplicate_post', __NAMESPACE__ . '\\handle_duplicate_post');
    },
];

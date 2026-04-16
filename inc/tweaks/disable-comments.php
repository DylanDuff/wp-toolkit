<?php

namespace DDWPTweaks\Tweaks;

function remove_comments_admin_bar($wp_admin_bar)
{
    $wp_admin_bar->remove_node('comments');
}

function remove_comments_from_post_types()
{
    foreach (get_post_types() as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }
}

function remove_comments_admin_menu()
{
    remove_menu_page('edit-comments.php');
    remove_submenu_page('options-general.php', 'options-discussion.php');
}

function redirect_comments_admin()
{
    global $pagenow;
    if ($pagenow === 'edit-comments.php' || $pagenow === 'options-discussion.php') {
        wp_safe_redirect(admin_url());
        exit;
    }
}

function remove_comments_dashboard_widget()
{
    remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
}

return [
    'id'    => 'ddwpt_disable_comments',
    'label' => 'Disable Comments',
    'tab'   => 'general',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Completely disable comments site-wide, regardless of individual post settings.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Close comments and pings on the frontend
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);

        // Hide existing comments
        add_filter('comments_array', '__return_empty_array', 10, 2);

        // Remove comment counts from queries
        add_filter('wp_count_comments', function () {
            return (object) [
                'approved'       => 0,
                'moderated'      => 0,
                'spam'           => 0,
                'trash'          => 0,
                'post-trashed'   => 0,
                'total_comments' => 0,
                'all'            => 0,
            ];
        });

        // Remove comments support from all post types
        add_action('init', __NAMESPACE__ . '\\remove_comments_from_post_types', 9999);

        // Remove admin menu items and redirect
        add_action('admin_menu', __NAMESPACE__ . '\\remove_comments_admin_menu', 9999);
        add_action('admin_init', __NAMESPACE__ . '\\redirect_comments_admin');

        // Remove comments from admin bar
        add_action('admin_bar_menu', __NAMESPACE__ . '\\remove_comments_admin_bar', 9999);

        // Remove recent comments dashboard widget
        add_action('wp_dashboard_setup', __NAMESPACE__ . '\\remove_comments_dashboard_widget');

        // Remove comment feed links from <head>
        remove_action('wp_head', 'feed_links_extra', 3);
    },
];

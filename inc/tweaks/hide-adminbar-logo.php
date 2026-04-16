<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_hide_adminbar_logo',
    'label' => 'Hide Admin Bar Logo',
    'tab'   => 'admin-bar',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Remove the WordPress logo and its dropdown menu from the admin bar.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('admin_bar_menu', function ($wp_admin_bar) {
            $wp_admin_bar->remove_node('wp-logo');
        }, 999);
    },
];

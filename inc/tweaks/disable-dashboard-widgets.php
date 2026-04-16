<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_disable_dashboard_widgets',
    'label' => 'Disable Dashboard Widgets',
    'tab'   => 'dashboard',

    'settings' => [
        [
            'id'          => 'welcome',
            'type'        => 'checkbox',
            'label'       => 'Welcome panel',
            'description' => 'Hide the "Welcome to WordPress" panel.',
        ],
        [
            'id'          => 'at_a_glance',
            'type'        => 'checkbox',
            'label'       => 'At a Glance',
            'description' => 'Hide the content summary widget.',
        ],
        [
            'id'          => 'quick_draft',
            'type'        => 'checkbox',
            'label'       => 'Quick Draft',
            'description' => 'Hide the Quick Draft widget.',
        ],
        [
            'id'          => 'activity',
            'type'        => 'checkbox',
            'label'       => 'Activity',
            'description' => 'Hide the recent activity widget.',
        ],
        [
            'id'          => 'events_news',
            'type'        => 'checkbox',
            'label'       => 'WordPress Events & News',
            'description' => 'Hide the events and news feed.',
        ],
        [
            'id'          => 'site_health',
            'type'        => 'checkbox',
            'label'       => 'Site Health Status',
            'description' => 'Hide the site health widget.',
        ],
    ],

    'callback' => function ($settings) {
        $any_active = false;
        foreach ($settings as $val) {
            if (!empty($val)) {
                $any_active = true;
                break;
            }
        }
        if (!$any_active) return;

        if (!empty($settings['welcome'])) {
            remove_action('welcome_panel', 'wp_welcome_panel');
        }

        add_action('wp_dashboard_setup', function () use ($settings) {
            if (!empty($settings['at_a_glance'])) {
                remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
            }
            if (!empty($settings['quick_draft'])) {
                remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
            }
            if (!empty($settings['activity'])) {
                remove_meta_box('dashboard_activity', 'dashboard', 'normal');
            }
            if (!empty($settings['events_news'])) {
                remove_meta_box('dashboard_primary', 'dashboard', 'side');
            }
            if (!empty($settings['site_health'])) {
                remove_meta_box('dashboard_site_health', 'dashboard', 'normal');
            }
        });
    },
];

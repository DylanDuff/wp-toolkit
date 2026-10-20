<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_auto_open_plugin_upload',
    'label' => 'Auto-open Plugin Upload',
    'tab'   => 'general',
    'group' => 'plugins',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Show the "Upload Plugin" box straight away when opening Plugins → Add New, instead of needing the button click.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Only on the landing view (no tab, i.e. Featured). Browsing another
        // tab or searching keeps the box closed so it doesn't push results
        // down; the button still toggles it as usual.
        $is_landing = static function () {
            global $pagenow;
            return $pagenow === 'plugin-install.php'
                && empty($_GET['tab'])
                && current_user_can('upload_plugins');
        };

        // Core's plugin-install.js toggles `show-upload-view` on <body> when
        // the button is clicked, so starting with the class reveals the box
        // and the button keeps working as a toggle.
        add_filter('admin_body_class', function ($classes) use ($is_landing) {
            return $is_landing() ? $classes . ' show-upload-view' : $classes;
        });

        // Core sets aria-expanded="false" on DOM ready; correct it after.
        add_action('admin_footer', function () use ($is_landing) {
            if (!$is_landing()) return;
            echo "<script>jQuery(function($){ $('.upload-view-toggle').attr('aria-expanded', 'true'); });</script>";
        });
    },
];

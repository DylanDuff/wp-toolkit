<?php

namespace DDWPTweaks\Tweaks;

// Must run before ACF initialises (plugins_loaded), not on init.
if (get_option('ddwpt_acf_abilities_api_enabled')) {
    add_filter('acf/settings/enable_acf_ai', '__return_true');
}

return [
    'id'    => 'ddwpt_acf_abilities_api',
    'label' => 'Enable ACF Abilities',
    'tab'   => 'ai',
    'group' => 'abilities',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Enables the ACF AI Abilities API via the <code>acf/settings/enable_acf_ai</code> filter.',
        ],
    ],

    'callback' => function ($settings) {
        // Filter is registered at file-load time above; nothing to do here.
    },
];

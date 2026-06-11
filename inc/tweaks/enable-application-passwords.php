<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_enable_application_passwords',
    'label' => 'Enable Application Passwords',
    'tab'   => 'general',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Forces Application Passwords to be available site-wide. WordPress disables them on non-HTTPS environments by default.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('wp_is_application_passwords_available', '__return_true');
    },
];

<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_change_howdy',
    'label' => 'Change "Howdy" Greeting',
    'tab'   => 'admin-bar',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Replace the "Howdy" greeting in the admin bar.',
        ],
        [
            'id'          => 'greeting',
            'type'        => 'text',
            'label'       => 'Greeting text',
            'default'     => 'Hi',
            'description' => 'The text shown before the user\'s display name. Leave empty to show just the name.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $greeting = trim($settings['greeting'] ?? '');

        // Intercept the "Howdy, %s" translation string before the admin bar builds it
        add_filter('gettext', function ($translation, $text, $domain) use ($greeting) {
            if ($text !== 'Howdy, %s' || $domain !== 'default') {
                return $translation;
            }
            return $greeting !== '' ? $greeting . ', %s' : '%s';
        }, 10, 3);
    },
];

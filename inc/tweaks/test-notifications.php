<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_test_notifications',
    'label' => 'Test Notifications',
    'tab'   => 'debug',

    'settings' => [
        [
            'id'          => 'success',
            'type'        => 'checkbox',
            'label'       => 'Success notice',
            'description' => 'Show a success notification on every admin page load.',
        ],
        [
            'id'          => 'error',
            'type'        => 'checkbox',
            'label'       => 'Error notice',
            'description' => 'Show an error notification on every admin page load.',
        ],
        [
            'id'          => 'warning',
            'type'        => 'checkbox',
            'label'       => 'Warning notice',
            'description' => 'Show a warning notification on every admin page load.',
        ],
        [
            'id'          => 'info',
            'type'        => 'checkbox',
            'label'       => 'Info notice',
            'description' => 'Show an info notification on every admin page load.',
        ],
    ],

    'callback' => function ($settings) {
        $types = ['success', 'error', 'warning', 'info'];
        $active = [];

        foreach ($types as $type) {
            if (!empty($settings[$type])) {
                $active[] = $type;
            }
        }

        if (empty($active)) return;

        add_action('admin_notices', function () use ($active) {
            $messages = [
                'success' => 'This is a test <strong>success</strong> notice.',
                'error'   => 'This is a test <strong>error</strong> notice.',
                'warning' => 'This is a test <strong>warning</strong> notice.',
                'info'    => 'This is a test <strong>info</strong> notice.',
            ];

            foreach ($active as $type) {
                echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . $messages[$type] . '</p></div>';
            }
        });
    },
];

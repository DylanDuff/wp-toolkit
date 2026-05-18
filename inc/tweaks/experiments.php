<?php

return [
    'id'       => 'ddwpt_experiments',
    'label'    => 'Experiments',
    'tab'      => 'settings',
    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable Experiments',
            'description' => 'Reveals the Experimental tab in the plugin navigation.',
        ],
    ],
    'callback' => function ( $settings ) {
        // Experimental tab visibility is handled in the admin UI layer.
    },
];

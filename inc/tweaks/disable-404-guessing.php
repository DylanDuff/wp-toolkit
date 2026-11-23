<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_disable_404_guessing',
    'label' => 'Disable 404 Guessing',
    'tab'   => 'seo',
    'group' => 'urls',
    'description' => 'Return a 404 instead of redirecting to a similar URL.',
    'tags'  => ['Frontend'],

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Show a 404 for a URL that does not exist, instead of WordPress redirecting to a post or page with a similar slug.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('do_redirect_guess_404_permalink', '__return_false');
    },
];

<?php

namespace DDWPTweaks\Tweaks;

// Bricks builds its control options on `init` before the tweak loader runs
// callbacks, so the filter is registered at file-load time (plugins_loaded).
if (get_option('ddwpt_bricks_term_order_enabled')) {
    add_filter('bricks/setup/control_options', static function ($options) {
        $options['termsOrderBy']['term_order'] = esc_html__('Term order', 'bricks');

        return $options;
    });
}

return [
    'id'    => 'ddwpt_bricks_term_order',
    'label' => 'Term Order Sorting',
    'tab'   => 'bricks',
    'group' => 'builder',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Adds "Term order" to the Order by options of Bricks term query loops, so terms can follow the custom order set by a term ordering plugin.',
        ],
    ],

    // Registration happens at file-load time above.
    'callback' => static function ($settings) {
    },
];

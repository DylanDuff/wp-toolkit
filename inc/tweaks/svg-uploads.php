<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_svg_uploads',
    'label' => 'Enable SVG Uploads',
    'tab'   => 'media',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Allow SVG files to be uploaded to the media library. Only admins can upload SVGs.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Allow SVG MIME type for administrators
        add_filter('upload_mimes', function ($mimes) {
            if (current_user_can('manage_options')) {
                $mimes['svg']  = 'image/svg+xml';
                $mimes['svgz'] = 'image/svg+xml';
            }
            return $mimes;
        });

        // Fix SVG detection in WP file type check
        add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
            if (!current_user_can('manage_options')) return $data;

            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext === 'svg' || $ext === 'svgz') {
                $data['ext']  = $ext;
                $data['type'] = 'image/svg+xml';
            }
            return $data;
        }, 10, 4);

        // Display SVGs correctly in the media library
        add_action('admin_head', function () {
            echo '<style>
                .attachment-266x266, .thumbnail img[src$=".svg"] {
                    width: 100% !important;
                    height: auto !important;
                }
            </style>';
        });
    },
];

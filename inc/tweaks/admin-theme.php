<?php

namespace DDWPTweaks\Tweaks;

// Scan the themes directory and parse CSS file headers
$themes_dir = dirname(__DIR__) . '/themes/';
$theme_files = glob($themes_dir . '*.css');

$options = ['' => 'None (default WordPress)'];
$theme_map = []; // slug => file path

foreach ($theme_files as $file) {
    $header = '';
    $handle = fopen($file, 'r');
    if ($handle) {
        $header = fread($handle, 2048);
        fclose($handle);
    }

    $name = '';
    $description = '';

    if (preg_match('/Theme Name:\s*(.+)/i', $header, $m)) {
        $name = trim($m[1]);
    }
    if (preg_match('/Description:\s*(.+)/i', $header, $m)) {
        $description = trim($m[1]);
    }

    if (empty($name)) continue;

    $slug = basename($file, '.css');
    $label = $description ? $name . ' — ' . $description : $name;
    $options[$slug] = $label;
    $theme_map[$slug] = $file;
}

return [
    'id'    => 'ddwpt_admin_theme',
    'label' => 'Admin Theme',
    'tab'   => 'themes',

    'settings' => [
        [
            'id'          => 'active',
            'type'        => 'select',
            'label'       => 'Active theme',
            'default'     => '',
            'options'     => $options,
            'description' => 'Select an admin theme. Add new themes by dropping a CSS file into inc/themes/.',
        ],
    ],

    'callback' => function ($settings) use ($theme_map) {
        $active = $settings['active'] ?? '';

        if (empty($active) || !isset($theme_map[$active])) {
            return;
        }

        $file = $theme_map[$active];
        $slug = basename($file, '.css');

        add_action('admin_enqueue_scripts', function () use ($file, $slug) {
            wp_enqueue_style(
                'ddwpt-theme-' . $slug,
                plugins_url('inc/themes/' . basename($file), dirname(__DIR__, 2) . '/plugin.php'),
                [],
                filemtime($file)
            );
        });
    },
];

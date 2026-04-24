<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_motion_library',
    'label' => 'Motion Animations',
    'tab'   => 'animations',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Load the Motion animation library and activate preset animations on the frontend.',
        ],
        [
            'id'      => 'version',
            'type'    => 'text',
            'label'   => 'Motion Version',
            'default' => '11.13.5',
        ],
        [
            'id'      => 'bundle',
            'type'    => 'select',
            'label'   => 'Bundle Type',
            'default' => 'full',
            'options' => [
                'full' => 'Full (2.6kb) — animate, scroll, press, hover, inView',
                'mini' => 'Mini (2.3kb) — animate only',
            ],
        ],
        [
            'id'          => 'fade_in',
            'type'        => 'text',
            'label'       => 'Fade In on Load',
            'default'     => '',
            'description' => 'CSS selector. Elements fade from invisible to visible on page load.',
        ],
        [
            'id'          => 'slide_up',
            'type'        => 'text',
            'label'       => 'Slide Up on Load',
            'default'     => '',
            'description' => 'CSS selector. Elements slide up and fade in on page load.',
        ],
        [
            'id'          => 'scroll_reveal',
            'type'        => 'text',
            'label'       => 'Scroll Reveal',
            'default'     => '',
            'description' => 'CSS selector. Elements fade and slide in when scrolled into view. Requires full bundle.',
        ],
        [
            'id'          => 'press_scale',
            'type'        => 'text',
            'label'       => 'Press Scale',
            'default'     => '',
            'description' => 'CSS selector. Elements scale down when pressed, spring back on release. Requires full bundle.',
        ],
        [
            'id'          => 'hover_grow',
            'type'        => 'text',
            'label'       => 'Hover Grow',
            'default'     => '',
            'description' => 'CSS selector. Elements grow slightly on hover. Requires full bundle.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $version = sanitize_text_field($settings['version'] ?? '') ?: '11.13.5';
        $is_mini = ($settings['bundle'] ?? 'full') === 'mini';

        $presets = [
            'fade_in'       => $settings['fade_in'] ?? '',
            'slide_up'      => $settings['slide_up'] ?? '',
            'scroll_reveal' => $settings['scroll_reveal'] ?? '',
            'press_scale'   => $settings['press_scale'] ?? '',
            'hover_grow'    => $settings['hover_grow'] ?? '',
        ];

        $presets = array_filter($presets);
        if (empty($presets)) {
            return;
        }

        $needs_full = !empty($presets['scroll_reveal'])
                   || !empty($presets['press_scale'])
                   || !empty($presets['hover_grow']);

        if ($needs_full) {
            $is_mini = false;
        }

        $bundle_path = $is_mini ? 'mini/' : '';
        $cdn_url = "https://cdn.jsdelivr.net/npm/motion@{$version}/{$bundle_path}+esm";

        add_action('wp_footer', function () use ($cdn_url, $presets, $is_mini) {
            $imports = ['animate'];
            if (!$is_mini) {
                if (!empty($presets['scroll_reveal'])) $imports[] = 'inView';
                if (!empty($presets['press_scale']))   $imports[] = 'press';
                if (!empty($presets['hover_grow']))     $imports[] = 'hover';
            }
            $import_list = implode(', ', $imports);

            $js_lines = [];

            if (!empty($presets['fade_in'])) {
                $sel = wp_json_encode($presets['fade_in']);
                $js_lines[] = "document.querySelectorAll({$sel}).forEach(el => el.style.opacity = '0');";
                $js_lines[] = "animate({$sel}, { opacity: [0, 1] }, { duration: 0.6, delay: 0.1 });";
            }

            if (!empty($presets['slide_up'])) {
                $sel = wp_json_encode($presets['slide_up']);
                $js_lines[] = "document.querySelectorAll({$sel}).forEach(el => { el.style.opacity = '0'; el.style.transform = 'translateY(20px)'; });";
                $js_lines[] = "animate({$sel}, { opacity: [0, 1], y: [20, 0] }, { duration: 0.6, delay: 0.15 });";
            }

            if (!empty($presets['scroll_reveal'])) {
                $sel = wp_json_encode($presets['scroll_reveal']);
                $js_lines[] = "document.querySelectorAll({$sel}).forEach(el => el.style.opacity = '0');";
                $js_lines[] = "inView({$sel}, (info) => { animate(info.target, { opacity: [0, 1], y: [20, 0] }, { duration: 0.5 }); }, { amount: 0.2 });";
            }

            if (!empty($presets['press_scale'])) {
                $sel = wp_json_encode($presets['press_scale']);
                $js_lines[] = "press({$sel}, (el) => { animate(el, { scale: 0.95 }, { duration: 0.1 }); return () => animate(el, { scale: 1 }, { type: 'spring', stiffness: 300 }); });";
            }

            if (!empty($presets['hover_grow'])) {
                $sel = wp_json_encode($presets['hover_grow']);
                $js_lines[] = "hover({$sel}, (el) => { animate(el, { scale: 1.05 }, { duration: 0.2 }); return () => animate(el, { scale: 1 }, { duration: 0.2 }); });";
            }

            $js_body = implode("\n    ", $js_lines);
            $cdn_escaped = esc_url($cdn_url);

            echo <<<SCRIPT
<script type="module" defer>
    import { {$import_list} } from "{$cdn_escaped}";
    {$js_body}
</script>
SCRIPT;
        }, 99);
    },
];

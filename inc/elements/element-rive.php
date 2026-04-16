<?php
if (! defined('ABSPATH')) exit;

class Prefix_Element_Rive extends \Bricks\Element
{
    public $category     = 'media';
    public $name         = 'prefix-rive';
    public $icon         = 'ti-video-camera';
    public $css_selector = '.prefix-rive-wrapper';
    public $scripts      = ['prefixRiveLoader'];

    public function get_label()
    {
        return esc_html__('Rive Animation', 'bricks');
    }

    public function set_control_groups()
    {
        $this->control_groups['settings'] = [
            'title' => esc_html__('Settings', 'bricks'),
            'tab'   => 'content',
        ];
    }

    public function set_controls()
    {
        $this->controls['url'] = [
            'tab'     => 'content',
            'group'   => 'settings',
            'label'   => esc_html__('Rive File URL', 'bricks'),
            'type'    => 'text',
            'default' => 'https://cdn.rive.app/animations/vehicles.riv',
        ];

        $this->controls['width'] = [
            'tab'     => 'content',
            'group'   => 'settings',
            'label'   => esc_html__('Width (px)', 'bricks'),
            'type'    => 'number',
            'default' => 500,
        ];

        $this->controls['height'] = [
            'tab'     => 'content',
            'group'   => 'settings',
            'label'   => esc_html__('Height (px)', 'bricks'),
            'type'    => 'number',
            'default' => 500,
        ];

        $this->controls['assets'] = [
            'tab'     => 'content',
            'group'   => 'settings',
            'label'   => esc_html__('Referenced Images', 'bricks'),
            'type'    => 'repeater',
            'fields'  => [
                'assetName' => [
                    'label' => esc_html__('Asset Name', 'bricks'),
                    'type'  => 'text',
                ],
                'assetUrl' => [
                    'label' => esc_html__('Image URL', 'bricks'),
                    'type'  => 'text',
                ],
            ],
        ];

        $this->controls['useWebGL'] = [
            'tab'     => 'content',
            'group'   => 'settings',
            'label'   => esc_html__('Use WebGL Runtime (for referenced images)', 'bricks'),
            'type'    => 'checkbox',
            'default' => false,
        ];

        $this->controls['dynamic_artboards'] = [
            'tab'     => 'content',
            'group'   => 'settings',
            'label'   => esc_html__('Enable Dynamic Artboards', 'bricks'),
            'type'    => 'checkbox',
            'default' => false,
        ];

        $this->controls['artboard_desktop'] = [
            'tab'   => 'content',
            'group' => 'settings',
            'label' => 'Artboard (Desktop)',
            'type'  => 'text',
        ];

        $this->controls['artboard_tablet'] = [
            'tab'   => 'content',
            'group' => 'settings',
            'label' => 'Artboard (Tablet)',
            'type'  => 'text',
        ];

        $this->controls['artboard_mobile'] = [
            'tab'   => 'content',
            'group' => 'settings',
            'label' => 'Artboard (Mobile)',
            'type'  => 'text',
        ];
    }

    public function enqueue_scripts()
    {
        $use_webgl = ! empty($this->settings['useWebGL']) && $this->settings['useWebGL'];

        $runtime_url = $use_webgl
            ? 'https://cdn.jsdelivr.net/npm/@rive-app/webgl2@2.31.5/rive.min.js'
            : 'https://cdn.jsdelivr.net/npm/@rive-app/canvas@2.31.5/rive.min.js';

        wp_enqueue_script(
            'rive-cdn',
            $runtime_url,
            [],
            null,
            true
        );

        wp_enqueue_script(
            'prefix-rive-loader',
            plugin_dir_url(__FILE__) . 'js/prefix-rive-loader.js',
            ['rive-cdn'],
            '1.0',
            true
        );
    }

    public function render()
    {
        $url    = ! empty($this->settings['url']) ? esc_url($this->settings['url']) : '';
        $width  = ! empty($this->settings['width']) ? intval($this->settings['width']) : 500;
        $height = ! empty($this->settings['height']) ? intval($this->settings['height']) : 500;
        $use_webgl = ! empty($this->settings['useWebGL']) && $this->settings['useWebGL'];

        $assets = [];
        if (! empty($this->settings['assets'])) {
            foreach ($this->settings['assets'] as $asset) {
                $assets[] = [
                    'assetName' => $asset['assetName'] ?? '',
                    'assetUrl'  => $asset['assetUrl'] ?? '',
                ];
            }
        }
        $this->set_attribute('canvas', 'data-rive-assets', esc_attr(wp_json_encode($assets)));

        $artboard_data = [
            'dynamic_artboards' => !empty($this->settings['dynamic_artboards']),
            'artboard_desktop'  => $this->settings['artboard_desktop'] ?? '',
            'artboard_tablet'   => $this->settings['artboard_tablet'] ?? '',
            'artboard_mobile'   => $this->settings['artboard_mobile'] ?? '',
        ];

        $this->set_attribute('canvas', 'data-settings', esc_attr(wp_json_encode($artboard_data)));
        $this->set_attribute('canvas', 'data-rive-webgl', $use_webgl ? 'true' : 'false');
        $this->set_attribute('_root', 'class', 'prefix-rive-wrapper');
        $this->set_attribute('canvas', 'width', $width);
        $this->set_attribute('canvas', 'height', $height);
        $this->set_attribute('canvas', 'data-rive-url', $url);

        echo "<div {$this->render_attributes('_root')}>";
        echo "<canvas {$this->render_attributes('canvas')}></canvas>";
        echo "</div>";
    }
}

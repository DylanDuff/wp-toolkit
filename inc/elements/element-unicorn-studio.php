<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Pixite_Element_Unicorn_Studio extends \Bricks\Element {
    public $category = 'general';
    public $name     = 'pixite-unicorn-studio';
    public $icon     = 'ti-star';

    public function get_label() {
        return esc_html__( 'Unicorn Studio', 'bricks' );
    }

    public function set_controls() {
        $this->controls['project_id'] = [
            'tab'     => 'content',
            'label'   => esc_html__( 'Project ID', 'bricks' ),
            'type'    => 'text',
            'default' => '',
        ];
    }

    public function render() {
        $project_id = $this->settings['project_id'] ?? '';

        if ( ! $project_id ) {
            return $this->render_element_placeholder( [
                'title' => esc_html__( 'Please enter a Unicorn Studio Project ID.', 'bricks' ),
            ] );
        }

        $uid = 'us-' . $this->id;

        $this->set_attribute( '_root', 'id', $uid );
        $this->set_attribute( '_root', 'style', 'width:100%; height:100%;' );
        $this->set_attribute( '_root', 'data-us-project', esc_attr( $project_id ) );

        echo "<div {$this->render_attributes( '_root' )}></div>";
        ?>
        <script>
        (function( uid ) {
            function init() {
                if ( window.UnicornStudio ) {
                    window.UnicornStudio.isInitialized = false;
                    window.UnicornStudio.init();
                }
            }

            if ( window.UnicornStudio ) {
                init();
                return;
            }

            if ( window.__unicornStudioLoading ) {
                window.__unicornStudioQueue = window.__unicornStudioQueue || [];
                window.__unicornStudioQueue.push( init );
                return;
            }

            window.__unicornStudioLoading = true;
            window.__unicornStudioQueue   = [ init ];

            var s    = document.createElement( 'script' );
            s.src    = 'https://cdn.jsdelivr.net/gh/hiunicornstudio/unicornstudio.js@v2.1.6/dist/unicornStudio.umd.js';
            s.onload = function() {
                window.__unicornStudioLoading = false;
                ( window.__unicornStudioQueue || [] ).forEach( function( fn ) { fn(); } );
                window.__unicornStudioQueue = [];
            };
            document.head.appendChild( s );
        })( <?php echo json_encode( $uid ); ?> );
        </script>
        <?php
    }
}

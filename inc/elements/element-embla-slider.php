<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Prefix_Element_Embla_Slider extends \Bricks\Element {
    public $category = 'media';
    public $name     = 'prefix-embla-slider';
    public $icon     = 'ti-layout-slider';
    public $nestable = true;

    /**
     * Global JS functions Bricks calls after it (re-)renders this element.
     *
     * The builder replaces element markup via Vue, so any <script> we output in
     * render() never executes there. Bricks' own mechanism is this array: it
     * calls window[<name>]() in the canvas iframe on every re-render.
     * @see Bricks Elements::register_element() + builder runElementScripts()
     */
    public $scripts = [ 'prefixEmblaInit' ];

    /**
     * Default for the 'perPage' control. The --embla-per-page fallback in
     * prefix-embla-slider.css mirrors this — keep the two in step.
     */
    const DEFAULT_PER_PAGE = 2;

    public function get_label() {
        return esc_html__( 'Embla Slider', 'bricks' ) . ' (' . esc_html__( 'Nestable', 'bricks' ) . ')';
    }

    public function get_keywords() {
        return [ 'slider', 'carousel', 'nestable', 'embla' ];
    }

    /**
     * Vendored Embla version — see inc/elements/js/vendor/README.md before bumping.
     * Embla 9 renamed several options we pass (startIndex, watchResize, watchSlides,
     * watchDrag), so a major bump needs render() updated in the same commit.
     */
    const EMBLA_VERSION = '8.6.0';

    public function enqueue_scripts() {
        wp_enqueue_script(
            'embla-carousel',
            plugin_dir_url( __FILE__ ) . 'js/vendor/embla-carousel.umd.js',
            [],
            self::EMBLA_VERSION,
            true
        );

        wp_enqueue_script(
            'embla-carousel-autoplay',
            plugin_dir_url( __FILE__ ) . 'js/vendor/embla-carousel-autoplay.umd.js',
            [ 'embla-carousel' ],
            self::EMBLA_VERSION,
            true
        );

        wp_enqueue_script(
            'prefix-embla-slider',
            plugin_dir_url( __FILE__ ) . 'js/prefix-embla-slider.js',
            [ 'embla-carousel', 'embla-carousel-autoplay' ],
            DDWPT_VERSION,
            true
        );

        wp_enqueue_style(
            'prefix-embla-slider',
            plugin_dir_url( __FILE__ ) . 'css/prefix-embla-slider.css',
            [],
            DDWPT_VERSION
        );
    }

    public function set_control_groups() {
        $this->control_groups['options'] = [
            'title' => esc_html__( 'Options', 'bricks' ),
        ];

        $this->control_groups['slide'] = [
            'title' => esc_html__( 'Slide', 'bricks' ),
        ];

        $this->control_groups['arrows'] = [
            'title' => esc_html__( 'Arrows', 'bricks' ),
        ];

        $this->control_groups['pagination'] = [
            'title' => esc_html__( 'Pagination', 'bricks' ),
        ];
    }

    public function set_controls() {
        // Redirect built-in height control to slides
        $this->controls['_height']['css'][0]['selector'] = '.embla__slide';

        // Slider is full width unless the width control overrides it (see CSS)
        $this->controls['_width']['placeholder'] = '100%';

        $this->controls['_children'] = [
            'type'          => 'repeater',
            'titleProperty' => 'label',
            'items'         => 'children',
        ];

        // OPTIONS

        $this->controls['type'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Type', 'bricks' ),
            'type'        => 'select',
            'options'     => [
                'loop'  => esc_html__( 'Loop', 'bricks' ),
                'slide' => esc_html__( 'Slide', 'bricks' ),
            ],
            'inline'      => true,
            'placeholder' => esc_html__( 'Loop', 'bricks' ),
        ];

        // '!=' rather than "= loop" so this also shows while Type is untouched,
        // which renders as loop (see render()).
        $this->controls['typeLoopInfo'] = [
            'group'    => 'options',
            'content'  => esc_html__( 'Embla automatically falls back to false if slide content isn\'t enough to create the loop effect without visible glitches.', 'bricks' ),
            'type'     => 'info',
            'required' => [ 'type', '!=', 'slide' ],
        ];

        $this->controls['direction'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Direction', 'bricks' ),
            'type'        => 'select',
            'options'     => [
                'ltr' => esc_html__( 'Left to right', 'bricks' ),
                'rtl' => esc_html__( 'Right to left', 'bricks' ),
                'ttb' => esc_html__( 'Vertical', 'bricks' ),
            ],
            'inline'      => true,
            'placeholder' => esc_html__( 'Left to right', 'bricks' ),
        ];

        $this->controls['align'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Align', 'bricks' ),
            'type'        => 'select',
            'options'     => [
                'start'  => esc_html__( 'Start', 'bricks' ),
                'center' => esc_html__( 'Center', 'bricks' ),
                'end'    => esc_html__( 'End', 'bricks' ),
            ],
            'inline'      => true,
            'placeholder' => esc_html__( 'Start', 'bricks' ),
        ];

        $this->controls['autoHeight'] = [
            'group'  => 'options',
            'label'  => esc_html__( 'Auto height', 'bricks' ),
            'type'   => 'checkbox',
            'inline' => true,
        ];

        $this->controls['autoHeightInfo'] = [
            'group'    => 'options',
            'content'  => esc_html__( 'Using "Auto height" might lead to CLS (Cumulative Layout Shift).', 'bricks' ),
            'type'     => 'info',
            'required' => [ 'autoHeight', '=', true ],
        ];

        $this->controls['height'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Height', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '50vh',
            'breakpoints' => true,
            'required'    => [ 'autoHeight', '=', '' ],
            'css'         => [
                [
                    'property' => 'height',
                    'selector' => '.embla__slide',
                ],
            ],
        ];

        $this->controls['gap'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Spacing', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'breakpoints' => true,
            // Written as a custom property (not column-gap directly) so the slide
            // size calc can subtract the gaps — see prefix-embla-slider.css.
            'css'         => [
                [
                    'property' => '--embla-gap',
                    'selector' => '',
                ],
            ],
            'default'     => '1rem',
        ];

        $this->controls['start'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Start index', 'bricks' ),
            'type'        => 'number',
            'placeholder' => 0,
        ];

        // Written as a unitless custom property so it can vary per breakpoint. A
        // 'number' control with no 'unit'/'units' key emits the bare value (Bricks
        // Assets::generate_css_from_element) — adding units here would yield "3px".
        $this->controls['perPage'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Items to show', 'bricks' ),
            'type'        => 'number',
            'min'         => 1,
            // Fractional values are valid (2.5, 3.5 for a peek effect) — Embla has no
            // per-page concept, it measures whatever width the CSS gives each slide.
            // Without this the input defaults to step="1" and rejects decimals.
            'step'        => 'any',
            'breakpoints' => true,
            'css'         => [
                [
                    'property' => '--embla-per-page',
                    'selector' => '',
                ],
            ],
            'default'     => self::DEFAULT_PER_PAGE,
            'desc'        => esc_html__( 'Decimals allowed, e.g. 3.5 to peek the next slide.', 'bricks' ),
        ];

        // Grid mode. Embla is strictly one-dimensional — it derives every scroll snap
        // from each slide's offsetLeft, so any wrapped or grid layout gives slides in
        // the same column an identical offset and breaks snapping outright. Instead
        // the JS groups children into columns of N, and Embla still sees a flat line.
        // Not breakpoint-aware: this changes DOM structure, not CSS.
        $this->controls['rows'] = [
            'group'   => 'options',
            'label'   => esc_html__( 'Rows', 'bricks' ),
            'type'    => 'number',
            'min'     => 1,
            'step'    => 1,
            'default' => 1,
            'desc'    => esc_html__( 'Stack this many items per column. 1 is a single row. "Items to show" then counts columns.', 'bricks' ),
        ];

        $this->controls['perMove'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Items to scroll', 'bricks' ),
            'type'        => 'number',
            'placeholder' => 1,
            'min'         => 1,
        ];

        $this->controls['speed'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Duration', 'bricks' ),
            'type'        => 'number',
            'placeholder' => 25,
            'min'         => 0,
            'max'         => 100,
            'desc'        => esc_html__( 'Animation duration factor (0–100). Higher = slower. Default: 25.', 'bricks' ),
        ];

        $this->controls['dragFree'] = [
            'group'  => 'options',
            'label'  => esc_html__( 'Free drag', 'bricks' ),
            'type'   => 'checkbox',
            'inline' => true,
            'desc'   => esc_html__( 'Drag freely without snapping to slides.', 'bricks' ),
        ];

        // AUTOPLAY

        $this->controls['autoplaySeparator'] = [
            'group' => 'options',
            'label' => esc_html__( 'Autoplay', 'bricks' ),
            'type'  => 'separator',
        ];

        $this->controls['autoplay'] = [
            'group' => 'options',
            'label' => esc_html__( 'Autoplay', 'bricks' ),
            'type'  => 'checkbox',
        ];

        $this->controls['pauseOnHover'] = [
            'group'    => 'options',
            'label'    => esc_html__( 'Pause on hover', 'bricks' ),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => [ 'autoplay', '!=', '' ],
        ];

        $this->controls['pauseOnFocus'] = [
            'group'    => 'options',
            'label'    => esc_html__( 'Pause on focus', 'bricks' ),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => [ 'autoplay', '!=', '' ],
        ];

        $this->controls['interval'] = [
            'group'       => 'options',
            'label'       => esc_html__( 'Interval in ms', 'bricks' ),
            'type'        => 'number',
            'placeholder' => 3000,
            'required'    => [ 'autoplay', '!=', '' ],
        ];

        // SLIDE

        $this->controls['slidePadding'] = [
            'group'   => 'slide',
            'label'   => esc_html__( 'Padding', 'bricks' ),
            'type'    => 'spacing',
            'css'     => [
                [
                    'property' => 'padding',
                    'selector' => '.embla__slide',
                ],
            ],
            'default' => [
                'top'    => '1rem',
                'right'  => '1rem',
                'bottom' => '1rem',
                'left'   => '1rem',
            ],
        ];

        // Slides are Bricks blocks (flex column), so align-items is the horizontal
        // axis and justify-content the vertical one — hence the label/property pairing.
        $this->controls['slideAlignHorizontal'] = [
            'group'   => 'slide',
            'label'   => esc_html__( 'Align horizontal', 'bricks' ),
            'type'    => 'align-items',
            'exclude' => 'stretch',
            'inline'  => true,
            'css'     => [
                [
                    'property' => 'align-items',
                    'selector' => '.embla__slide',
                ],
            ],
            'default' => 'center',
        ];

        $this->controls['slideAlignVertical'] = [
            'group'   => 'slide',
            'label'   => esc_html__( 'Align vertical', 'bricks' ),
            'type'    => 'justify-content',
            'exclude' => 'space',
            'inline'  => true,
            'css'     => [
                [
                    'property' => 'justify-content',
                    'selector' => '.embla__slide',
                ],
            ],
            'default' => 'center',
        ];

        $this->controls['slideBackground'] = [
            'group'   => 'slide',
            'label'   => esc_html__( 'Background', 'bricks' ),
            'type'    => 'background',
            'css'     => [
                [
                    'property' => 'background',
                    'selector' => '.embla__slide',
                ],
            ],
            'exclude' => 'video',
        ];

        $this->controls['slideBorder'] = [
            'group'   => 'slide',
            'label'   => esc_html__( 'Border', 'bricks' ),
            'type'    => 'border',
            'css'     => [
                [
                    'property' => 'border',
                    'selector' => '.embla__slide',
                ],
            ],
            'default' => [
                'width' => [
                    'top'    => 1,
                    'right'  => 1,
                    'bottom' => 1,
                    'left'   => 1,
                ],
                'style' => 'solid',
                'color' => [
                    'hex' => '#cccccc',
                ],
            ],
        ];

        // ARROWS

        // 'builtin' renders the arrows inside the element and styles them with the
        // controls below. 'custom' renders none and binds the user's own elements
        // anywhere on the page instead — the two are mutually exclusive.
        $this->controls['arrows'] = [
            'group'       => 'arrows',
            'label'       => esc_html__( 'Mode', 'bricks' ),
            'type'        => 'select',
            'options'     => [
                'none'    => esc_html__( 'None', 'bricks' ),
                'builtin' => esc_html__( 'Built-in', 'bricks' ),
                'custom'  => esc_html__( 'Custom', 'bricks' ),
            ],
            'inline'      => true,
            'placeholder' => esc_html__( 'None', 'bricks' ),
            'rerender'    => true,
        ];

        $this->controls['arrowHeight'] = [
            'group'       => 'arrows',
            'label'       => esc_html__( 'Height', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'height',
                    'selector' => '.embla__button',
                ],
            ],
            'placeholder' => 50,
            'required'    => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowWidth'] = [
            'group'       => 'arrows',
            'label'       => esc_html__( 'Width', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'width',
                    'selector' => '.embla__button',
                ],
            ],
            'placeholder' => 50,
            'required'    => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowBackground'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Background', 'bricks' ),
            'type'     => 'color',
            'css'      => [
                [
                    'property' => 'background-color',
                    'selector' => '.embla__button',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowBorder'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Border', 'bricks' ),
            'type'     => 'border',
            'css'      => [
                [
                    'property' => 'border',
                    'selector' => '.embla__button',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowColor'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Color', 'bricks' ),
            'type'     => 'color',
            'css'      => [
                [
                    'property' => 'color',
                    'selector' => '.embla__button',
                ],
                [
                    'property' => 'fill',
                    'selector' => '.embla__button svg',
                ],
                [
                    'property' => 'stroke',
                    'selector' => '.embla__button svg',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowSize'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Size', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'font-size',
                    'selector' => '.embla__button',
                ],
                [
                    'property' => 'height',
                    'selector' => '.embla__button svg',
                ],
                [
                    'property' => 'width',
                    'selector' => '.embla__button svg',
                ],
                [
                    'property' => 'min-height',
                    'selector' => '.embla__button',
                ],
                [
                    'property' => 'min-width',
                    'selector' => '.embla__button',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowTextShadow'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Text shadow', 'bricks' ),
            'type'     => 'text-shadow',
            'css'      => [
                [
                    'property' => 'text-shadow',
                    'selector' => '.embla__button',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        // Disabled state

        $this->controls['disabledArrowSep'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Disabled', 'bricks' ),
            'type'     => 'separator',
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowDisabledBackground'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Background', 'bricks' ),
            'type'     => 'color',
            'css'      => [
                [
                    'property' => 'background-color',
                    'selector' => '.embla__button:disabled',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowDisabledBorder'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Border', 'bricks' ),
            'type'     => 'border',
            'css'      => [
                [
                    'property' => 'border',
                    'selector' => '.embla__button:disabled',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowDisabledColor'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Color', 'bricks' ),
            'type'     => 'color',
            'css'      => [
                [
                    'property' => 'color',
                    'selector' => '.embla__button:disabled',
                ],
                [
                    'property' => 'fill',
                    'selector' => '.embla__button:disabled svg',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['arrowDisabledOpacity'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Opacity', 'bricks' ),
            'type'     => 'number',
            'inline'   => true,
            'min'      => 0,
            'max'      => 1,
            'step'     => 0.1,
            'css'      => [
                [
                    'property' => 'opacity',
                    'selector' => '.embla__button:disabled',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        // Prev arrow

        $this->controls['prevArrowSeparator'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Prev arrow', 'bricks' ),
            'type'     => 'separator',
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['prevArrow'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Prev arrow', 'bricks' ),
            'type'     => 'icon',
            'rerender' => true,
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['prevArrowTop'] = [
            'group'       => 'arrows',
            'label'       => esc_html__( 'Top', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'top',
                    'selector' => '.embla__button--prev',
                ],
            ],
            'placeholder' => '50%',
            'required'    => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['prevArrowRight'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Right', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'right',
                    'selector' => '.embla__button--prev',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['prevArrowBottom'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Bottom', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'bottom',
                    'selector' => '.embla__button--prev',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['prevArrowLeft'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Left', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'left',
                    'selector' => '.embla__button--prev',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['prevArrowTransform'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Transform', 'bricks' ),
            'type'     => 'transform',
            'css'      => [
                [
                    'property' => 'transform',
                    'selector' => '.embla__button--prev',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        // Next arrow

        $this->controls['nextArrowSeparator'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Next arrow', 'bricks' ),
            'type'     => 'separator',
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['nextArrow'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Next arrow', 'bricks' ),
            'type'     => 'icon',
            'rerender' => true,
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['nextArrowTop'] = [
            'group'       => 'arrows',
            'label'       => esc_html__( 'Top', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'top',
                    'selector' => '.embla__button--next',
                ],
            ],
            'placeholder' => '50%',
            'required'    => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['nextArrowRight'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Right', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'right',
                    'selector' => '.embla__button--next',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['nextArrowBottom'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Bottom', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'bottom',
                    'selector' => '.embla__button--next',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['nextArrowLeft'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Left', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'left',
                    'selector' => '.embla__button--next',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        $this->controls['nextArrowTransform'] = [
            'group'    => 'arrows',
            'label'    => esc_html__( 'Transform', 'bricks' ),
            'type'     => 'transform',
            'css'      => [
                [
                    'property' => 'transform',
                    'selector' => '.embla__button--next',
                ],
            ],
            'required' => [ 'arrows', '=', 'builtin' ],
        ];

        // Custom arrows
        //
        // These deliberately carry no 'css' key. A control with one only patches the
        // builder stylesheet and skips the AJAX re-render, so prefixEmblaInit() would
        // never re-run and the new bindings would never attach.

        $this->controls['customArrowsInfo'] = [
            'group'    => 'arrows',
            'content'  => esc_html__( 'Bind your own elements anywhere on the page as slider controls. Every element matching the selector is bound, and gets the class "is-disabled" when it can\'t scroll any further.', 'bricks' ),
            'type'     => 'info',
            'required' => [ 'arrows', '=', 'custom' ],
        ];

        $this->controls['prevArrowSelector'] = [
            'group'       => 'arrows',
            'label'       => esc_html__( 'Prev selector', 'bricks' ),
            'type'        => 'text',
            'placeholder' => '.my-prev-button',
            'rerender'    => true,
            'desc'        => esc_html__( 'CSS selector of your own previous control, e.g. .my-prev-button', 'bricks' ),
            'required'    => [ 'arrows', '=', 'custom' ],
        ];

        $this->controls['nextArrowSelector'] = [
            'group'       => 'arrows',
            'label'       => esc_html__( 'Next selector', 'bricks' ),
            'type'        => 'text',
            'placeholder' => '.my-next-button',
            'rerender'    => true,
            'desc'        => esc_html__( 'CSS selector of your own next control, e.g. .my-next-button', 'bricks' ),
            'required'    => [ 'arrows', '=', 'custom' ],
        ];

        // PAGINATION (dots)

        $this->controls['pagination'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Show', 'bricks' ),
            'type'     => 'checkbox',
            'inline'   => true,
            'rerender' => true,
            'default'  => true,
        ];

        $this->controls['paginationSpacing'] = [
            'group'       => 'pagination',
            'label'       => esc_html__( 'Margin', 'bricks' ),
            'type'        => 'spacing',
            'css'         => [
                [
                    'property' => 'margin',
                    'selector' => '.embla__dot',
                ],
            ],
            'placeholder' => [
                'top'    => '5px',
                'right'  => '5px',
                'bottom' => '5px',
                'left'   => '5px',
            ],
            'required'    => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationHeight'] = [
            'group'       => 'pagination',
            'label'       => esc_html__( 'Height', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'height',
                    'selector' => '.embla__dot',
                ],
            ],
            'placeholder' => '10px',
            'required'    => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationWidth'] = [
            'group'       => 'pagination',
            'label'       => esc_html__( 'Width', 'bricks' ),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'width',
                    'selector' => '.embla__dot',
                ],
            ],
            'placeholder' => '10px',
            'required'    => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationColor'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Color', 'bricks' ),
            'type'     => 'color',
            'css'      => [
                [
                    'property' => 'background-color',
                    'selector' => '.embla__dot',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationBorder'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Border', 'bricks' ),
            'type'     => 'border',
            'css'      => [
                [
                    'property' => 'border',
                    'selector' => '.embla__dot',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        // Active

        $this->controls['paginationActiveSeparator'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Active', 'bricks' ),
            'type'     => 'separator',
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationHeightActive'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Height', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'height',
                    'selector' => '.embla__dot.is-active',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationWidthActive'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Width', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'width',
                    'selector' => '.embla__dot.is-active',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationColorActive'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Color', 'bricks' ),
            'type'     => 'color',
            'css'      => [
                [
                    'property' => 'background-color',
                    'selector' => '.embla__dot.is-active',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationBorderActive'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Border', 'bricks' ),
            'type'     => 'border',
            'css'      => [
                [
                    'property' => 'border',
                    'selector' => '.embla__dot.is-active',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        // Position

        $this->controls['paginationPositionSeparator'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Position', 'bricks' ),
            'type'     => 'separator',
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationTop'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Top', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'top',
                    'selector' => '.embla__dots',
                ],
                [
                    'property' => 'bottom',
                    'value'    => 'auto',
                    'selector' => '.embla__dots',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationRight'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Right', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'right',
                    'selector' => '.embla__dots',
                ],
                [
                    'property' => 'left',
                    'value'    => 'auto',
                    'selector' => '.embla__dots',
                ],
                [
                    'property' => 'transform',
                    'value'    => 'translateX(0)',
                    'selector' => '.embla__dots',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationBottom'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Bottom', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'bottom',
                    'selector' => '.embla__dots',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];

        $this->controls['paginationLeft'] = [
            'group'    => 'pagination',
            'label'    => esc_html__( 'Left', 'bricks' ),
            'type'     => 'number',
            'units'    => true,
            'css'      => [
                [
                    'property' => 'left',
                    'selector' => '.embla__dots',
                ],
                [
                    'property' => 'right',
                    'value'    => 'auto',
                    'selector' => '.embla__dots',
                ],
                [
                    'property' => 'transform',
                    'value'    => 'translateX(0)',
                    'selector' => '.embla__dots',
                ],
            ],
            'required' => [ 'pagination', '!=', '' ],
        ];
    }

    public function get_nestable_item() {
        return [
            'name'     => 'block',
            'label'    => esc_html__( 'Slide', 'bricks' ) . ' {item_index}',
            'children' => [
                [
                    'name'     => 'heading',
                    'settings' => [
                        'text' => esc_html__( 'Slide', 'bricks' ) . ' {item_index}',
                    ],
                ],
                [
                    'name'     => 'button',
                    'settings' => [
                        'text'  => esc_html__( 'I am a button', 'bricks' ),
                        'size'  => 'lg',
                        'style' => 'primary',
                    ],
                ],
            ],
        ];
    }

    public function get_nestable_children() {
        $children = [];

        for ( $i = 0; $i < 3; $i++ ) {
            $item       = $this->get_nestable_item();
            $item       = wp_json_encode( $item );
            $item       = str_replace( '{item_index}', $i + 1, $item );
            $item       = json_decode( $item, true );
            $children[] = $item;
        }

        return $children;
    }

    private function render_arrows() {
        $prev_icon = ! empty( $this->settings['prevArrow'] ) ? self::render_icon( $this->settings['prevArrow'] ) : false;
        $next_icon = ! empty( $this->settings['nextArrow'] ) ? self::render_icon( $this->settings['nextArrow'] ) : false;

        $default_prev = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>';
        $default_next = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>';

        $output  = '<button class="embla__button embla__button--prev" type="button" aria-label="' . esc_attr__( 'Previous slide', 'bricks' ) . '">';
        $output .= $prev_icon ?: $default_prev;
        $output .= '</button>';

        $output .= '<button class="embla__button embla__button--next" type="button" aria-label="' . esc_attr__( 'Next slide', 'bricks' ) . '">';
        $output .= $next_icon ?: $default_next;
        $output .= '</button>';

        return $output;
    }

    public function render() {
        $settings    = $this->settings;
        $type        = $settings['type'] ?? 'loop';
        $direction   = $settings['direction'] ?? 'ltr';
        $arrows_mode = $settings['arrows'] ?? 'none';

        // No inline --embla-per-page: it comes from the perPage control's generated
        // CSS so it can differ per breakpoint. An inline style would outrank the
        // media queries and pin every breakpoint to one value.
        $this->set_attribute( '_root', 'class', 'embla' );

        if ( $direction === 'ttb' ) {
            $this->set_attribute( '_root', 'data-embla-axis', 'y' );
        }

        // Marks which physical edge the loop seam margin belongs on — Embla reads
        // margin-left rather than margin-right when direction is rtl.
        if ( $direction === 'rtl' ) {
            $this->set_attribute( '_root', 'data-embla-dir', 'rtl' );
        }

        // Core Embla options
        $embla_options = [
            'loop'           => $type === 'loop',
            'axis'           => $direction === 'ttb' ? 'y' : 'x',
            'direction'      => $direction === 'rtl' ? 'rtl' : 'ltr',
            'startIndex'     => ! empty( $settings['start'] ) ? intval( $settings['start'] ) : 0,
            'slidesToScroll' => ! empty( $settings['perMove'] ) ? intval( $settings['perMove'] ) : 1,
            'duration'       => isset( $settings['speed'] ) ? intval( $settings['speed'] ) : 25,
            'align'          => $settings['align'] ?? 'start',
            'dragFree'       => isset( $settings['dragFree'] ),
        ];

        $config = [
            'options' => $embla_options,
        ];

        $rows = ! empty( $settings['rows'] ) ? max( 1, intval( $settings['rows'] ) ) : 1;

        if ( $rows > 1 ) {
            $config['rows'] = $rows;
        }

        if ( isset( $settings['autoplay'] ) ) {
            $config['autoplay'] = [
                'delay'             => ! empty( $settings['interval'] ) ? intval( $settings['interval'] ) : 3000,
                'stopOnHover'       => isset( $settings['pauseOnHover'] ),
                'stopOnFocus'       => isset( $settings['pauseOnFocus'] ),
                'stopOnInteraction' => false,
                'playOnInit'        => (bool) $this->is_frontend,
            ];
        }

        if ( isset( $settings['autoHeight'] ) ) {
            $config['autoHeight'] = true;
        }

        // Selectors are resolved document-wide by the JS — the point is that these
        // controls can live anywhere on the page, not inside the slider. Only passed
        // in custom mode so a leftover selector can't keep binding after a switch.
        if ( $arrows_mode === 'custom' ) {
            if ( ! empty( $settings['prevArrowSelector'] ) ) {
                $config['prevArrowSelector'] = trim( $settings['prevArrowSelector'] );
            }

            if ( ! empty( $settings['nextArrowSelector'] ) ) {
                $config['nextArrowSelector'] = trim( $settings['nextArrowSelector'] );
            }
        }

        $this->set_attribute( '_root', 'data-embla', esc_attr( wp_json_encode( $config ) ) );

        $output  = "<div {$this->render_attributes( '_root' )}>";
        $output .= '<div class="embla__viewport">';
        $output .= '<div class="embla__container">';
        $output .= \Bricks\Frontend::render_children( $this );
        $output .= '</div>';
        $output .= '</div>';

        if ( $arrows_mode === 'builtin' ) {
            $output .= $this->render_arrows();
        }

        if ( isset( $settings['pagination'] ) ) {
            $output .= '<div class="embla__dots" role="tablist" aria-label="' . esc_attr__( 'Slider pagination', 'bricks' ) . '"></div>';
        }

        $output .= '</div>';

        // No inline loader: enqueue_scripts() runs on the frontend (Element::init) and in the
        // builder iframe (Elements::register_element), and $scripts handles re-initialising.

        echo $output;
    }
}

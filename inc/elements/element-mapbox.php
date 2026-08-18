<?php
/**
 * Bricks Element: Mapbox Map
 * Loaded by the mapbox-bricks tweak via \Bricks\Elements::register_element().
 */

if (!defined("ABSPATH")) {
    exit();
}

class Element_Mapbox extends \Bricks\Element
{
    const NAME = "mapbox-map";
    const MAPBOX_GL_VERSION = "3.0.1";
    const DEFAULT_STYLE = "mapbox://styles/mapbox/streets-v12";

    public $category = "general";
    public $name = self::NAME;
    public $icon = "ti-map-alt";

    /**
     * Global JS function Bricks calls after every (re-)render in the builder canvas.
     *
     * The builder replaces element markup via Vue, so a <script> emitted from
     * render() runs on the frontend and is inert in the canvas — which is why the
     * map only ever appeared on the frontend. $scripts is the supported mechanism:
     * Bricks calls window[<name>]() in the iframe on every re-render.
     * @see docs/bricks-elements.md — "Builder lifecycle"
     */
    public $scripts = ["prefixMapboxInit"];

    public function get_label()
    {
        return esc_html__("Mapbox Map", "bricks");
    }

    public function get_keywords()
    {
        return ["map", "mapbox", "location", "marker", "service area"];
    }

    /**
     * Runs on the frontend via Element::init(), and in the builder iframe via
     * Elements::register_element() — so the canvas gets the runtime too.
     */
    public function enqueue_scripts()
    {
        wp_enqueue_style(
            "mapbox-gl",
            "https://api.mapbox.com/mapbox-gl-js/v" .
                self::MAPBOX_GL_VERSION .
                "/mapbox-gl.css",
            [],
            null,
        );

        wp_enqueue_script(
            "mapbox-gl",
            "https://api.mapbox.com/mapbox-gl-js/v" .
                self::MAPBOX_GL_VERSION .
                "/mapbox-gl.js",
            [],
            null,
            true,
        );

        wp_enqueue_script(
            "prefix-mapbox",
            plugin_dir_url(__FILE__) . "js/prefix-mapbox.js",
            ["mapbox-gl"],
            defined("DDWPT_VERSION") ? DDWPT_VERSION : null,
            true,
        );
    }

    /**
     * Render the marker icon control to HTML.
     *
     * Delegates to Bricks' own renderer rather than reimplementing it. A custom SVG
     * has no 'icon' key at all — it carries only svg.id — so hand-rolled handling
     * that keys off $icon['icon'] silently drops every uploaded icon. render_icon()
     * also covers dynamic data icons and inlines the SVG file instead of <img>-ing
     * its URL, so it can be styled by currentColor.
     */
    private function render_marker_icon($icon)
    {
        if (empty($icon) || !is_array($icon)) {
            return "";
        }

        return (string) self::render_icon($icon, [
            "class" => ["mapbox-map__marker-icon"],
            "aria-hidden" => "true",
        ]);
    }

    /**
     * Resolve the Map Style control to a value for the GL JS `style` option.
     *
     * Mapbox Studio's Share panel hands out both a `mapbox://styles/…` URI and an
     * `https://api.mapbox.com/styles/v1/…` URL; GL JS accepts either, so the only
     * job here is to reject anything that is neither before it reaches a fetch.
     */
    private function resolve_map_style()
    {
        $style = $this->settings["map_style"] ?? self::DEFAULT_STYLE;

        if ($style !== "custom") {
            return $style;
        }

        $custom = trim($this->settings["map_style_url"] ?? "");

        if (strpos($custom, "mapbox://") === 0) {
            return $custom;
        }

        $url = esc_url_raw($custom, ["http", "https"]);

        return $url !== "" ? $url : self::DEFAULT_STYLE;
    }

    /**
     * Resolve a Bricks color control value to a CSS-usable string.
     * Handles hex string, array with 'raw'/'hex'/'rgb' keys.
     */
    private function resolve_color($key, $default = "#3b82f6")
    {
        $val = $this->settings[$key] ?? null;
        if (!$val) {
            return $default;
        }
        if (is_string($val)) {
            return $val;
        }
        if (is_array($val)) {
            if (isset($val["raw"])) {
                return $val["raw"];
            }
            if (isset($val["hex"])) {
                return "#" . ltrim($val["hex"], "#");
            }
            if (isset($val["rgb"])) {
                $c = $val["rgb"];
                return "rgba(" .
                    (int) $c["r"] .
                    "," .
                    (int) $c["g"] .
                    "," .
                    (int) $c["b"] .
                    "," .
                    (float) ($c["a"] ?? 1) .
                    ")";
            }
        }
        return $default;
    }

    /**
     * Validate and decode a GeoJSON string from the textarea.
     * Returns the decoded array on success, null on failure.
     * Accepts FeatureCollection, Feature, Polygon, or MultiPolygon.
     */
    private function parse_geojson($raw)
    {
        $raw = trim($raw);
        if (empty($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data["type"])) {
            return null;
        }
        return $data;
    }

    public function set_control_groups()
    {
        $this->control_groups["general"] = [
            "title" => esc_html__("General", "bricks"),
            "tab" => "content",
        ];

        $this->control_groups["map"] = [
            "title" => esc_html__("Map", "bricks"),
            "tab" => "content",
        ];

        $this->control_groups["marker"] = [
            "title" => esc_html__("Marker", "bricks"),
            "tab" => "content",
            "required" => ["mode", "!=", "area"],
        ];

        $this->control_groups["service_area"] = [
            "title" => esc_html__("Service Area", "bricks"),
            "tab" => "content",
            "required" => ["mode", "!=", "marker"],
        ];
    }

    public function set_controls()
    {
        // ── API & Style ───────────────────────────────────────────────────────

        $this->controls["api_key"] = [
            "group" => "general",
            "label" => esc_html__("API Key", "bricks"),
            "type" => "text",
            "placeholder" => "pk.eyJ1...",
        ];

        $this->controls["map_style"] = [
            "group" => "general",
            "label" => esc_html__("Map Style", "bricks"),
            "type" => "select",
            "options" => [
                "mapbox://styles/mapbox/streets-v12" => esc_html__(
                    "Streets",
                    "bricks",
                ),
                "mapbox://styles/mapbox/outdoors-v12" => esc_html__(
                    "Outdoors",
                    "bricks",
                ),
                "mapbox://styles/mapbox/light-v11" => esc_html__(
                    "Light",
                    "bricks",
                ),
                "mapbox://styles/mapbox/dark-v11" => esc_html__(
                    "Dark",
                    "bricks",
                ),
                "mapbox://styles/mapbox/satellite-v9" => esc_html__(
                    "Satellite",
                    "bricks",
                ),
                "mapbox://styles/mapbox/satellite-streets-v12" => esc_html__(
                    "Satellite Streets",
                    "bricks",
                ),
                "custom" => esc_html__("Custom style URL", "bricks"),
            ],
            "default" => self::DEFAULT_STYLE,
        ];

        $this->controls["map_style_url"] = [
            "group" => "general",
            "label" => esc_html__("Custom Style URL", "bricks"),
            "type" => "text",
            "placeholder" => "mapbox://styles/username/clx1234567890",
            "required" => ["map_style", "=", "custom"],
            "description" => esc_html__(
                "Publish the style in Mapbox Studio, then paste its Style URL. Both mapbox://styles/… and https://api.mapbox.com/styles/v1/… are accepted. The style must be public, or owned by the account the access token belongs to.",
                "bricks",
            ),
        ];

        // ── Map ──────────────────────────────────────────────────────────────

        $this->controls["lat"] = [
            "group" => "map",
            "label" => esc_html__("Latitude", "bricks"),
            "type" => "text",
            "default" => "-33.767719570242015",
        ];

        $this->controls["lng"] = [
            "group" => "map",
            "label" => esc_html__("Longitude", "bricks"),
            "type" => "text",
            "default" => "150.6844585269858",
        ];

        $this->controls["zoom"] = [
            "group" => "map",
            "label" => esc_html__("Zoom Level", "bricks"),
            "type" => "number",
            "min" => 0,
            "max" => 22,
            "step" => 1,
            "default" => 11,
        ];

        $this->controls["map_height"] = [
            "group" => "map",
            "label" => esc_html__("Map Height", "bricks"),
            "type" => "number",
            "units" => true,
            "default" => "400px",
        ];

        $this->controls["scroll_zoom"] = [
            "group" => "map",
            "label" => esc_html__("Enable Scroll Zoom", "bricks"),
            "type" => "checkbox",
            "default" => false,
        ];

        // ── Display Mode ─────────────────────────────────────────────────────

        $this->controls["mode"] = [
            "group" => "general",
            "label" => esc_html__("Mode", "bricks"),
            "type" => "select",
            "options" => [
                "marker" => esc_html__("Marker only", "bricks"),
                "area" => esc_html__("Service area only", "bricks"),
                "both" => esc_html__("Marker + Service area", "bricks"),
            ],
            "default" => "marker",
        ];

        // ── Marker ───────────────────────────────────────────────────────────

        $this->controls["marker_icon"] = [
            "group" => "marker",
            "label" => esc_html__("Marker Icon", "bricks"),
            "type" => "icon",
            "default" => ["library" => "themify", "icon" => "ti-location-pin"],
        ];

        $this->controls["icon_width"] = [
            "group" => "marker",
            "label" => esc_html__("Icon Width (px)", "bricks"),
            "type" => "number",
            "min" => 1,
            "default" => 35,
        ];

        $this->controls["icon_height"] = [
            "group" => "marker",
            "label" => esc_html__("Icon Height (px)", "bricks"),
            "type" => "number",
            "min" => 1,
            "default" => 35,
        ];

        $this->controls["description"] = [
            "group" => "marker",
            "label" => esc_html__("Popup Description (HTML)", "bricks"),
            "type" => "textarea",
            "default" => "<strong>Business</strong><br><p>Address</p>",
        ];

        // ── Service Area ─────────────────────────────────────────────────────

        $this->controls["area_coords"] = [
            "group" => "service_area",
            "label" => esc_html__("GeoJSON", "bricks"),
            "type" => "textarea",
            "placeholder" => '{"type":"FeatureCollection","features":[...]}',
            "description" => esc_html__(
                "Paste GeoJSON directly from geojson.io. Supports FeatureCollection, Feature, Polygon, and MultiPolygon.",
                "bricks",
            ),
        ];

        $this->controls["area_coords_info"] = [
            "group" => "service_area",
            "type" => "info",
            "content" =>
                '💡 <strong>How to get your GeoJSON:</strong> Go to <a href="https://geojson.io/next" target="_blank" rel="noopener">geojson.io</a>, draw your service area using the polygon tool, then copy the full JSON from the panel on the right and paste it above.',
        ];

        $this->controls["fill_color"] = [
            "group" => "service_area",
            "label" => esc_html__("Fill Colour", "bricks"),
            "type" => "color",
            "default" => ["hex" => "#3b82f6"],
        ];

        $this->controls["fill_opacity"] = [
            "group" => "service_area",
            "label" => esc_html__("Fill Opacity", "bricks"),
            "type" => "number",
            "min" => 0,
            "max" => 1,
            "step" => 0.05,
            "default" => 0.3,
        ];

        $this->controls["outline_color"] = [
            "group" => "service_area",
            "label" => esc_html__("Outline Colour", "bricks"),
            "type" => "color",
            "default" => ["hex" => "#1d4ed8"],
        ];

        $this->controls["outline_width"] = [
            "group" => "service_area",
            "label" => esc_html__("Outline Width (px)", "bricks"),
            "type" => "number",
            "min" => 0,
            "default" => 2,
        ];
    }

    public function render()
    {
        $s = $this->settings;

        $api_key = trim($s["api_key"] ?? "");

        if ($api_key === "") {
            // Builder-only notice; returns nothing on the frontend.
            return $this->render_element_placeholder([
                "title" => esc_html__(
                    "Please enter a Mapbox access token.",
                    "bricks",
                ),
            ]);
        }

        $mode = $s["mode"] ?? "marker";
        $show_marker = in_array($mode, ["marker", "both"], true);
        $show_area = in_array($mode, ["area", "both"], true);

        // Cast: GL JS takes numbers, and a text control hands us strings.
        $lat = isset($s["lat"]) && $s["lat"] !== ""
            ? (float) $s["lat"]
            : -33.767719570242015;
        $lng = isset($s["lng"]) && $s["lng"] !== ""
            ? (float) $s["lng"]
            : 150.6844585269858;

        $map_height = $s["map_height"] ?? "400px";

        $config = [
            "apiKey" => $api_key,
            "style" => $this->resolve_map_style(),
            "center" => [$lng, $lat],
            "zoom" => isset($s["zoom"]) ? (int) $s["zoom"] : 11,
            "scrollZoom" => !empty($s["scroll_zoom"]),
        ];

        if ($show_marker) {
            $config["marker"] = [
                "width" => isset($s["icon_width"]) ? (int) $s["icon_width"] : 35,
                "height" => isset($s["icon_height"])
                    ? (int) $s["icon_height"]
                    : 35,
                "popup" => wp_kses_post($s["description"] ?? ""),
            ];
        }

        $geojson = $this->parse_geojson($s["area_coords"] ?? "");

        if ($show_area && $geojson !== null) {
            $config["area"] = [
                "geojson" => $geojson,
                "fillColor" => $this->resolve_color("fill_color", "#3b82f6"),
                "fillOpacity" => isset($s["fill_opacity"])
                    ? (float) $s["fill_opacity"]
                    : 0.3,
                "outlineColor" => $this->resolve_color(
                    "outline_color",
                    "#1d4ed8",
                ),
                "outlineWidth" => isset($s["outline_width"])
                    ? (int) $s["outline_width"]
                    : 2,
            ];
        }

        // Keyed by element id so the JS can drop a stale map (and its WebGL context)
        // when the builder re-renders this element.
        $this->set_attribute("_root", "data-mapbox-id", $this->id);
        $this->set_attribute(
            "_root",
            "data-mapbox",
            esc_attr(wp_json_encode($config)),
        );

        $icon_html = $show_marker
            ? $this->render_marker_icon($s["marker_icon"] ?? [])
            : "";

        // The height lives on the inner canvas, not the root: an inline style on the
        // root would outrank the Layout → Height style control.
        $output = "<div {$this->render_attributes('_root')}>";
        $output .=
            '<div class="mapbox-map__canvas" style="width:100%;height:' .
            esc_attr($map_height) .
            ';"></div>';

        // Rendered server-side rather than passed through the JSON config so an
        // inlined SVG never has to survive a round-trip through an attribute.
        // The JS unhides it and hands the node to mapboxgl.Marker.
        if ($icon_html !== "") {
            $output .=
                '<div class="mapbox-map__marker" hidden>' . $icon_html . "</div>";
        }

        $output .= "</div>";

        // No inline loader: enqueue_scripts() covers both contexts and $scripts
        // re-initialises the map after a builder re-render.
        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}

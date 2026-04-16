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
    public $category = "general";
    public $name = "mapbox-map";
    public $icon = "ti-map-alt";

    public function get_label()
    {
        return esc_html__("Mapbox Map", "bricks");
    }

    public function get_keywords()
    {
        return ["map", "mapbox", "location", "marker", "service area"];
    }

    public function enqueue_scripts()
    {
        wp_enqueue_style(
            "mapbox-gl-css",
            "https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css",
            [],
            null,
        );

        wp_enqueue_script(
            "mapbox-gl-js",
            "https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js",
            [],
            null,
            true,
        );
    }

    /**
     * Render a Bricks icon control value to an HTML string.
     * Handles font icons (FontAwesome, Themify, Ionicons) and SVG uploads.
     */
    private function render_marker_icon($icon)
    {
        if (empty($icon) || empty($icon["icon"])) {
            return "";
        }
        if (isset($icon["library"]) && $icon["library"] === "svg") {
            $url = $icon["svg"]["url"] ?? ($icon["url"] ?? "");
            if ($url) {
                return '<img src="' .
                    esc_url($url) .
                    '" style="width:100%;height:100%;object-fit:contain;" alt="">';
            }
            return "";
        }
        return '<i class="' .
            esc_attr($icon["icon"]) .
            '" aria-hidden="true"></i>';
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
            ],
            "default" => "mapbox://styles/mapbox/streets-v12",
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

        // General
        $api_key = $s["api_key"] ?? "";
        $map_style = $s["map_style"] ?? "mapbox://styles/mapbox/streets-v12";
        $lat = $s["lat"] ?? "-33.767719570242015";
        $lng = $s["lng"] ?? "150.6844585269858";
        $zoom = isset($s["zoom"]) ? (int) $s["zoom"] : 11;
        $map_height = $s["map_height"] ?? "400px";
        $scroll_zoom = !empty($s["scroll_zoom"]);
        $mode = $s["mode"] ?? "marker";

        // Marker
        $icon_html = $this->render_marker_icon($s["marker_icon"] ?? []);
        $icon_width = isset($s["icon_width"]) ? (int) $s["icon_width"] : 35;
        $icon_height = isset($s["icon_height"]) ? (int) $s["icon_height"] : 35;
        $description = $s["description"] ?? "";

        // Service area
        $fill_color = $this->resolve_color("fill_color", "#3b82f6");
        $fill_opacity = isset($s["fill_opacity"])
            ? (float) $s["fill_opacity"]
            : 0.3;
        $outline_color = $this->resolve_color("outline_color", "#1d4ed8");
        $outline_width = isset($s["outline_width"])
            ? (int) $s["outline_width"]
            : 2;
        $geojson_data = $this->parse_geojson($s["area_coords"] ?? "");

        $show_marker = in_array($mode, ["marker", "both"], true);
        $show_area = in_array($mode, ["area", "both"], true);

        // Unique IDs — supports multiple maps per page.
        $map_id = "mapbox-map-" . $this->id;
        $src_id = "service-area-" . $this->id;

        // Encode everything for safe JS embedding.
        $js_api_key = json_encode($api_key);
        $js_map_style = json_encode($map_style);
        $js_lat = json_encode($lat);
        $js_lng = json_encode($lng);
        $js_zoom = json_encode($zoom);
        $js_map_id = json_encode($map_id);
        $js_icon_html = json_encode($icon_html);
        $js_icon_width = json_encode($icon_width);
        $js_icon_height = json_encode($icon_height);
        $js_description = json_encode(wp_kses_post($description));
        $js_scroll_zoom = $scroll_zoom ? "true" : "false";
        $js_show_marker = $show_marker ? "true" : "false";
        $js_show_area = $show_area && $geojson_data !== null ? "true" : "false";
        $js_src_id = json_encode($src_id);
        $js_geojson = json_encode($geojson_data);
        $js_fill_color = json_encode($fill_color);
        $js_fill_opacity = json_encode($fill_opacity);
        $js_outline_color = json_encode($outline_color);
        $js_outline_width = json_encode($outline_width);

        // Render the map container.
        printf(
            '<div id="%s" style="width:100%%;height:%s;"></div>',
            esc_attr($map_id),
            esc_attr($map_height),
        );

        $init_script = <<<JS
        (function() {
          var showMarker = {$js_show_marker};
          var showArea   = {$js_show_area};
          var geojson    = {$js_geojson};

          function initMap() {
            mapboxgl.accessToken = {$js_api_key};

            var map = new mapboxgl.Map({
              container: {$js_map_id},
              style:     {$js_map_style},
              center:    [{$js_lng}, {$js_lat}],
              zoom:      {$js_zoom},
            });

            // ── Marker ────────────────────────────────────────────────────────────
            if (showMarker) {
              var el = document.createElement('div');
              el.className            = 'mapbox-marker';
              el.style.width          = {$js_icon_width}  + 'px';
              el.style.height         = {$js_icon_height} + 'px';
              el.style.display        = 'flex';
              el.style.alignItems     = 'center';
              el.style.justifyContent = 'center';
              el.style.cursor         = 'pointer';
              if ({$js_icon_html}) {
                el.innerHTML = {$js_icon_html};
              }

              var marker = new mapboxgl.Marker(el).setLngLat([{$js_lng}, {$js_lat}]);

              if ({$js_description}) {
                marker.setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML({$js_description}));
              }

              marker.addTo(map);
            }

            // ── Service Area ──────────────────────────────────────────────────────
            if (showArea) {
              var addServiceArea = function() {
                map.addSource({$js_src_id}, { type: 'geojson', data: geojson });

                map.addLayer({
                  id: {$js_src_id} + '-fill', type: 'fill', source: {$js_src_id},
                  paint: { 'fill-color': {$js_fill_color}, 'fill-opacity': {$js_fill_opacity} },
                });

                map.addLayer({
                  id: {$js_src_id} + '-outline', type: 'line', source: {$js_src_id},
                  paint: { 'line-color': {$js_outline_color}, 'line-width': {$js_outline_width} },
                });
              };

              if (map.loaded()) { addServiceArea(); } else { map.on('load', addServiceArea); }
            }

            if (!{$js_scroll_zoom}) { map.scrollZoom.disable(); }
          }

          if (typeof mapboxgl !== 'undefined') {
            initMap();
          } else {
            var check = setInterval(function() {
              if (typeof mapboxgl !== 'undefined') { clearInterval(check); initMap(); }
            }, 50);
          }
        })();
        JS;

        echo "<script>" . $init_script . "</script>"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}

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
    const DEFAULT_LAT = -33.767719570242015;
    const DEFAULT_LNG = 150.6844585269858;
    const MAX_PITCH = 85;
    const DEFAULT_CLUSTER_RADIUS = 50;
    const DEFAULT_CLUSTER_MAX_ZOOM = 14;
    // A GeoJSON source's maxzoom defaults to 18, and GL JS needs clusterMaxZoom
    // below it or the deepest zoom never shows unclustered points.
    const MAX_CLUSTER_ZOOM = 17;
    const GEOCODE_ENDPOINT = "https://api.mapbox.com/search/geocode/v6/forward";
    const GEOCODE_CACHE_TTL = 30 * DAY_IN_SECONDS;
    const GEOCODE_FAILURE_TTL = HOUR_IN_SECONDS;

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
     * Resolve dynamic data tags ({post_title}, {acf_…}) in a setting value.
     * Bricks only does this for its own elements, so a custom element has to.
     */
    private function render_dynamic($value)
    {
        if (!is_string($value) || strpos($value, "{") === false) {
            return $value;
        }

        if (!function_exists("bricks_render_dynamic_data")) {
            return $value;
        }

        return bricks_render_dynamic_data($value, $this->post_id);
    }

    /**
     * Read a lat/lng setting as a float, resolving dynamic tags first.
     * GL JS takes numbers; a text control or an ACF number field hands us
     * strings. Anything that doesn't resolve to a number falls back to the
     * default rather than shipping a bad LngLat to the map.
     */
    private function resolve_coordinate($key, $default)
    {
        $raw = trim((string) $this->render_dynamic($this->settings[$key] ?? ""));

        return is_numeric($raw) ? (float) $raw : $default;
    }

    /**
     * Read a numeric setting (zoom, pitch, bearing, cluster options) as a
     * float clamped to the range GL JS accepts. Floats rather than ints so
     * values copied from wpToolkitMapbox.logView() in the console survive
     * as entered.
     */
    private function resolve_clamped_number($key, $default, $min, $max)
    {
        $raw = $this->settings[$key] ?? "";

        if (!is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (float) $raw));
    }

    /**
     * Resolve the map centre as [lng, lat], from either the coordinate
     * controls or a geocoded street address, per the Location control.
     * An address that can't be geocoded falls back to the coordinate
     * controls, so the map still renders somewhere sensible.
     */
    private function resolve_center()
    {
        $lat = $this->resolve_coordinate("lat", self::DEFAULT_LAT);
        $lng = $this->resolve_coordinate("lng", self::DEFAULT_LNG);

        if (($this->settings["location"] ?? "coordinates") !== "address") {
            return [$lng, $lat];
        }

        $address = trim(
            wp_strip_all_tags(
                (string) $this->render_dynamic($this->settings["address"] ?? ""),
            ),
        );

        return $this->geocode($address) ?? [$lng, $lat];
    }

    /**
     * Forward-geocode a street address to [lng, lat] via the Mapbox
     * Geocoding API, cached in a transient keyed by the normalised address.
     *
     * GL JS only takes coordinates, so the lookup happens here rather than in
     * the browser: one request per address per cache period instead of one per
     * page view. Failures are cached briefly too, so a bad address or a
     * rejected token doesn't trigger a request on every render.
     * Returns null when the address can't be resolved.
     */
    private function geocode($address)
    {
        if ($address === "") {
            return null;
        }

        $cache_key =
            "ddwpt_mapbox_geo_" . md5(strtolower(preg_replace("/\s+/", " ", $address)));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return is_array($cached) ? $cached : null;
        }

        $token = trim($this->settings["api_key"] ?? "");
        $url = add_query_arg(
            [
                "q" => rawurlencode($address),
                "limit" => 1,
                "access_token" => rawurlencode($token),
            ],
            self::GEOCODE_ENDPOINT,
        );

        // URL-restricted tokens are checked against the Referer, which a
        // server-side request doesn't send unless we set it.
        $response = wp_remote_get($url, [
            "timeout" => 5,
            "headers" => ["Referer" => home_url("/")],
        ]);

        $center = null;

        if (
            !is_wp_error($response) &&
            wp_remote_retrieve_response_code($response) === 200
        ) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $point = $body["features"][0]["geometry"]["coordinates"] ?? null;

            if (
                is_array($point) &&
                isset($point[0], $point[1]) &&
                is_numeric($point[0]) &&
                is_numeric($point[1])
            ) {
                $center = [(float) $point[0], (float) $point[1]];
            }
        }

        // A transient can't store false (it reads back as a miss), so a
        // failure is cached as an empty string.
        set_transient(
            $cache_key,
            $center ?? "",
            $center ? self::GEOCODE_CACHE_TTL : self::GEOCODE_FAILURE_TTL,
        );

        return $center;
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

    /**
     * Normalise any accepted GeoJSON shape to a flat list of Features.
     * A bare geometry (Polygon, Point, …) is wrapped as a Feature.
     */
    private function geojson_features($data)
    {
        if ($data["type"] === "FeatureCollection") {
            return array_values(
                array_filter($data["features"] ?? [], "is_array"),
            );
        }

        if ($data["type"] === "Feature") {
            return [$data];
        }

        return [["type" => "Feature", "geometry" => $data, "properties" => []]];
    }

    /**
     * Build a Point feature for the points source, or null when the
     * coordinates aren't a usable [lng, lat]. Only a server-sanitised
     * `popup` property is kept: `description` as HTML, else `name` as text.
     */
    private function point_feature($coordinates, $properties)
    {
        if (
            !is_array($coordinates) ||
            !isset($coordinates[0], $coordinates[1]) ||
            !is_numeric($coordinates[0]) ||
            !is_numeric($coordinates[1])
        ) {
            return null;
        }

        $properties = is_array($properties) ? $properties : [];
        $popup = "";

        if (!empty($properties["description"]) && is_string($properties["description"])) {
            $popup = wp_kses_post($properties["description"]);
        } elseif (!empty($properties["name"]) && is_string($properties["name"])) {
            $popup = esc_html($properties["name"]);
        }

        return [
            "type" => "Feature",
            "geometry" => [
                "type" => "Point",
                "coordinates" => [(float) $coordinates[0], (float) $coordinates[1]],
            ],
            "properties" => ["popup" => $popup],
        ];
    }

    /**
     * Split decoded GeoJSON into the service-area shapes and the points.
     *
     * They need separate sources: a clustered GeoJSON source only keeps
     * point features, so polygons sharing it would vanish when clustering
     * is on. MultiPoints are exploded into Points, which is the only shape
     * the clusterer counts. Each half is a FeatureCollection, or null when
     * the GeoJSON has none of that kind.
     */
    private function split_geojson($data)
    {
        $area = [];
        $points = [];

        foreach ($this->geojson_features($data) as $feature) {
            $geometry = is_array($feature["geometry"] ?? null)
                ? $feature["geometry"]
                : [];
            $type = $geometry["type"] ?? "";
            $properties = $feature["properties"] ?? [];

            if ($type === "Point") {
                $points[] = $this->point_feature($geometry["coordinates"] ?? null, $properties);
            } elseif ($type === "MultiPoint") {
                foreach ((array) ($geometry["coordinates"] ?? []) as $coordinates) {
                    $points[] = $this->point_feature($coordinates, $properties);
                }
            } elseif ($type !== "") {
                $area[] = $feature;
            }
        }

        $points = array_values(array_filter($points));

        return [
            "area" => $area ? ["type" => "FeatureCollection", "features" => $area] : null,
            "points" => $points
                ? ["type" => "FeatureCollection", "features" => $points]
                : null,
        ];
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

        $this->control_groups["points"] = [
            "title" => esc_html__("Points & Clustering", "bricks"),
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

        $this->controls["location"] = [
            "group" => "map",
            "label" => esc_html__("Location", "bricks"),
            "type" => "select",
            "options" => [
                "coordinates" => esc_html__("Latitude / Longitude", "bricks"),
                "address" => esc_html__("Street address", "bricks"),
            ],
            "default" => "coordinates",
            "inline" => true,
        ];

        $this->controls["address"] = [
            "group" => "map",
            "label" => esc_html__("Street Address", "bricks"),
            "type" => "text",
            "placeholder" => "1 Main St, Penrith NSW 2750",
            "required" => ["location", "=", "address"],
            "description" => esc_html__(
                "Converted to coordinates with the Mapbox Geocoding API and cached for 30 days. Supports dynamic data. If the address can't be found the map falls back to the default coordinates.",
                "bricks",
            ),
        ];

        $this->controls["lat"] = [
            "group" => "map",
            "label" => esc_html__("Latitude", "bricks"),
            "type" => "text",
            "default" => "-33.767719570242015",
            "required" => ["location", "!=", "address"],
        ];

        $this->controls["lng"] = [
            "group" => "map",
            "label" => esc_html__("Longitude", "bricks"),
            "type" => "text",
            "default" => "150.6844585269858",
            "required" => ["location", "!=", "address"],
        ];

        $this->controls["zoom"] = [
            "group" => "map",
            "label" => esc_html__("Zoom Level", "bricks"),
            "type" => "number",
            "min" => 0,
            "max" => 22,
            "step" => 0.1,
            "default" => 11,
        ];

        $this->controls["pitch"] = [
            "group" => "map",
            "label" => esc_html__("Pitch (°)", "bricks"),
            "type" => "number",
            "min" => 0,
            "max" => self::MAX_PITCH,
            "step" => 1,
            "default" => 0,
            "description" => esc_html__(
                "Camera tilt, 0 (top-down) to 85.",
                "bricks",
            ),
        ];

        $this->controls["bearing"] = [
            "group" => "map",
            "label" => esc_html__("Bearing (°)", "bricks"),
            "type" => "number",
            "min" => -180,
            "max" => 180,
            "step" => 1,
            "default" => 0,
            "description" => esc_html__(
                "Map rotation, 0 is north up. To tune the view, ctrl/right-drag the map to tilt and rotate, then run wpToolkitMapbox.logView() in the browser console and copy the values here.",
                "bricks",
            ),
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
                "Paste GeoJSON directly from geojson.io. Supports FeatureCollection, Feature, Polygon, and MultiPolygon. Point features are drawn as dots (see Points & Clustering) with their description or name property as the popup.",
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

        // ── Points & Clustering ──────────────────────────────────────────────
        // No control here carries a `css` key: each one changes a GL JS source
        // or layer option, so it must re-render for $scripts to rebuild the map.

        $this->controls["point_color"] = [
            "group" => "points",
            "label" => esc_html__("Point Colour", "bricks"),
            "type" => "color",
            "default" => ["hex" => "#1d4ed8"],
        ];

        $this->controls["cluster"] = [
            "group" => "points",
            "label" => esc_html__("Cluster Points", "bricks"),
            "type" => "checkbox",
            "default" => false,
            "description" => esc_html__(
                "Group nearby Point features from the GeoJSON into numbered clusters. Click a cluster to zoom in on it. The main marker is never clustered.",
                "bricks",
            ),
        ];

        $this->controls["cluster_radius"] = [
            "group" => "points",
            "label" => esc_html__("Cluster Radius (px)", "bricks"),
            "type" => "number",
            "min" => 1,
            "max" => 200,
            "step" => 1,
            "default" => self::DEFAULT_CLUSTER_RADIUS,
            "required" => ["cluster", "=", true],
            "description" => esc_html__(
                "How close points must be on screen to merge.",
                "bricks",
            ),
        ];

        $this->controls["cluster_max_zoom"] = [
            "group" => "points",
            "label" => esc_html__("Cluster Max Zoom", "bricks"),
            "type" => "number",
            "min" => 0,
            "max" => self::MAX_CLUSTER_ZOOM,
            "step" => 1,
            "default" => self::DEFAULT_CLUSTER_MAX_ZOOM,
            "required" => ["cluster", "=", true],
            "description" => esc_html__(
                "Above this zoom every point shows on its own.",
                "bricks",
            ),
        ];

        $this->controls["cluster_color"] = [
            "group" => "points",
            "label" => esc_html__("Cluster Colour", "bricks"),
            "type" => "color",
            "default" => ["hex" => "#3b82f6"],
            "required" => ["cluster", "=", true],
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

        $map_height = $s["map_height"] ?? "400px";

        $config = [
            "apiKey" => $api_key,
            "style" => $this->resolve_map_style(),
            "center" => $this->resolve_center(),
            "zoom" => $this->resolve_clamped_number("zoom", 11, 0, 22),
            "pitch" => $this->resolve_clamped_number("pitch", 0, 0, self::MAX_PITCH),
            "bearing" => $this->resolve_clamped_number("bearing", 0, -180, 180),
            "scrollZoom" => !empty($s["scroll_zoom"]),
        ];

        if ($show_marker) {
            $config["marker"] = [
                "width" => isset($s["icon_width"]) ? (int) $s["icon_width"] : 35,
                "height" => isset($s["icon_height"])
                    ? (int) $s["icon_height"]
                    : 35,
                "popup" => wp_kses_post(
                    $this->render_dynamic($s["description"] ?? ""),
                ),
            ];
        }

        $geojson = $this->parse_geojson($s["area_coords"] ?? "");
        $layers =
            $show_area && $geojson !== null
                ? $this->split_geojson($geojson)
                : ["area" => null, "points" => null];

        if ($layers["area"] !== null) {
            $config["area"] = [
                "geojson" => $layers["area"],
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

        if ($layers["points"] !== null) {
            $config["points"] = [
                "geojson" => $layers["points"],
                "color" => $this->resolve_color("point_color", "#1d4ed8"),
                "cluster" => !empty($s["cluster"])
                    ? [
                        "radius" => $this->resolve_clamped_number(
                            "cluster_radius",
                            self::DEFAULT_CLUSTER_RADIUS,
                            1,
                            200,
                        ),
                        "maxZoom" => $this->resolve_clamped_number(
                            "cluster_max_zoom",
                            self::DEFAULT_CLUSTER_MAX_ZOOM,
                            0,
                            self::MAX_CLUSTER_ZOOM,
                        ),
                        "color" => $this->resolve_color("cluster_color", "#3b82f6"),
                    ]
                    : null,
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

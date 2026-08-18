(function () {
  "use strict";

  // Keyed by element id — lets us tear down a stale map when the builder re-renders.
  // Not just tidiness: every Map holds a WebGL context and browsers cap those at
  // ~16, so leaking one per re-render kills the canvas partway through a session.
  var instances = {};
  window.prefixMapboxInstances = instances;

  // Keyed alongside instances. The observer outlives the map, so it has to be
  // disconnected on re-init too.
  var resizeObservers = {};

  var isBuilder = !document.body.classList.contains("bricks-is-frontend");

  function readConfig(root) {
    try {
      return JSON.parse(root.getAttribute("data-mapbox") || "null");
    } catch (e) {
      return null;
    }
  }

  function destroy(id) {
    if (resizeObservers[id]) {
      resizeObservers[id].disconnect();
      delete resizeObservers[id];
    }

    if (instances[id]) {
      try {
        instances[id].remove();
      } catch (e) {}
      delete instances[id];
    }
  }

  /**
   * Build the marker from the server-rendered icon node, falling back to Mapbox's
   * default pin when the element rendered no icon.
   *
   * Sizing is applied here rather than in CSS because both dimensions are controls.
   * A font icon takes its size from font-size, an inlined SVG from its own width /
   * height attributes — so both have to be set for the two icon libraries to end up
   * the same size in the same box.
   */
  function createMarker(root, marker) {
    var el = root.querySelector(".mapbox-map__marker");

    if (!el) {
      return new mapboxgl.Marker();
    }

    el.removeAttribute("hidden");
    el.style.width = marker.width + "px";
    el.style.height = marker.height + "px";
    el.style.display = "flex";
    el.style.alignItems = "center";
    el.style.justifyContent = "center";
    el.style.cursor = "pointer";
    el.style.lineHeight = "1";
    el.style.fontSize = Math.min(marker.width, marker.height) + "px";

    Array.prototype.forEach.call(el.querySelectorAll("svg"), function (svg) {
      svg.style.width = "100%";
      svg.style.height = "100%";
      svg.style.display = "block";
    });

    return new mapboxgl.Marker({ element: el });
  }

  function addServiceArea(map, id, area) {
    var sourceId = "service-area-" + id;

    if (map.getSource(sourceId)) {
      return;
    }

    map.addSource(sourceId, { type: "geojson", data: area.geojson });

    map.addLayer({
      id: sourceId + "-fill",
      type: "fill",
      source: sourceId,
      paint: {
        "fill-color": area.fillColor,
        "fill-opacity": area.fillOpacity,
      },
    });

    map.addLayer({
      id: sourceId + "-outline",
      type: "line",
      source: sourceId,
      paint: {
        "line-color": area.outlineColor,
        "line-width": area.outlineWidth,
      },
    });
  }

  function buildMap(root) {
    var config = readConfig(root);

    if (!config || !config.apiKey) {
      return;
    }

    var id = root.getAttribute("data-mapbox-id") || root.id;
    var container = root.querySelector(".mapbox-map__canvas");

    if (!container) {
      return;
    }

    destroy(id);

    mapboxgl.accessToken = config.apiKey;

    var map;

    try {
      map = new mapboxgl.Map({
        container: container,
        style: config.style,
        center: config.center,
        zoom: config.zoom,
      });
    } catch (e) {
      // A half-typed custom style URL or a bad token throws here. In the builder
      // that is a control mid-edit, so it must not take the whole init down.
      if (isBuilder && window.console) {
        console.warn("[Mapbox] " + e.message);
      }
      return;
    }

    instances[id] = map;

    if (!config.scrollZoom) {
      map.scrollZoom.disable();
    }

    if (config.marker) {
      var marker = createMarker(root, config.marker).setLngLat(config.center);

      if (config.marker.popup) {
        marker.setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(config.marker.popup));
      }

      marker.addTo(map);
    }

    if (config.area) {
      var add = function () {
        addServiceArea(map, id, config.area);
      };

      if (map.loaded()) {
        add();
      } else {
        map.on("load", add);
      }
    }

    // Mapbox only measures its container at init and on window resize. Neither
    // covers the builder — the canvas iframe resizes when panels open, and the
    // Height style control patches CSS without a re-render — so without this the
    // map keeps whatever size it had when it was created.
    if (typeof ResizeObserver !== "undefined") {
      var observer = new ResizeObserver(function () {
        map.resize();
      });

      observer.observe(container);
      resizeObservers[id] = observer;
    }
  }

  function initAll() {
    if (typeof mapboxgl === "undefined") {
      return;
    }

    // In the builder always re-init: Bricks replaces the markup on every setting
    // change, so a fresh root never carries data-mapbox-init.
    var selector = isBuilder ? ".brxe-mapbox-map" : ".brxe-mapbox-map:not([data-mapbox-init])";

    Array.prototype.forEach.call(document.querySelectorAll(selector), function (root) {
      root.setAttribute("data-mapbox-init", "1");
      buildMap(root);
    });
  }

  if (!window.bricksFunctions) {
    window.bricksFunctions = [];
  }

  window.bricksFunctions.push({ run: initAll });

  // Bricks calls this by name after re-rendering the element in the builder canvas.
  // Must match the element's $scripts entry — see element-mapbox.php.
  window.prefixMapboxInit = initAll;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }

  document.addEventListener("bricks/ajax/query_result/displayed", initAll);
})();

(function () {
    'use strict';

    // Keyed by element id — lets us destroy stale instances when the builder re-renders
    var instances = {};

    // Keyed by element id, alongside instances. Custom arrows matched by a user
    // selector live outside the slider root, so they survive the builder's re-render
    // and would stack a fresh click listener on every init. Aborting the previous
    // controller removes every listener we attached with its signal in one go.
    var arrowControllers = {};

    /**
     * Resolve a user-supplied CSS selector document-wide.
     *
     * querySelectorAll throws on invalid selector syntax, so a half-typed selector in
     * the builder would otherwise take the whole slider init down with it.
     */
    function queryCustomArrows(selector) {
        if (typeof selector !== 'string' || !selector) return [];

        try {
            return Array.prototype.slice.call(document.querySelectorAll(selector));
        } catch (e) {
            return [];
        }
    }

    /**
     * Grid mode. Embla is strictly one-dimensional: it measures every slide's
     * offsetLeft and derives each scroll snap from the gaps between consecutive
     * edges. A wrapped or CSS-grid layout gives slides in the same column an
     * identical offset, which computes as a zero-width slide and corrupts both the
     * snap list and canLoop(). So the DOM is regrouped instead — children are
     * wrapped into columns of N, and Embla still sees one flat line of slides.
     *
     * Idempotent: any previous grouping is undone first, since the builder re-runs
     * init against markup that may or may not already carry wrappers.
     */
    function applyRowGrouping(container, rows) {
        if (!container) return;

        // Unwrap previous groups, restoring the original children in order
        var existing = container.querySelectorAll('[data-embla-group]');
        Array.prototype.forEach.call(existing, function (group) {
            while (group.firstChild) {
                container.insertBefore(group.firstChild, group);
            }
            container.removeChild(group);
        });

        if (!(rows > 1)) return;

        var items = Array.prototype.slice.call(container.children);

        for (var i = 0; i < items.length; i += rows) {
            var group = document.createElement('div');
            group.setAttribute('data-embla-group', '');
            group.className = 'embla__group';
            container.insertBefore(group, items[i]);

            for (var j = i; j < Math.min(i + rows, items.length); j++) {
                group.appendChild(items[j]);
            }
        }
    }

    /**
     * Embla's *effective* loop state, which is not always the requested one — see
     * warnIfLoopDisabled(). Returns null when it can't be determined, since
     * internalEngine() is advanced API that may change shape between versions.
     */
    function getEffectiveLoop(embla) {
        var engine;

        try {
            engine = embla.internalEngine();
        } catch (e) {
            return null;
        }

        if (!engine || !engine.options) return null;

        return !!engine.options.loop;
    }

    /**
     * Embla turns `loop` off silently when slide content isn't enough to create the
     * loop effect without visible glitches. Its canLoop() check requires the sum of
     * all slide sizes, minus the largest single slide, to cover the viewport — so
     * gaps, padding and variable slide widths all shift where the threshold lands.
     *
     * Nothing is logged by Embla itself, so a slider just quietly stops looping.
     * Surface it in the builder where it's actionable.
     */
    function warnIfLoopDisabled(embla, options, root, container) {
        if (!isBuilder || !options.loop) return;
        if (getEffectiveLoop(embla) !== false) return;

        var slideCount = container ? container.children.length : 0;
        // Computed, not root.style — --embla-per-page lives in a breakpoint-aware
        // stylesheet rule now, so the inline style attribute no longer carries it.
        // parseFloat, not parseInt — fractional per-page values (3.5) are valid.
        var perPage = parseFloat(getComputedStyle(root).getPropertyValue('--embla-per-page')) || 1;

        console.warn(
            '[Embla Slider] Embla fell back to loop: false — slide content is not enough to ' +
            'create the loop effect without visible glitches. This slider has ' + slideCount +
            ' slide(s) showing ' + perPage + ' at a time. Add slides, lower "Items to show", ' +
            'or set Type to "Slide".'
        );
    }

    function buildEmbla(root) {
        if (typeof EmblaCarousel === 'undefined') return;

        var configRaw = root.getAttribute('data-embla');
        if (!configRaw) return;

        var config = {};
        try {
            config = JSON.parse(configRaw);
        } catch (e) {
            return;
        }

        var viewport = root.querySelector('.embla__viewport');
        if (!viewport) return;

        var container = viewport.querySelector('.embla__container');
        var prevBtn = root.querySelector('.embla__button--prev');
        var nextBtn = root.querySelector('.embla__button--next');
        var dotsEl = root.querySelector('.embla__dots');
        var options = config.options || {};
        var elementId = root.id;

        // Destroy any previous instance for this element (builder re-renders same id)
        if (elementId && instances[elementId]) {
            try { instances[elementId].destroy(); } catch (e) {}
            delete instances[elementId];
        }

        // Drop the previous init's arrow listeners before attaching this init's
        if (elementId && arrowControllers[elementId]) {
            arrowControllers[elementId].abort();
            delete arrowControllers[elementId];
        }

        // Grid mode: regroup children into columns before anything measures them
        applyRowGrouping(container, config.rows);

        // A slide is whatever ends up a direct child — the items themselves, or the
        // column wrappers in grid mode. Clear the class off everything first so items
        // nested by a previous grouping stop claiming to be slides.
        if (container) {
            Array.prototype.forEach.call(container.querySelectorAll('.embla__slide'), function (el) {
                el.classList.remove('embla__slide');
            });

            Array.prototype.forEach.call(container.children, function (child) {
                child.classList.add('embla__slide');
            });
        }

        var plugins = [];
        if (config.autoplay && typeof EmblaCarouselAutoplay !== 'undefined') {
            plugins.push(EmblaCarouselAutoplay(config.autoplay));
        }

        // Must be set BEFORE init. Embla reads the last slide's computed trailing
        // margin once, while measuring, to size the loop seam gap (see the CSS).
        // Margin does not change the border box, so ResizeObserver never fires for
        // it — a class applied after init is silently ignored until something else
        // forces a re-measure. Keyed off the requested option because the effective
        // one isn't knowable until the engine exists; corrected below if it differs.
        root.classList.toggle('embla--loop', !!options.loop);

        var embla = EmblaCarousel(viewport, options, plugins);

        if (elementId) {
            instances[elementId] = embla;
        }

        warnIfLoopDisabled(embla, options, root, container);

        // Embla silently drops loop when slide content is too thin. Strip the seam
        // margin back off so a non-looping slider doesn't carry phantom trailing
        // space, and re-measure, since the margin was counted during init.
        if (options.loop && getEffectiveLoop(embla) === false) {
            root.classList.remove('embla--loop');
            embla.reInit();
        }

        // Arrows — the built-in buttons plus any elements matched by the custom selectors
        var customPrevArrows = queryCustomArrows(config.prevArrowSelector);
        var customNextArrows = queryCustomArrows(config.nextArrowSelector);

        var arrowController = typeof AbortController === 'undefined' ? null : new AbortController();
        var arrowListenerOptions = arrowController ? { signal: arrowController.signal } : false;

        if (elementId && arrowController) {
            arrowControllers[elementId] = arrowController;
        }

        function bindScrollPrev(arrow) {
            arrow.addEventListener('click', function () { embla.scrollPrev(); }, arrowListenerOptions);
        }

        function bindScrollNext(arrow) {
            arrow.addEventListener('click', function () { embla.scrollNext(); }, arrowListenerOptions);
        }

        if (prevBtn) bindScrollPrev(prevBtn);
        if (nextBtn) bindScrollNext(nextBtn);

        customPrevArrows.forEach(bindScrollPrev);
        customNextArrows.forEach(bindScrollNext);

        function syncArrows() {
            var canScrollPrev = embla.canScrollPrev();
            var canScrollNext = embla.canScrollNext();

            if (prevBtn) prevBtn.disabled = !canScrollPrev;
            if (nextBtn) nextBtn.disabled = !canScrollNext;

            // Custom arrows are arbitrary elements, not necessarily <button>, so
            // .disabled would be meaningless on them — expose the state as a class.
            customPrevArrows.forEach(function (arrow) { arrow.classList.toggle('is-disabled', !canScrollPrev); });
            customNextArrows.forEach(function (arrow) { arrow.classList.toggle('is-disabled', !canScrollNext); });
        }

        embla.on('init', syncArrows);
        embla.on('select', syncArrows);
        embla.on('reInit', syncArrows);

        // Pagination dots
        if (dotsEl) {
            var dots = [];

            function buildDots() {
                dotsEl.innerHTML = '';
                dots = [];
                embla.scrollSnapList().forEach(function (_, i) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.role = 'tab';
                    dot.classList.add('embla__dot');
                    dot.setAttribute('aria-label', 'Slide ' + (i + 1));
                    dotsEl.appendChild(dot);
                    dot.addEventListener('click', function () { embla.scrollTo(i); });
                    dots.push(dot);
                });
                syncDots();
            }

            function syncDots() {
                var active = embla.selectedScrollSnap();
                dots.forEach(function (dot, i) {
                    dot.classList.toggle('is-active', i === active);
                    dot.setAttribute('aria-selected', i === active ? 'true' : 'false');
                });
            }

            embla.on('init', buildDots);
            embla.on('reInit', buildDots);
            embla.on('select', syncDots);
        }

        // Auto height
        if (config.autoHeight) {
            root.classList.add('embla--auto-height');

            function updateHeight() {
                if (!container) return;
                var slide = container.children[embla.selectedScrollSnap()];
                if (slide) {
                    viewport.style.height = slide.offsetHeight + 'px';
                }
            }

            embla.on('init', updateHeight);
            embla.on('select', updateHeight);
            embla.on('settle', updateHeight);
        }
    }

    var isBuilder = !document.body.classList.contains('bricks-is-frontend');

    function initAll() {
        if (typeof EmblaCarousel === 'undefined') return;

        // In the builder always reinit — Bricks replaces DOM on every setting change,
        // leaving new elements without data-embla-init. On frontend, skip already-inited ones.
        var selector = isBuilder
            ? '.brxe-prefix-embla-slider'
            : '.brxe-prefix-embla-slider:not([data-embla-init])';

        Array.prototype.forEach.call(document.querySelectorAll(selector), function (root) {
            root.setAttribute('data-embla-init', '1');
            buildEmbla(root);
        });
    }

    // Register with Bricks' function runner so the builder triggers us on element re-renders
    if (!window.bricksFunctions) {
        window.bricksFunctions = [];
    }
    window.bricksFunctions.push({ run: initAll });

    // Bricks calls this by name after re-rendering the element in the builder canvas.
    // Must match the element's $scripts entry — see element-embla-slider.php.
    window.prefixEmblaInit = initAll;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    document.addEventListener('bricks/ajax/query_result/displayed', initAll);
})();

(function () {
    'use strict';

    // Keyed by element id — lets us destroy stale instances when the builder re-renders
    var instances = {};

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

        // Add slide class to each direct child so CSS targeting works
        if (container) {
            Array.prototype.forEach.call(container.children, function (child) {
                child.classList.add('embla__slide');
            });
        }

        var plugins = [];
        if (config.autoplay && typeof EmblaCarouselAutoplay !== 'undefined') {
            plugins.push(EmblaCarouselAutoplay(config.autoplay));
        }

        var embla = EmblaCarousel(viewport, options, plugins);

        if (elementId) {
            instances[elementId] = embla;
        }

        // Arrows
        if (prevBtn) {
            prevBtn.addEventListener('click', function () { embla.scrollPrev(); });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () { embla.scrollNext(); });
        }

        function syncArrows() {
            if (prevBtn) prevBtn.disabled = !embla.canScrollPrev();
            if (nextBtn) nextBtn.disabled = !embla.canScrollNext();
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

    // Expose globally so inline scripts in render() can call us after builder re-renders
    window.prefixEmblaInit = initAll;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    document.addEventListener('bricks/ajax/query_result/displayed', initAll);
})();

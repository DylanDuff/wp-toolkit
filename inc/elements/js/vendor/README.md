# Vendored Embla Carousel

Third-party, unmodified. Do not edit these files — replace them wholesale.

| File | Package | Version | Global |
|---|---|---|---|
| `embla-carousel.umd.js` | [embla-carousel](https://www.npmjs.com/package/embla-carousel) | 8.6.0 | `EmblaCarousel` |
| `embla-carousel-autoplay.umd.js` | [embla-carousel-autoplay](https://www.npmjs.com/package/embla-carousel-autoplay) | 8.6.0 | `EmblaCarouselAutoplay` |

MIT licensed (`LICENSE`), © David Jerleke.

For why this one is vendored while Rive/Unicorn Studio/Mapbox stay on a CDN, see `docs/bricks-elements.md`.

## Updating

```bash
V=8.6.0
cd inc/elements/js/vendor
curl -sL "https://cdn.jsdelivr.net/npm/embla-carousel@$V/embla-carousel.umd.js" -o embla-carousel.umd.js
curl -sL "https://cdn.jsdelivr.net/npm/embla-carousel-autoplay@$V/embla-carousel-autoplay.umd.js" -o embla-carousel-autoplay.umd.js
```

Then update `EMBLA_VERSION` in `inc/elements/element-embla-slider.php` (it is the
`wp_enqueue_script` cache-buster) and the table above. Keep both packages on the
same version — the autoplay plugin tracks core's major.

## Before a major bump

**Embla 9 renamed options this element passes.** `render()` builds `$embla_options`
with v8 names; these became:

| v8 | v9 |
|---|---|
| `startIndex` | `startSnap` |
| `watchResize` | `resize` |
| `watchSlides` | `slideChanges` |
| `watchDrag` | `draggable` |
| `watchFocus` | `focus` |

Unknown options are ignored silently rather than erroring, so a bump without
updating `render()` degrades quietly instead of failing loudly.

Two upstream behaviours the element depends on, worth re-checking on any bump:

- **Silent loop fallback**, detected via `embla.internalEngine().options.loop` —
  `internalEngine()` is advanced API and may change shape.
- **`watchResize`**, which is what picks up CSS-driven slide geometry changes
  (Bricks does not re-render the element for those).

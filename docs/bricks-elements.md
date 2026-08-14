# Custom Bricks Elements

Element classes live in `inc/elements/`, companion JS in `inc/elements/js/`, CSS in `inc/elements/css/`, vendored third-party runtimes in `inc/elements/js/vendor/`.

Each element is registered by its own tweak, so it can be toggled off without touching code:

```php
add_action( 'init', function () {
    if ( ! class_exists( '\Bricks\Elements' ) ) {
        return;
    }
    \Bricks\Elements::register_element( dirname( __DIR__ ) . '/elements/element-embla-slider.php' );
}, 11 );
```

All Bricks code must guard with `defined('BRICKS_VERSION')` or a `class_exists('\Bricks\Elements')` check — the plugin runs fine on non-Bricks sites.

---

## Builder lifecycle

Two things about how Bricks re-renders elements in the builder are undocumented upstream, easy to get wrong, and fail silently.

### 1. `$scripts` — how element JS re-runs

The builder replaces element markup via Vue, so **any `<script>` tag emitted from `render()` never executes there**. It works on the frontend and is inert in the canvas, which looks like "the element is broken in the builder only".

The supported mechanism is the `$scripts` property. Bricks calls `window[<name>]()` in the canvas iframe after every re-render:

```php
public $scripts = [ 'prefixEmblaInit' ];
```

The JS must expose a matching global:

```js
window.prefixEmblaInit = initAll;
```

Bricks core does the same thing (`slider-nested.php` declares `bricksSplide`). Don't hand-roll an inline loader — `enqueue_scripts()` already runs in both contexts: on the frontend via `Element::init()`, and in the builder iframe via `Elements::register_element()`.

### 2. The `css` key decides whether a re-render happens at all

A control **with** a `css` key only patches the generated stylesheet in the canvas — no server re-render, and `$scripts` does **not** re-run. A control **without** `css` triggers an AJAX re-render, which does re-run it.

| Control shape | Builder behaviour | Element JS re-runs? |
|---|---|---|
| Has `css` | Stylesheet patched in place | No |
| No `css` | AJAX re-render | Yes |
| `'rerender' => true` | Forces re-render regardless | Yes |

`rerender` is an undocumented control property, but Bricks core uses it (`animated-typing.php`, `base.php` swiper arrows). `'rerender' => false` suppresses; Bricks sets it on sub-fields of composite border/dimension controls.

**The practical rule:** anything that changes a JS runtime option must not have a `css` key, or the change won't reach the JS. Anything purely cosmetic should have one, so the builder stays fast.

Geometry controls are the interesting middle case — they write CSS only, so no re-render, but the resulting size change still has to reach the JS. For the Embla slider that works because Embla's own `watchResize` ResizeObserver calls `reInit()`. An element without that kind of self-measurement would need `'rerender' => true`.

---

## Third-party runtimes: vendored vs CDN

Both approaches are in use, deliberately. The deciding question is **whether upstream changes affect files users have already authored.**

| Element | Runtime | Source | Why |
|---|---|---|---|
| Embla Slider | embla-carousel 8.6.0 | **Vendored** (`js/vendor/`) | A slider's behaviour is finished once it works. Nothing a user authors depends on the library version. |
| Rive | @rive-app 2.31.5 | CDN, exact pin | `.riv` files are exported by the Rive editor; the runtime has to keep up with export format changes. |
| Unicorn Studio | unicornstudio.js 2.1.6 | CDN, exact pin | Same — scene exports track the tool. |
| Mapbox | mapbox-gl-js 3.0.1 | CDN, exact pin | Vendor-hosted API client, tied to a remote service. |

For the CDN elements, staying on a CDN means a version bump is a one-line change that immediately serves the runtime an updated export expects. Vendoring those would mean shipping a plugin release every time the upstream editor changes its export format.

Embla has no such coupling, so it's vendored: no third-party request on every frontend page that renders a slider, works offline and in local dev, and no exposure to a bad upstream patch.

**Whichever is used, pin an exact version.** A floating major (`@8`) means jsdelivr serves whatever is current, so an upstream patch reaches every site at once with no way to roll back.

See `inc/elements/js/vendor/README.md` for the Embla update procedure and the option renames to check before a major bump.

---

## Embla Slider

`inc/elements/element-embla-slider.php` — nestable, registered by the `embla-slider-bricks` tweak. Slides are the element's direct children; the init JS adds `.embla__slide` to each.

### Loop falls back silently

Embla drops `loop: true` to `false` when slide content can't cover the viewport — its `canLoop()` requires the sum of all slide sizes, minus the largest single slide, to reach the viewport width. Gaps, padding and variable slide widths all shift where that threshold lands.

Nothing is logged upstream, so a slider just quietly stops looping. `warnIfLoopDisabled()` detects it by comparing the requested option against `embla.internalEngine().options.loop` and warns in the builder only. `internalEngine()` is advanced API, so the call is wrapped in try/catch.

### CSS custom property contract

Slide width is one `calc()` in `prefix-embla-slider.css`, fed by two breakpoint-aware controls:

```css
flex: 0 0 calc((100% - (var(--embla-per-page, 2) - 1) * var(--embla-gap, 1rem)) / var(--embla-per-page, 2));
```

| Property | Control | Notes |
|---|---|---|
| `--embla-per-page` | Items to show | Unitless, decimals allowed. A `number` control with no `unit`/`units` key emits the bare value — adding units yields `3px` and breaks the calc. |
| `--embla-gap` | Spacing | Also drives `column-gap`/`row-gap` on the container. |

Gaps sit *between* slides, so N per view means N−1 gaps to subtract; without that the last visible slide is clipped by the accumulated gap width.

**Fractional values (`3.5`) are supported**, unlike Splide. Embla has no per-page or `slidesPerView` option at all — `measureSize()` reads each slide's rect and every scroll snap, `canLoop()` sum and translate is derived from those measurements, with no integer assumption anywhere. So "3.5 slides" is purely a CSS width to us and an ordinary measured width to Embla. The control carries `'step' => 'any'`; without it the number input defaults to `step="1"` and rejects decimals.

Neither may be written as an inline style — inline outranks media queries and would pin every breakpoint to one value. The `var()` fallbacks mirror the control defaults (`DEFAULT_PER_PAGE`); keep them in step.

### The loop seam gap

Per Embla's own docs: *"When using gap with `loop: true`, there will be no gap between the last and first slide."* Embla loops by applying a transform to slides, and a transform doesn't carry the container's `column-gap`, so the wrap point comes out flush.

The fix is a trailing margin on the last slide. Embla reads it explicitly while measuring:

```js
const endGap = parseFloat(
    getComputedStyle(lastSlide).getPropertyValue('margin-' + endEdge)
);
// last slide's size-with-gap = its measured size + endGap
```

Three things follow from that one line, each of which fails silently on its own:

- **It must be on `.embla__slide:last-child`.** A margin on `.embla__container` is never read.
- **Physical properties only.** `endEdge` is `right` for horizontal LTR, `left` for RTL, `bottom` for vertical. A logical `margin-inline-end` resolves to `margin-right` regardless of Embla's `direction` option unless the document `dir` is also set, so it misses on RTL. Hence the `data-embla-dir="rtl"` marker.
- **`.embla--loop` must be on the root before `EmblaCarousel()` runs.** Margin doesn't change the border box, so ResizeObserver won't fire for it and a class added after init is ignored until something else forces a re-measure. This is why it appears to work when poked in dev tools but not on load. The JS therefore sets the class from the *requested* `loop` option before init, then removes it and calls `reInit()` if Embla turned looping off.

`.embla--loop` is toggled by the init JS from `getEffectiveLoop()`, **not** from the requested option — a slider whose loop was silently disabled must not get phantom space after its final slide. Adding the margin grows total content, which can only make `canLoop()` more likely to pass, so the ResizeObserver reInit it triggers settles rather than oscillates.

Embla's *preferred* fix for gaps generally is slide padding plus a negative container margin. That isn't usable here: the Slide → Padding control writes a `padding` shorthand to `.embla__slide` at ID specificity and would clobber it.

### Grid mode (Rows)

`Rows > 1` stacks items into columns: the JS wraps every N children in a `.embla__group`, and "Items to show" then counts columns. `Rows = 1` is the default and skips wrapping entirely, so the normal path is unchanged.

It has to be DOM regrouping rather than a CSS grid. Embla derives every scroll snap from the difference between consecutive slides' `offsetLeft`:

```js
h = rects.map((r, i, arr) => … arr[i + 1][startEdge] - r[startEdge]);
```

Any wrapped or `grid-auto-flow: column` layout gives same-column slides an identical offset, which computes as a zero-width slide and corrupts both the snap list and `canLoop()`. Grouping keeps Embla seeing one flat line.

The wrapper is **flex-column, not a CSS grid**. It becomes the `.embla__slide`, so the Slide align controls (`align-items` / `justify-content`) must keep meaning what they mean on a Bricks block, which is also flex-column — on a grid container those two swap axes.

`applyRowGrouping()` unwraps any previous grouping and clears stale `.embla__slide` classes before regrouping, because the builder re-runs init against markup that may or may not already carry wrappers.

Unlike Items to show and Spacing, Rows is **not breakpoint-aware** — it changes DOM structure rather than CSS, so per-breakpoint values would need JS re-chunking on media query changes.

### Arrows

The `arrows` control is a mode:

- `none` — no arrows rendered.
- `builtin` — renders `.embla__button--prev` / `.embla__button--next` inside the element; the Arrows style controls apply.
- `custom` — binds any elements matching user-supplied CSS selectors, anywhere on the page.

Custom arrows live outside the element root, so they survive a builder re-render and would stack a listener on every init. One `AbortController` per init owns every arrow listener; the previous one is aborted before rebinding. Selectors are resolved in a try/catch — `querySelectorAll` throws on invalid syntax, and a half-typed selector in the builder must not take slider init down with it.

Built-in arrows get `.disabled`; custom ones get an `is-disabled` class instead, since they aren't necessarily `<button>`. No styling ships for that class.

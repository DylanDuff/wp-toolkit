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

### 3. `enqueue_scripts()` runs too late for CSS

Bricks calls an element's `enqueue_scripts()` from `Element::init()` — while rendering the page body, after `wp_head()`. WordPress therefore prints the stylesheet as a **late style, in the footer**, and two things follow:

- **The first paint has no element CSS.** For the slider that means no flex container: every slide stacks vertically at full width, then the page reflows into columns when the footer stylesheet lands. Highly visible on a grid-mode slider.
- **Element defaults beat the user's style controls.** Bricks compiles a style control to `.brxe-{id} .embla__dot` — scope class plus target class, (0,2,0). A base rule written as `.brxe-{element-name} .embla__dot` ties it exactly, so source order decides, and the footer always comes last. The control silently does nothing.

Both are fixed together:

```php
// Tweak: ahead of Bricks' inline CSS (Frontend::enqueue_inline_css, priority 11)
add_action( 'wp_enqueue_scripts', fn() => Prefix_Element_Embla_Slider::maybe_enqueue_assets(), 5 );
```

`maybe_enqueue_assets()` scans `Bricks\Database::$page_data['header'|'content'|'footer']` — flat element lists per area, populated on `wp`, nested elements included — and enqueues only when the element is actually on the page. `enqueue_scripts()` stays as the fallback for what that check can't see: elements inside a Bricks template, popups, AJAX renders.

Independently, base CSS scopes itself with `:where(.brxe-{element-name})` so it contributes a single class of specificity and always loses to the generated control CSS regardless of load order. Scoping without `:where()` is the trap — it looks harmless and quietly outranks every style control the element exposes.

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

## Mapbox Map

`inc/elements/element-mapbox.php` — registered by the `mapbox-bricks` tweak. Runtime is `mapbox-gl-js` from the Mapbox CDN, exact-pinned in `MAPBOX_GL_VERSION`; the element's own logic lives in `js/prefix-mapbox.js` and is exposed as `window.prefixMapboxInit` for `$scripts`.

Markup is a Bricks root wrapping `.mapbox-map__canvas` (the Mapbox container) and, when the marker has an icon, a hidden `.mapbox-map__marker` node. Everything else is one JSON blob in `data-mapbox` on the root.

**The map height sits on the inner canvas, not the root.** An inline `height` on the root would outrank the Layout → Height style control, which is generated CSS.

### One WebGL context per map

Each `mapboxgl.Map` holds a WebGL context and browsers cap those at roughly 16. The builder re-renders on every control change, so without teardown a handful of edits exhausts the budget and the canvas dies mid-session. The JS keys live instances by element id and calls `map.remove()` before rebuilding.

### `window.prefixMapboxInstances`

That same registry is exposed on `window` so page-level JS can reach a live `Map` — for anything that belongs to one specific page rather than in the element's controls:

```js
var map = window.prefixMapboxInstances['abc123']; // key = the Bricks element id
```

Two things to know before relying on it:

- **It is mutated, never reassigned.** `destroy()` deletes keys off the same object, so a reference captured once stays current. Keep it that way — reassigning `instances` would strand every existing reference.
- **A map only appears after init runs**, which is on `DOMContentLoaded` at the earliest, and in the builder on every re-render. Page JS that reads the registry at load time can race it, and a builder re-render swaps the instance out from under a captured reference. Read it at point of use, or poll for the key.

### Resize is not automatic

Mapbox measures its container at init and on **window** resize only. Neither covers the builder — the canvas iframe resizes when panels open, and a control carrying a `css` key patches the stylesheet with no re-render — so a `ResizeObserver` on the container calls `map.resize()`. It is disconnected alongside the map instance on re-init.

### The marker icon is rendered server-side

`Bricks\Element::render_icon()` does the work; do not hand-roll it. A custom SVG icon carries **no `icon` key at all** — only `svg.id` — so any check shaped like `empty($icon['icon'])` silently drops every uploaded icon and renders nothing. `render_icon()` also handles dynamic-data icons and inlines the SVG file rather than pointing an `<img>` at its URL, so `currentColor` works.

The node is rendered into the markup `hidden` rather than passed through the JSON config, so an inlined SVG never round-trips through an attribute. The JS unhides it, sizes it, and hands the node to `mapboxgl.Marker`. Font icons take their size from `font-size` and inlined SVGs from their own `width`/`height`, so the JS sets both — otherwise the two libraries render at different sizes in the same box. With no icon configured it falls through to Mapbox's default pin.

### Custom styles

`map_style` is a preset list plus a `custom` option that reveals `map_style_url`. Mapbox Studio's Share panel hands out both `mapbox://styles/…` and `https://api.mapbox.com/styles/v1/…`; GL JS takes either, so `resolve_map_style()` only has to pass those two through (`esc_url_raw` restricted to http/https) and fall back to the default for anything else. A custom style must be published, and public or owned by the token's account.

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

`Rows > 1` stacks items into columns: every N children are wrapped in a `.embla__group`, and "Items to show" then counts columns. `Rows = 1` is the default and skips wrapping entirely, so the normal path is unchanged.

**The frontend renders the final structure server-side** (`render_slides()`): the groups *and* the `.embla__slide` class that carries the slide width are in the HTML Bricks outputs. Doing this in JS alone meant the page painted a flat, unsized row and then reflowed into columns once the script ran — a visible layout shift on every load, proportional to how many rows were configured. `render_slides()` walks the children itself (mirroring `Frontend::render_children()`, component instances included) because the core helper returns one concatenated string with no seam to chunk on; if it can't resolve the child ids it falls back to the flat render rather than dropping the slides.

**Group boundaries are injected per rendered slide, not per child element.** The distinction is everything for a query loop: that is *one* child element which renders N sibling nodes, so chunking the element list puts the whole loop in column 1 — the entire slider in a single stack — and Bricks concatenates the iterations into one string with no seam to split afterwards.

The seam is the `bricks/frontend/render_element` filter (Bricks 2.0+). Bricks runs a query loop by passing `Frontend::render_element` *itself* as the loop callback:

```php
// Bricks: includes/elements/container.php
$element['looped'] = true;
$output = $query->render( 'Bricks\Frontend::render_element', compact( 'element' ) );
```

so the filter fires once per iteration. `render_slides()` hooks it for the duration of its own `render_children()` call and prefixes `</div><div class="embla__group embla__slide" data-embla-group>` every N slides. Three guards make that safe:

- **Skip the loop element's own render.** It fires again wrapping the concatenated iterations, which already carry their boundaries. Iterations are marked `looped` with `hasLoop` unset; the wrapper is the reverse.
- **Match direct children only** — the filter fires for every descendant too. Component instances suffix the id with `-{instanceId}`, so compare the base id.
- **Ignore empty renders**, so a child hidden on the frontend doesn't consume a grid cell.

If the filter never fires (Bricks < 2.0), the children are already rendered flat, and the root gets `data-embla-rows` plus an inline `--embla-rows` instead. That triggers a **pre-init grid** in the CSS:

```css
:where(.brxe-prefix-embla-slider[data-embla-rows]:not([data-embla-init])) .embla__container {
    display: grid;
    grid-template-rows: repeat(var(--embla-rows, 1), 1fr);
    grid-auto-flow: column;
    grid-auto-columns: /* same width calc as .embla__slide */;
}
```

Column-flow grid over flat children approximates the wrappers — same order, widths and gaps. It is a fallback, not a match: grid rows are shared across every column, so with `1fr` all rows equal the tallest card in the *whole* grid, while the real wrappers size each column independently. On a long loop that overshoots the settled height noticeably. It is scoped to `:not([data-embla-init])` because Embla itself cannot run on a grid: same-column slides share an `offsetLeft`, which it measures as a zero-width slide. It is also a reasonable no-JS fallback.

The JS still derives the same structure, because it has to: the builder never gets server-rendered children (see above), and a Bricks query-loop re-render replaces the markup. `applyRowGrouping()` therefore starts with `groupingIsCurrent()` and returns early when the DOM already matches — not for speed, but because moving a node in the DOM reloads any iframe inside it, so unconditional regrouping would restart embedded videos and maps on every init.

It has to be DOM regrouping rather than a CSS grid. Embla derives every scroll snap from the difference between consecutive slides' `offsetLeft`:

```js
h = rects.map((r, i, arr) => … arr[i + 1][startEdge] - r[startEdge]);
```

Any wrapped or `grid-auto-flow: column` layout gives same-column slides an identical offset, which computes as a zero-width slide and corrupts both the snap list and `canLoop()`. Grouping keeps Embla seeing one flat line.

The wrapper is **flex-column, not a CSS grid**. It becomes the `.embla__slide`, so the Slide align controls (`align-items` / `justify-content`) must keep meaning what they mean on a Bricks block, which is also flex-column — on a grid container those two swap axes.

When it does regroup, `applyRowGrouping()` unwraps any previous grouping and clears stale `.embla__slide` classes first, because the builder re-runs init against markup that may or may not already carry wrappers.

Unlike Items to show and Spacing, Rows is **not breakpoint-aware** — it changes DOM structure rather than CSS, so per-breakpoint values would need JS re-chunking on media query changes.

### The children placeholder (builder only)

A nestable PHP element does **not** get its children rendered into its markup in the builder. `Frontend::render_children()` returns a placeholder instead, and Vue moves the real child nodes in afterwards:

```php
// Bricks: includes/frontend.php — builder path
return '<div class="brx-nestable-children-placeholder"></div>';
```

```js
// Bricks: BricksElementPHP.vue → moveChildElements()
placeholder.after(...this.$refs.children.childNodes)
this.$nextTick(() => this.$_runElementScripts(this.name))   // ← $scripts fires here
```

Three consequences for any element that touches its own children in JS:

1. **The placeholder stays in the DOM.** It is `display: none !important` (builder.min.css) so it is invisible, but it is still a direct child of `.embla__container`. Left unfiltered, Embla takes it as a slide — its slide list is `[].slice.call(slidesOption || container.children)` — giving a zero-width phantom slide at index 0 that shifts every snap, slide index and `slideRegistry` entry, and skews `canLoop()`. The JS pins the slide list to `':scope > .embla__slide'` so this can't happen.

2. **It is the insertion anchor, so it must never be moved.** Grid mode's `applyRowGrouping()` originally wrapped it into the first `.embla__group` along with the first item. Every later re-render then ran `placeholder.after(children)` *inside column 1*, dumping every child into that one column — which is why grid mode collapsed to a single visible item in the canvas while rendering correctly on the frontend, where no placeholder exists.

3. **`$scripts` runs on `$nextTick` after the move**, so element JS can rely on the children being present — but it also runs on renders where they are not yet, so init has to tolerate an empty container.

The frontend path emits none of this: `render_children()` returns the actual child HTML. Anything that only breaks in the canvas is worth checking against this mechanism first.

### Auto height

`.embla--auto-height` on the root drives three things that all have to line up, and dropping any one of them makes the feature look like it does nothing:

1. **`align-items: flex-start` on the container.** The container is a flex row, so it defaults to `align-items: stretch` — every slide is already sized to the tallest one. Measure them and you get the same number back regardless of which slide is showing, so the viewport height never changes. Scoped to auto height, because stretch is what keeps a row of cards even the rest of the time; and scoped to horizontal, because on a vertical slider `align-items` is the cross axis (width) and `flex-start` would collapse slides to their content width.

2. **Measure the slides in the current *snap*, not slide N.** `selectedScrollSnap()` returns a snap index. With "Items to scroll" > 1, or a snap list trimmed by `containScroll`, it does not line up with the slide list; with "Items to show" > 1 a single snap covers several slides, and the tallest of them has to win or the rest clip. `internalEngine().slideRegistry[snap]` is the snap → slide-index map (internal API, so the JS falls back to the naive lookup if its shape changes).

3. **A `ResizeObserver` on the slides.** Embla's own resize handling measures along the **scroll axis only** — `measureSize()` returns width for a horizontal slider. A slide growing *taller* after init (images without dimensions, late web fonts, JS-toggled content) therefore never triggers Embla's `reInit`, and the height stays at whatever it was at init. The observer is re-pointed on `reInit`, since Embla rebuilds its slide list there, and disconnected at the top of the next init alongside the arrow `AbortController`.

The inline height is cleared when auto height is off — the root survives a builder re-render, so a pinned height would otherwise stick after toggling the control.

The Layout → Height control still targets `.embla__slide` and will defeat auto height if set; the Options → Height control hides itself instead (`'required' => ['autoHeight', '=', '']`).

### Cursor states

Opt-in via the "Cursor states" control: `cursor: grab` on the viewport, `grabbing` while a drag is in progress. Two decisions worth keeping:

- The control carries **no `css` key**, even though it only produces CSS. A control with one is patched into the stylesheet without a re-render, so `$scripts` would never fire and the drag listeners would never bind. Routing it through the JSON config forces the re-render.
- The dragging state comes from Embla's `pointerDown` / `pointerUp` events, not `:active`. Embla's pointer-up is document-level, so the cursor stays correct when a drag continues outside the slide, and a click that never moves doesn't flicker.

Styles hang off `.embla--cursor` / `.embla--dragging` on the root and apply to `.embla__viewport`, so the arrows and dots keep their own cursor.

### Arrows

The `arrows` control is a mode:

- `none` — no arrows rendered.
- `builtin` — renders `.embla__button--prev` / `.embla__button--next` inside the element; the Arrows style controls apply.
- `custom` — binds any elements matching user-supplied CSS selectors, anywhere on the page.

Custom arrows live outside the element root, so they survive a builder re-render and would stack a listener on every init. One `AbortController` per init owns every arrow listener; the previous one is aborted before rebinding. Selectors are resolved in a try/catch — `querySelectorAll` throws on invalid syntax, and a half-typed selector in the builder must not take slider init down with it.

Built-in arrows get `.disabled`; custom ones get an `is-disabled` class instead, since they aren't necessarily `<button>`. No styling ships for that class.

# Bricks Builder Tweaks

Small Bricks tweaks that add no element: extra dynamic data tags, component property groups in Gutenberg, and extra allowed HTML tags. Custom elements are covered in [`bricks-elements.md`](bricks-elements.md).

All of these guard on `defined('BRICKS_VERSION')`.

## Registering dynamic tags (`Bricks_Tags`)

`inc/class-bricks-tags.php` registers string-valued tags. Bricks resolves a tag through three filters and lists it through a fourth; every tweak that adds tags needs all four, so they go through this helper instead of wiring the filters by hand.

| Filter | Purpose |
|---|---|
| `bricks/dynamic_tags_list` | Lists the tag in the builder's dynamic data picker |
| `bricks/dynamic_data/render_tag` | A field holding only the tag (also element conditions) |
| `bricks/dynamic_data/render_content` | Tags inside text content |
| `bricks/frontend/render_data` | Tags in frontend render data |

```php
\DDWPTweaks\Bricks_Tags::register('WP Toolkit', [
    'my_tag' => [
        'label'   => 'My tag (add :arg)',
        'resolve' => static fn(array $args, $post) => 'value',
        'image'   => false, // true: return [value] when Bricks asks in image context
    ],
]);
```

- `{my_tag:a:b}` calls `resolve(['a', 'b'], $post)`. `$post` is whatever Bricks passes (a `WP_Post` or an ID, the loop's post inside a query loop), so resolvers call `get_post($post)`.
- Resolvers return a string. Values replaced inside content are passed through `esc_html()`; a value returned for a whole-field tag is returned as is.
- Filters run at priority 20, after Bricks' own tags.
- A tag only matches `{name}` or `{name:args}`, where args contain no spaces or braces. Bricks filter syntax such as `@fallback` is not supported.

`wpsr-bricks.php` predates the helper and wires its filters directly, because `{wpsr_reviews}` returns an array (a loop source) rather than a string.

## Extra Dynamic Tags (`bricks-dynamic-tags.php`)

Each tag has its own checkbox. All are listed under the "WP Toolkit" group.

| Tag | Output |
|---|---|
| `{post_reading_time}` | `6 min` (unit text is a setting) |
| `{post_reading_time:number}` | `6` |
| `{post_count:book}` | Published posts of post type `book` (no argument: `post`) |
| `{post_count:category:news}` | The term's count (all post types) |
| `{post_count:book:genre:crime}` | Published `book` posts in term `crime` |
| `{post_count:book:genre:any}` / `:none` | Published `book` posts with at least one / no `genre` term |
| `{page_type}` | Post type on a single, `term` on a taxonomy archive, otherwise `other` |

**Reading time** counts words in `post_content` after stripping shortcodes and tags, at the configured words per minute (default 220), minimum 1. Bricks-built posts keep their content in post meta, so the tag only reflects block or classic content.

**Post count** results are cached per request. Arguments go through `sanitize_key()`.

**Page type** reads `get_queried_object()`, not the render context. `{post_type}` on a taxonomy archive returns the post type of the first post in the archive's query, so in a template shared by singles and term archives an element condition like `{post_type} == service` can match the archive too. `{page_type} == service` does not. It never returns an empty string, so `==` and `!=` compare safely.

## Component Property Groups in Gutenberg (`bricks-component-groups.php`)

Bricks 2.1+ exposes components as blocks, but renders every property in one flat "Properties" panel. This tweak keeps ungrouped properties in that panel and renders each property group as its own `PanelBody`.

The script (`assets/js/bricks-component-groups.js`) is added inline after the `bricks-gutenberg-components` handle on `enqueue_block_assets`, so the `editor.BlockEdit` filter exists before any block renders. It wraps `window.bricksCreatePropertyControl` to hide grouped properties from the flat panel, and reads components from `window.bricksGutenbergData.components`. Both are undocumented Bricks globals, so re-check after Bricks updates. If either is missing the script exits and Bricks' own panel is unchanged.

## Extra HTML Tags (`bricks-html-tags.php`)

Adds tags to `bricks/allowed_html_tags`, the list allowed in an element's "Custom" HTML tag option. Typical use: `canvas` for WebGL or chart elements.

Tags are parsed from a comma or space separated list, lowercased, and must match `^[a-z][a-z0-9-]*$`. Tags that run code, load other documents or change the page head (`script`, `style`, `iframe`, `object`, `embed`, `base`, `meta`, `link` and similar; see `BRICKS_HTML_TAGS_BLOCKED`) are dropped on save and again at runtime.

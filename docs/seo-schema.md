# Structured Data (Schema)

Builds JSON-LD per post type from post fields, ACF, taxonomies and Site Options, and outputs it in `wp_head` as one `@graph`. Replaces hand-written per-site schema PHP and the SEO plugin's limited schema builders.

**Status:** experimental. The tweak lives in the Experimental tab and only outputs anything while **Settings → Experiments** is enabled *and* the tweak's own toggle is on.

| File | Role |
|---|---|
| `inc/tweaks/seo-schema.php` | Tweak: settings, front-end output, editor field, preview AJAX |
| `inc/seo/class-schema-renderer.php` | Walks a template, resolves tokens, applies directives, prunes empties |
| `inc/seo/class-schema-sources.php` | Token sources (`post.*`, `acf:`, `tax:`...) and the per-post-type source catalogue for the editor |
| `inc/seo/class-schema-transforms.php` | Pipe transforms (`hours`, `address`, `duration`...) |
| `inc/seo/class-schema-types.php` | Known schema.org types, required/recommended properties, presets |
| `inc/seo/class-schema-conflicts.php` | Yoast / Rank Math / SEOPress handling |
| `assets/js/schema-editor.js`, `assets/css/schema-editor.css` | The editor (Visual and JSON modes) |

---

## Storage

One option, `ddwpt_seo_schema_templates`, a JSON object keyed by post type:

```json
{
  "recipe": { "enabled": true, "template": [ { "@type": "Recipe", "name": "{{post.title}}" } ] }
}
```

`template` is a list of root nodes (a single object is accepted too). Every root node joins the page `@graph`. Because it is a normal tweak option it travels with settings export/import.

Other settings: `organization_output` (`none` default, `template`, `all`), `organization_type`, `organization_name`, `organization_logo`, `conflict_mode` (all prefixed `ddwpt_seo_schema_`). The organization fields only show while `organization_output` is not `none` (`show_if`); with `none`, inline `ref:organization` nodes use any saved values, otherwise the site title and logo fallbacks.

---

## Template format

A template is the JSON-LD you want, with `{{tokens}}` where values come from content.

- A string that is **exactly one token** keeps the resolved type: a list stays a list, an object stays an object.
- A token **inside longer text** is interpolated as text; lists are joined with `, `. Text made only of tokens that are all empty resolves to empty.
- Anything that resolves to `null`, `''` or `[]` is **pruned**. An object left with only `@type`/`@id` is pruned too (or replaced by its `@else`).

### Sources

| Token | Resolves to |
|---|---|
| `post.title` `post.url` `post.content` `post.excerpt` `post.thumbnail` `post.date` `post.modified` `post.author` `post.id` `post.type` | Core data of the current post (or the related post inside `@from`) |
| `acf:group.sub_field` | `get_field()` on the first segment, then a dot path into the array |
| `meta:key` | Raw post meta |
| `tax:diet-type` / `tax:diet-type,meal-type` | Assigned `WP_Term` objects of one or more taxonomies |
| `option:so_phone_number` | ACF options page value, falling back to `get_option()` |
| `site.name` `site.url` `site.logo` | Organization settings, falling back to site title, home URL, Site Options brand logo, custom logo |
| `item` `item.field` `item.@index` | Current row inside `@each` (`@index` is 1-based, and empty for an `else` row) |
| `ref:organization` `ref:website` | `{"@id": "<home>/#organization"}` when this page prints the site nodes (or merges into Yoast's graph); otherwise the whole node inline (`@type`, `@id`, name, url, logo, sameAs) |

Dot paths also read `name`/`slug`/`url`/`title` off term and post objects, so `item.url` works when looping terms.

### Transforms

Chained with pipes, left to right: `{{acf:prep_time|minutes|duration}}`. The argument is everything after the first `:` up to the next `|`, untrimmed (so `join:, ` keeps its space).

| Transform | Does |
|---|---|
| `clean` | Strip tags, collapse whitespace |
| `summary:220` | Opening sentences up to N characters |
| `minutes` | `"1 hour 10 minutes"` / `"1h 10m"` to `70`; unparseable (`"Overnight"`) to null |
| `duration` | Minutes to ISO 8601 (`PT1H10M`) |
| `sum:<source>` | Adds another source's minutes; null if either is missing |
| `hours` | Free text to `OpeningHoursSpecification[]`: lines or comma lists of `Monday: 7:30am–6:00pm`, `Monday to Thursday, 8:30am–5:30pm`; fortnightly and unparseable segments are skipped |
| `time` | `7:30am` to `07:30` |
| `address:AU` | One-line address (or ACF Google Map) to `PostalAddress`. Patterns for AU, US, CA, NZ, GB; anything unmatched becomes `streetAddress` only |
| `geo:<lng source>` | Latitude + longitude (or an ACF Google Map) to `GeoCoordinates`; dropped if either is not numeric |
| `phone:+61` | International format; ACF link fields use their title; a leading trunk `0` is dropped |
| `nutrition:label,value` | Repeater rows (`Calories`, `Fat`, `Protein`, `Fibre`...) to `NutritionInformation`; bare calorie numbers get ` calories` |
| `map:<table>` | Look up values in an `@maps` table (case-insensitive), dropping misses; on an object, maps its keys |
| `pairs:key,value` | Repeater rows to `{key: value}` |
| `term:<taxonomy>` | Term IDs or slugs to `WP_Term`s |
| `name` `slug` `url` `title` | Pluck off terms, posts, ACF link/image arrays, or lists of them |
| `post:<Type>` | Post IDs to `{@type, name, url}` (use `@from` when you need more properties) |
| `image` | Attachment ID, ACF image array or URL to a URL |
| `join:<sep>` `first` `unique` | List handling (`join` defaults to `, `) |
| `int` `float` `date` | Casts; `date` handles ACF's `Ymd` storage |
| `default:<value or source>` | Fallback when empty |
| `prefix:<text>` `suffix:<text>` | Only applied when the value is not empty |
| `plural:<word>` | `4` to `4 serves`, `1` to `1 serve` |

Arguments that start with a source prefix (`acf:`, `ref:`, `item`...) are resolved; anything else is literal.

Add site-specific transforms from a theme:

```php
add_filter('ddwpt_schema_transforms', function ($transforms) {
    $transforms['abn'] = fn($value, $arg, $renderer) => preg_replace('/\D/', '', (string) $value);
    return $transforms;
});
```

### Directives

Keys the renderer consumes and never outputs:

| Directive | On | Meaning |
|---|---|---|
| `@each` | object | Repeat the object per row of a source: `"@each": "acf:recipe.method"`. Long form `{"rows": "...", "if": "...", "else": {"key": "source"}}`: `if` gates the rows, `else` builds one synthetic row when there are none |
| `@value` | object in a loop | Yield this value per row instead of an object (plain string lists like `recipeIngredient`) |
| `@if` / `@unless` | object | Include only when a source is truthy / falsy |
| `@from` | object | Switch `post.*` to a related post (relationship / post object field); several posts give a list |
| `@merge` | object | Spread a resolved object (e.g. from `pairs`) into this node |
| `@else` | object | Used when the object resolves empty, or `@from` finds no post, or `@each` has no rows |
| `@maps` | root node | Named lookup tables for `map:` |

### Example: recipe

```json
{
  "@maps": { "diets": { "Vegan": "https://schema.org/VeganDiet", "Gluten-Free": "https://schema.org/GlutenFreeDiet" } },
  "@type": "Recipe",
  "@id": "{{post.url}}#recipe",
  "name": "{{post.title}}",
  "description": "{{post.content|summary:220}}",
  "image": "{{post.thumbnail}}",
  "author": {
    "@from": "acf:linked_author",
    "@type": "Person",
    "name": "{{post.title}}",
    "url": "{{post.url}}",
    "worksFor": "{{ref:organization}}",
    "@else": "{{ref:organization}}"
  },
  "prepTime": "{{acf:recipe_general.prep_time|minutes|duration}}",
  "totalTime": "{{acf:recipe_general.prep_time|minutes|sum:acf:recipe_general.cook_time|duration}}",
  "recipeYield": "{{acf:recipe_general.serves|plural:serve}}",
  "suitableForDiet": "{{tax:diet-type|name|map:diets|unique}}",
  "recipeIngredient": { "@each": "acf:recipe_general.ingredients", "@value": "{{item.quantity}} {{item.ingredient}}" },
  "recipeInstructions": { "@each": "acf:recipe_general.method", "@type": "HowToStep", "text": "{{item.step|clean}}" },
  "nutrition": "{{acf:recipe_general.nutritional_information|nutrition:label,statistic}}"
}
```

### Example: one clinic node per address

```json
{
  "@each": {
    "rows": "acf:location_visit.addresses",
    "if": "acf:location_visit.has_multiple_addresses",
    "else": { "address": "acf:location_general.address", "hours": "acf:location_general.hours" }
  },
  "@type": "MedicalClinic",
  "@id": "{{post.url}}#clinic{{item.@index|prefix:-}}",
  "name": "{{site.name}} {{post.title}}{{item.label|prefix: (|suffix:)}}",
  "address": "{{item.address|address:AU}}",
  "openingHoursSpecification": "{{item.hours|hours}}",
  "parentOrganization": "{{ref:organization}}"
}
```

These two templates reproduce the OnePointHealth theme's hand-written schema, with two deliberate differences: phone numbers drop the trunk `0` after `+61`, and missing coordinates drop `geo` instead of emitting `0, 0`.

---

## Editor

One panel per public post type, each with an **Output** toggle and two modes over the same template:

- **Visual**: a row per property. Rows read as a one-line summary (source label and transform steps) and open for editing on click, one at a time; Esc or the tick closes them. Nested objects and lookup tables collapse to a summary line too (type, how the object is produced, its property names). Each value is a **Field** (one box: source picker, then transforms as inline `›` steps, added from a searchable grouped menu), **Text** (with inline tokens), **Object** (nested rows) or raw **JSON**. Directives are not properties: the ones that decide how a node is produced ("Repeat for each", "Item value", "Use related post", "Only if", "Unless") sit above the properties, and the ones that act on the result ("Fallback when empty", "Merge object", lookup tables) sit below, each added from its own button there...). Property names autocomplete from the node's `@type`; required properties are marked and missing ones offered as one-click pills. Nodes and properties reorder by dragging their grip (or focusing it and pressing the up and down arrow keys), which changes key order in the stored JSON. Node cards and nested objects collapse; options (directives) sit in a tinted block above the properties.
- **JSON**: the whole template in CodeMirror. Invalid JSON blocks switching back to Visual, so nothing is lost.

**Presets** (`Schema_Types::presets()`, picked from a modal with a tab per group of type cards) append a node with the type's required and recommended properties, obvious ones pre-mapped (`name` → `post.title`, `image` → `post.thumbnail`) and the rest blank. Filter with `ddwpt_schema_presets`; add types with `ddwpt_schema_types`.

**Preview** renders the unsaved template against a chosen post over AJAX (`ddwpt_schema_preview`), listing empty sources and missing required properties, with a link to Google's Rich Results Test.

The editor's source picker comes from `Schema_Sources::catalogue()`: core post fields, ACF field groups assigned to the post type (groups flattened to dot paths, repeaters flagged as lists with their row fields offered as `item.*` inside a loop), public taxonomies, Site Options fields, and site references.

---

## Output and conflicts

On a singular view whose post type has an enabled template, the page gets one script tag at `wp_head` priority 20 containing the template's nodes.

Site `Organization` and `WebSite` nodes are **opt-in** (`organization_output`): by default they are left to the SEO plugin, and `{{ref:organization}}` embeds the organization inline so template nodes stay self-contained. `template` adds both nodes to pages with a template (refs become `@id` references); `all` also prints them alone on every other page.

JSON is encoded with `JSON_HEX_TAG`, so template text can't close the script tag.

| `conflict_mode` | Behaviour |
|---|---|
| `replace` (default) | On pages we handle: Yoast `wpseo_json_ld_output` → false; Rank Math `rank_math/json_ld` → `[]`; SEOPress: `wp_head` callbacks whose name contains `seopress` plus `schema`/`jsonld`/`rich_snippet`/`knowledge_graph` (not breadcrumbs) are unhooked. Adjust with the `ddwpt_schema_unhook_callback` filter |
| `merge` | Yoast only: our nodes are appended through `wpseo_schema_graph`. Our `#organization` id matches Yoast's, so references line up. Other plugins fall back to `replace` |
| `alongside` | Both outputs; duplicates Organization only if `organization_output` is opted in |

The SEOPress unhooking matches callbacks by name because SEOPress has no single schema switch; verify on the installed SEOPress version that its schema is gone and breadcrumbs remain.

---

## Known gaps

- No output caching; templates render per request (cheap, but ACF-heavy templates add `get_field` calls).
- Archive, term and front-page schema are not handled; only singular views.
- `hours` understands English day names in full, not abbreviations (`Mon`).

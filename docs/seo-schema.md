# Structured Data (Schema)

Builds JSON-LD per post type from post fields, ACF, taxonomies and Site Options, and outputs it in `wp_head` as one `@graph`. Replaces hand-written per-site schema PHP and the SEO plugin's limited schema builders.

**Status:** experimental. The tweak lives in the Experimental tab and only outputs anything while **Settings → Experiments** is enabled *and* the tweak's own toggle is on.

| File | Role |
|---|---|
| `inc/tweaks/seo-schema.php` | Tweak: settings, front-end output, editor field, preview AJAX |
| `inc/seo/class-schema-renderer.php` | Walks a template, resolves tokens, applies directives, prunes empties |
| `inc/seo/class-schema-syntax.php` | Token syntax: finds `{tag:filter|arg @fallback:'x'}` tokens (and the earlier `{{...}}`) and parses them |
| `inc/seo/class-schema-sources.php` | Tag sources (`post_*`, `acf_*`, `post_terms_*`...) and the per-post-type source catalogue for the editor |
| `inc/seo/class-schema-transforms.php` | Pipe transforms (`hours`, `address`, `duration`...) |
| `inc/seo/class-schema-types.php` | Known schema.org types, required/recommended properties, presets |
| `inc/seo/class-schema-conflicts.php` | Yoast / Rank Math / SEOPress handling |
| `assets/js/schema-editor.js`, `assets/css/schema-editor.css` | The editor (Visual and JSON modes) |

---

## Storage

One option, `ddwpt_seo_schema_templates`, a JSON object keyed by post type:

```json
{
  "recipe": { "enabled": true, "template": [ { "@type": "Recipe", "name": "{post_title}" } ] }
}
```

`enabled` is the switch in each post type's panel header (a `Toggle` in the editor; its clicks never open or close the panel). `template` is a list of root nodes (a single object is accepted too). Every root node joins the page `@graph`. Because it is a normal tweak option it travels with settings export/import.

Other settings: `organization_output` (`none` default, `template`, `all`), `organization_type`, `organization_name`, `organization_logo`, `conflict_mode` (all prefixed `ddwpt_seo_schema_`). The organization fields only show while `organization_output` is not `none` (`show_if`); with `none`, inline `ref_organization` nodes use any saved values, otherwise the site title and logo fallbacks.

---

## Template format

A template is the JSON-LD you want, with tokens where values come from content. Tokens follow [Bricks dynamic data](https://academy.bricksbuilder.io/builder/dynamic-content/dynamic-data/) (`inc/seo/class-schema-syntax.php`):

| Form | Example |
|---|---|
| `{tag}` | `{post_title}` |
| `{tag:filter:filter}` | `{acf_recipe_general_prep_time:minutes:duration}` |
| `{tag:filter\|arg}` | `{acf_location_general_phone:phone\|+61}`, `{post_terms_diet-type:name:map\|diets}` |
| `{tag @fallback:'text'}` | `{acf_location_general_phone:title:phone\|+61 @fallback:'+61 1300 123 674'}` |

- Filters run left to right. An argument follows `|`, as in Bricks' `array_value|key` (Bricks has no general filter-argument syntax). It is single-quoted when it holds `:`, `'`, `{`, `}`, `@`, `|` or edge spaces; `\'` escapes a quote: `{item_label:prefix|' (':suffix|')'}`, `{post_terms_meal-type:name:join|', '}`.
- `@fallback` is used when the filtered value is empty and may contain tags (`@fallback:'{site_title}'`). A `fallback` filter mid-chain (`:fallback|x`) applies at that point instead; the editor writes a trailing one as `@fallback`. An `Empty:` preview warning is skipped for tokens with `@fallback`.
- `{}` is an empty field (no source picked yet); `{:address|AU}` is a field with filters but no source (presets use these).
- A tag starts with a letter, `_` or `:` straight after `{`, so JSON-ish text like `{ "a": 1 }` is left alone.
- `@each`, `@from`, `@if` and `@unless` take the same expression without braces: `"@each": "acf_location_general_services_available:term|service-type"`.
- A string that is **exactly one token** keeps the resolved type: a list stays a list, an object stays an object.
- A token **inside longer text** is interpolated as text; lists are joined with `, `. Text made only of tokens that are all empty resolves to empty.
- Anything that resolves to `null`, `''` or `[]` is **pruned**. An object left with only `@type`/`@id` is pruned too (or replaced by its `@else`).

**Earlier syntax.** Templates saved before the switch used `{{source|transform:arg}}` with dotted sources (`post.title`, `acf:group.field`, `tax:a,b`, `item.@index`, `ref:organization`, `default:x`). The renderer still reads them, and the editor rewrites them on load (`migrateTemplate()` in `schema-editor.js`; `default` becomes `fallback`). `tax:a,b` (several taxonomies in one token) has no tag form and is left as written; use two tokens in text instead.

### Sources (tags)

| Tag | Resolves to |
|---|---|
| `post_title` `post_url` `post_content` `post_excerpt` `featured_image` `post_date` `post_modified` `author_name` `post_id` `post_type` | Core data of the current post (or the related post inside `@from`) |
| `acf_<field>` | ACF value. Group and repeater sub fields join with `_` as in Bricks: `acf_location_general_phone` is `location_general` → `phone`. Matched against the real keys (`get_fields()`), longest first, so field names may contain `_` |
| `acf_option_<field>` | ACF options page value, falling back to `get_option()` |
| `cf_<key>` | Raw post meta (Bricks' custom field prefix) |
| `post_terms_<taxonomy>` | Assigned `WP_Term` objects |
| `site_title` `site_url` `site_logo` | Organization settings, falling back to site title, home URL, Site Options brand logo, custom logo |
| `item` `item_<field>` `query_loop_index` | Current row inside `@each` (`query_loop_index` is 1-based, and empty for an `else` row) |
| `ref_organization` `ref_website` | `{"@id": "<home>/#organization"}` when this page prints the site nodes (or merges into Yoast's graph); otherwise the whole node inline (`@type`, `@id`, name, url, logo, sameAs) |

Paths also read `name`/`slug`/`url`/`title` off term and post objects, so `item_url` works when looping terms.

Tags we don't have yet (no Bricks equivalent needed for schema so far): `term_*`, `author_*` beyond the name, `wp_user_*`, `{echo:...}`, `{format_date ...}`.

### Filters

| Filter | Does |
|---|---|
| `clean` | Strip tags, collapse whitespace |
| `summary\|220` | Opening sentences up to N characters |
| `minutes` | `"1 hour 10 minutes"` / `"1h 10m"` to `70`; unparseable (`"Overnight"`) to null |
| `duration` | Minutes to ISO 8601 (`PT1H10M`) |
| `sum\|<tag>` | Adds another source's minutes; null if either is missing |
| `hours` | Free text to `OpeningHoursSpecification[]`: lines or comma lists of `Monday: 7:30am–6:00pm`, `Monday to Thursday, 8:30am–5:30pm`; fortnightly and unparseable segments are skipped |
| `time` | `7:30am` to `07:30` |
| `address\|AU` | One-line address (or ACF Google Map) to `PostalAddress`. Patterns for AU, US, CA, NZ, GB; anything unmatched becomes `streetAddress` only |
| `geo\|<lng tag>` | Latitude + longitude (or an ACF Google Map) to `GeoCoordinates`; dropped if either is not numeric |
| `phone\|+61` | International format; ACF link fields use their title; a leading trunk `0` is dropped |
| `nutrition\|'label,value'` | Repeater rows (`Calories`, `Fat`, `Protein`, `Fibre`...) to `NutritionInformation`; bare calorie numbers get ` calories` |
| `map\|<table>` | Look up values in an `@maps` table (case-insensitive), dropping misses; on an object, maps its keys |
| `pairs\|'key,value'` | Repeater rows to `{key: value}` |
| `term\|<taxonomy>` | Term IDs or slugs to `WP_Term`s |
| `name` `slug` `url` `title` | Pluck off terms, posts, ACF link/image arrays, or lists of them |
| `post\|<Type>` | Post IDs to `{@type, name, url}` (use `@from` when you need more properties) |
| `image` | Attachment ID, ACF image array or URL to a URL |
| `join\|<sep>` `first` `unique` | List handling (`join` defaults to `, `) |
| `int` `float` `date` | Casts; `date` handles ACF's `Ymd` storage |
| `fallback\|<text or tag>` | Fallback when empty (`default` is the earlier name) |
| `prefix\|<text>` `suffix\|<text>` | Only applied when the value is not empty |
| `plural\|<word>` | `4` to `4 serves`, `1` to `1 serve` |

Arguments that name a source (`acf_`, `item_`, `ref_`... or the earlier `acf:` forms) or hold a `{tag}` are resolved; anything else is literal. Bricks' own display filters (`:link`, `:plain`, `:10` word limits, `array_value|key`) aren't implemented: schema values are data, not markup.

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
| `@each` | object | Repeat the object per row of a source: `"@each": "acf_recipe_method"`. Long form `{"rows": "...", "if": "...", "else": {"key": "source"}}`: `if` gates the rows, `else` builds one synthetic row when there are none |
| `@value` | object in a loop | Yield this value per row instead of an object (plain string lists like `recipeIngredient`) |
| `@if` / `@unless` | object | Include only when a source is truthy / falsy |
| `@from` | object | Switch `post_*` tags to a related post (relationship / post object field); several posts give a list |
| `@merge` | object | Spread a resolved object (e.g. from `pairs`) into this node |
| `@else` | object | Used when the object resolves empty, or `@from` finds no post, or `@each` has no rows |
| `@maps` | root node | Named lookup tables for `map|<table>` |

### Example: recipe

```json
{
  "@maps": { "diets": { "Vegan": "https://schema.org/VeganDiet", "Gluten-Free": "https://schema.org/GlutenFreeDiet" } },
  "@type": "Recipe",
  "@id": "{post_url}#recipe",
  "name": "{post_title}",
  "description": "{post_content:summary|220}",
  "image": "{featured_image}",
  "author": {
    "@from": "acf_linked_author",
    "@type": "Person",
    "name": "{post_title}",
    "url": "{post_url}",
    "worksFor": "{ref_organization}",
    "@else": "{ref_organization}"
  },
  "prepTime": "{acf_recipe_general_prep_time:minutes:duration}",
  "totalTime": "{acf_recipe_general_prep_time:minutes:sum|acf_recipe_general_cook_time:duration}",
  "recipeYield": "{acf_recipe_general_serves:plural|serve}",
  "keywords": "{post_terms_diet-type:name:join}, {post_terms_meal-type:name:join}",
  "suitableForDiet": "{post_terms_diet-type:name:map|diets:unique}",
  "recipeIngredient": { "@each": "acf_recipe_general_ingredients", "@value": "{item_quantity} {item_ingredient}" },
  "recipeInstructions": { "@each": "acf_recipe_general_method", "@type": "HowToStep", "text": "{item_step:clean}" },
  "nutrition": "{acf_recipe_general_nutritional_information:nutrition|'label,statistic'}"
}
```

### Example: one clinic node per address

```json
{
  "@each": {
    "rows": "acf_location_visit_addresses",
    "if": "acf_location_visit_has_multiple_addresses",
    "else": { "address": "acf_location_general_address", "hours": "acf_location_general_hours" }
  },
  "@type": "MedicalClinic",
  "@id": "{post_url}#clinic{query_loop_index:prefix|-}",
  "name": "{site_title} {post_title}{item_label:prefix|' (':suffix|')'}",
  "telephone": "{acf_location_general_phone:title:phone|+61 @fallback:'+61 1300 123 674'}",
  "address": "{item_address:address|AU}",
  "openingHoursSpecification": "{item_hours:hours}",
  "parentOrganization": "{ref_organization}"
}
```

These two templates reproduce the OnePointHealth theme's hand-written schema, with two deliberate differences: phone numbers drop the trunk `0` after `+61`, and missing coordinates drop `geo` instead of emitting `0, 0`.

---

## Editor

One panel per public post type, each with an **Output** toggle and two modes over the same template:

- **Visual**: a row per property. Rows read as a one-line summary (source label and transform steps) and open for editing on click, one at a time; Esc or the tick closes them. Nested objects and lookup tables collapse to a summary line too (type, how the object is produced, its property names). Each value is **Text**, **Object** (nested rows) or raw **JSON**. A text value is an input with an insert-field menu that shows each token as its bare tag (`{post_title}`), and under it one box per token holding only the filters and fallback applied to it (inline `›` steps, added from a searchable grouped menu). Typing in the input keeps each tag's filters (matched by tag, then by position), and editing a box rewrites that token in place. A text value that is a lone `{tag}` keeps the source's type when rendered, so there is no separate field mode. Directives show as icons (repeat, list, link, eye, eye-off, fallback arrow, merge, table) with their name and purpose in the tooltip, in rows and in object summaries. They are not properties: the ones that decide how a node is produced ("Repeat for each", "Item value", "Use related post", "Only if", "Unless") sit above the properties, and the ones that act on the result ("Fallback when empty", "Merge object", lookup tables) sit below, each added from its own button there...). Property names autocomplete from the node's `@type`; required properties are marked and missing ones offered as one-click pills. Nodes and properties reorder by dragging their grip (or focusing it and pressing the up and down arrow keys), which changes key order in the stored JSON. Node cards and nested objects collapse; options (directives) sit in a tinted block above the properties.
- **JSON**: the whole template in CodeMirror. Invalid JSON blocks switching back to Visual, so nothing is lost.

**Presets** (`Schema_Types::presets()`, picked from a modal with a tab per group of type cards) append a node with the type's required and recommended properties, obvious ones pre-mapped (`name` → `{post_title}`, `image` → `{featured_image}`) and the rest blank. Filter with `ddwpt_schema_presets`; add types with `ddwpt_schema_types`.

**Preview** is the third editor mode, beside Visual and JSON (the preset button sits at the right of the toolbar and hides in Preview). It loads the post list on entry, renders the first post straight away and re-renders when another post is picked; Refresh re-runs it. It renders the unsaved template against a chosen post over AJAX (`ddwpt_schema_preview`), listing empty sources and missing required properties, with a link to Google's Rich Results Test.

The editor's source picker comes from `Schema_Sources::catalogue()`: core post fields, ACF field groups assigned to the post type (groups flattened to underscored `acf_group_field` tags, repeaters flagged as lists with their row fields offered as `item_*` inside a loop), public taxonomies, Site Options fields, and site references.

---

## Output and conflicts

On a singular view whose post type has an enabled template, the page gets one script tag at `wp_head` priority 20 containing the template's nodes.

Site `Organization` and `WebSite` nodes are **opt-in** (`organization_output`): by default they are left to the SEO plugin, and `{ref_organization}` embeds the organization inline so template nodes stay self-contained. `template` adds both nodes to pages with a template (refs become `@id` references); `all` also prints them alone on every other page.

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

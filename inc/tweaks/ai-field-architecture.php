<?php

namespace DDWPTweaks\Tweaks;

$default_field_architecture_guide = <<<'INSTRUCTIONS'
# ACF Field Architecture Standards

A content-agnostic reference for structuring ACF field groups on any CPT, in any project. These are the rules that stay constant regardless of what the page is about — services, products, team bios, case studies, whatever the next CPT turns out to be.

Use this once a collection's post type already exists (see "Adding New Collections" in the site instructions) and you're designing the field groups for its visually laid-out sections (hero, pricing, feature grids, etc.). It's a separate concern from the flat, per-entry structured-data fields a collection may also carry (a rating, a phone number) — those follow the simpler naming convention in the site instructions, not the Tab → Group → Fields pattern below.

---

## 1. Core structural unit: Tab → Group → Fields

Every visual section of a page gets exactly one **Tab**, and every Tab is immediately followed by exactly one **Group** field that contains everything belonging to that section.

- **Tab** = the human-facing label. Names it for what a content editor sees ("Hero", "Pricing", "Testimonials").
- **Group** = the technical container. Its field name is the actual key used in templates — this is the thing that gets standardized, not the tab label.
- Tabs (and their groups) are ordered top-to-bottom to match the rendered order of the page. Field-group order is documentation of page order — don't let them drift apart.

This pairing is the atomic unit of the system: **one section = one tab = one group.** When a new section is needed, this is the first thing you create, before any subfields.

---

## 2. Naming convention: standardizing keys

**Group names:** `{content_type_prefix}_{section_name}`, snake_case.
Example shape: `product_hero`, `product_specs`, `caseStudy_hero` → normalize to `case_study_hero`.
The prefix scopes every group to its CPT, so field keys never collide across post types and template code can predictably reference `get_field('xxx_hero')` for any CPT following the pattern.

**Subfield names:** generic and reusable, *not* prefixed with the section name.
Use `heading`, `lede`, `body`, `image`, `icon`, `label`, `cta_label` — never `hero_heading` or `pricing_body`. The group already provides the namespace; repeating the section name in the subfield is redundant and breaks reusability.

**Why this matters:** if every group's "intro" pattern is `heading` + `lede` + `image`, you can build one Bricks/template partial for "heading, lede, image block" and point it at any group in any CPT. Section-specific subfield names would force a bespoke partial per section. Generic names are what make components reusable across projects, not just within one.

---

## 3. Field-type decision rule (not content-specific)

Every field's type should answer one question: **is this a label/control, or is this editorial prose?**

| If the field is... | Use | Because |
|---|---|---|
| A heading, button label, tagline, short single-line value, a list-item label with no need for emphasis | `text` | It renders as a heading, button, or flat list item — markdown/bold would leak into UI chrome. |
| Flowing copy where emphasis, links, or paragraph breaks matter | `wysiwyg` | Editors need to bold a thesis sentence, add a link, or break into multiple paragraphs. |
| A reusable, independently-managed entity (testimonial, team member, related item) | `relationship` (or `post_object`) | See §6 — don't duplicate content that already exists as its own object. |
| A binary behavior switch (show/hide, mode A vs mode B) | `true_false` | See §5. |
| Visual asset tied to this specific entry | `image` | Keep optional by default — copy/layout should degrade gracefully without it. |

This rule generalizes regardless of what the section is about: decide per-field whether an editor should be able to format it, not per-section.

---

## 4. Repeater design rules

**When to reach for a repeater at all:** the content is a variable-length list of same-shaped items (benefits, steps, cards, FAQ items, spec rows — content-type irrelevant, shape is what matters).

**Layout choice — `table` vs `block`:**
- **`table`**: items are flat, text-only, no media, scannable at a glance. Good for short label/value pairs, plain list items, inclusion/exclusion-style lists.
- **`block`**: items carry an image/icon, or have copy substantial enough to need its own visual separation (a heading + a paragraph, not just a label).

Decide this per-repeater based on what the item actually contains, not by copying a previous project's choice. The heuristic transfers; the specific repeaters don't.

**Subfields inside a repeater follow the same generic-naming rule as §2** — `heading` / `body` / `label` / `image`, not `benefit_heading` or `step_body`.

**No hardcoded item counts.** Repeaters are unbounded (`min`/`max` = 0) by default unless there's a structural reason to cap them (e.g. a layout that physically only fits 3 cards). Content volume is a copy decision made per-project, not a schema constraint.

---

## 5. Conditional logic pattern: mutually exclusive field sets

When a section can work in two (or more) genuinely different modes — not just "empty vs filled," but structurally different content — use a `true_false` toggle to switch which field set is active, rather than:
- cramming both modes into one field, or
- duplicating the whole group per mode.

Example shape (content-agnostic): a "pricing" or "output" group might have a toggle like `is_variable` that swaps a fixed `value` field for a `variables` repeater. The toggle lives at the top of the group; the fields it controls use conditional logic keyed to it.

**Rule:** if a future section needs this pattern, name the toggle for the *mode it flips*, not the specific content ("`is_variable`" / "`is_scoped`", not "`show_special_pricing`"), so the pattern is recognizable independent of what content type it's applied to.

**Dependent-field pattern:** if a subfield in one group needs to reuse a value/config that lives in another group (e.g. a CTA in Section 3 reusing a link defined in the Hero group), don't duplicate the field — give the dependent section only the toggle + its own label, and document in the field's instructions that it inherits config from the other group. Keeps a single source of truth for shared config.

---

## 6. Relationship vs. inline content

Before adding a text field for something, ask: **is this content unique to this entry, or does it exist/get reused elsewhere?**

- Unique to this entry (this product's spec list, this page's hero copy) → inline field in the group.
- Reusable across many entries (a testimonial, a team member, a partner logo, a related item) → model it as its own CPT/taxonomy and pull it in via `relationship` or `post_object`. Never duplicate that content as typed text inside this group.

This keeps a single edit location for anything that appears on more than one page, and is the rule to apply regardless of what the reusable entity happens to be in a given project.

---

## 7. The "card/preview" pattern

Any CPT whose entries might be **referenced or listed elsewhere** (a grid, a related-items block, a homepage feature list) should carry its own lightweight group — separate from the detailed page-content groups — containing just what's needed to render a preview: a short label, a one-line blurb, a CTA label.

Reasoning: pulling a teaser from deep inside a detailed content group (e.g. reaching into a repeater to grab the first item's heading) is fragile and breaks when the detailed content restructures. A dedicated, flat "how do I present as a preview" group is stable independent of how the full page is organized.

Name this group consistently across projects (e.g. `{prefix}_card` or `{prefix}_preview`) so any listing/related-items template can rely on the same field path across every CPT that has one.

---

## 8. What does *not* belong in this field group

Before adding a field, check whether the content is actually:
- **Computed/queried automatically** (e.g. "related items" pulled by matching taxonomy/post type) — these don't need an authored field at all; they're template logic.
- **Owned by a plugin** (SEO meta, schema markup config) — don't recreate plugin-owned fields inside ACF; reference the plugin's data instead.
- **Global/shared across the whole site**, not per-entry (a sitewide FAQ set, footer trust badges) — these belong in an Options Page or a separate shared field group, not duplicated per CPT entry.
- **Build/placement guidance for the developer**, not editable content (e.g. "this asset displays in the footer zone") — this is a note for whoever builds the template, not a field.

Getting this wrong shows up as either (a) fields that are empty/unused because the content actually comes from elsewhere, or (b) editors given false confidence that something is per-page editable when it isn't. When scoping a new field group, explicitly separate "unique per-entry editorial content" (→ gets a field) from these four categories (→ doesn't).

---

## 9. Checklist for a new CPT field group

1. List the page's visual sections top to bottom.
2. For each section: one Tab, one Group, prefixed/snake_case name.
3. For each field in the group: text vs wysiwyg decided by §3, not habit.
4. For any repeatable list: table vs block decided by §4's content-shape test, no hardcoded max unless layout demands it.
5. For any either/or content mode: a named toggle per §5, not a duplicated group.
6. For any reusable entity: relationship field per §6, not inline duplication.
7. If this CPT's entries can appear as a card/teaser elsewhere: add the dedicated preview group per §7.
8. Before finalizing: run every planned field past §8 to confirm it's genuinely unique per-entry content and not something computed, plugin-owned, global, or dev-only.
INSTRUCTIONS;

// Runs at plugins_loaded when the file is required — before wp_abilities_api_init fires.
if (get_option('ddwpt_ai_field_architecture_enabled')) {
    add_action('wp_abilities_api_init', static function () use ($default_field_architecture_guide) {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $guide = get_option('ddwpt_ai_field_architecture_guide', $default_field_architecture_guide);
        if (!$guide) {
            return;
        }

        wp_register_ability(
            'wp-toolkit/get-acf-architecture-guide',
            [
                'label'               => 'Get ACF Field Architecture Guide',
                'description'         => 'Returns this site\'s conventions for structuring ACF field groups — Tab → Group → Fields, naming, field-type choice, repeaters, conditional logic, and relationships. Call this before designing or building new field groups for a CPT, after the post type itself already exists.',
                'category'            => 'site',
                'input_schema'        => [
                    'type'                 => ['object', 'null'],
                    'properties'           => [],
                    'additionalProperties' => false,
                ],
                'output_schema'       => [
                    'type'        => 'string',
                    'description' => 'The field architecture guide in Markdown format.',
                ],
                'execute_callback'    => static function () use ($guide) {
                    return $guide;
                },
                'permission_callback' => static function () {
                    return current_user_can('read');
                },
                'meta'                => [
                    'annotations'  => [
                        'readonly'   => true,
                        'idempotent' => true,
                    ],
                    'show_in_rest' => true,
                    'mcp'          => [
                        'public' => true,
                    ],
                ],
            ]
        );
    });
}

return [
    'id'    => 'ddwpt_ai_field_architecture',
    'label' => 'ACF Field Architecture Guide',
    'tab'   => 'ai',
    'group' => 'instructions',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Registers a <code>wp-toolkit/get-acf-architecture-guide</code> ability via the WordPress Abilities API, giving AI agents this site\'s conventions for structuring new ACF field groups.',
        ],
        [
            'id'          => 'guide',
            'type'        => 'textarea',
            'label'       => 'Field Architecture Guide',
            'description' => 'Plain text or Markdown. Returned by the <code>wp-toolkit/get-acf-architecture-guide</code> ability so AI agents structure new field groups consistently with the rest of the site.',
            'default'     => $default_field_architecture_guide,
            'resettable'  => true,
        ],
    ],

    'callback' => static function ($settings) {
        // Ability registration is handled at file-load time above.
    },
];

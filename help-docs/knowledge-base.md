# Knowledge Base

The knowledge base renders Markdown articles stored in `inc/knowledge/` as a WordPress admin page. Articles are discovered via `manifest.php`; no code changes are needed to add new ones.

## Display modes

Controlled by the Knowledge Base tweak setting (`ddwpt_knowledge_base_mode`):

- **sidebar** (default) — top-level admin menu entry ("Knowledge Base"), `manage_options` capability.
- **dashboard** — replaces the default WP welcome panel on `index.php` with a greeting banner; the article list appears as a submenu under Dashboard.

The mode is passed to `Knowledge_Base::__construct()` in the tweak callback.

## Adding articles

1. Create `inc/knowledge/my-article.md` with Markdown content.
2. Add the slug (without `.md`) and a dashicon class to the appropriate group in `inc/knowledge/manifest.php`:

```php
'My Group' => [
    'my-article' => 'dashicons-media-text',
],
```

Articles are rendered client-side via the [Showdown](https://showdownjs.com/) library (loaded from unpkg). Supported: tables, strikethrough, fenced code blocks.

Numeric prefixes (`00-`, `01-`, …) are stripped from display titles. `00-my-article.md` renders as "My Article".

## manifest.php structure

```php
return [
    'Group Name' => [
        'slug' => 'dashicons-icon-name',
        // ...
    ],
    // ...
];
```

Groups appear in definition order. Articles not listed in the manifest are excluded from the hub, even if the file exists. If `manifest.php` is missing entirely, all `*.md` files in `inc/knowledge/` are displayed under a single "Articles" group.

## Excerpt generation

The hub card excerpt is the first non-heading, non-list, non-code-block line of the article with more than 20 characters, stripped of Markdown syntax, truncated to 130 characters. Keep the first paragraph of each article descriptive.

## Article routing

Articles are accessed via `?page=ddwpt-knowledge&doc=<slug>`. The `doc` query param is validated against the known slug list before any file is read — unknown slugs fall back to the hub.

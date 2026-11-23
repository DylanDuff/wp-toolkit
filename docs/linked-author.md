# Team Member Authors

`inc/tweaks/linked-author.php` (ACF tab, Presets). A post can name a published `team-member` (from the Team Members preset) as its author. On the front end WordPress author output resolves to that team member; without one, core behaviour is untouched.

## Settings

| Setting | Notes |
|---|---|
| Post types | Post types that support `author`. Default `post`. |
| Add the Author field | Registers an ACF `post_object` field `linked_author` (sidebar, published team members only) on those post types. Turn it off when the site defines its own `linked_author` field. |

The link is the post meta `linked_author` (a team member post ID). Anything that writes that meta works; ACF is only needed for the field UI.

## What changes

Only on the front end (and AJAX), only for the current post, and only when the lookup is for that post's own author. wp-admin keeps showing real users.

| WordPress output | Team member value |
|---|---|
| `get_the_author()` / `the_author()` | Title |
| `get_the_author_meta('display_name' \| 'nickname')` | Title |
| `first_name` / `last_name` | Title split on the first space |
| `description` | Content, else excerpt, tags stripped |
| `user_url`, `get_author_posts_url()` | Permalink |
| `get_avatar()` / `get_avatar_url()` | Featured image (`thumbnail`, or `medium` above 150px). Comment and email avatars are left alone. |
| Bricks `{author_name}` | Title (via `bricks/dynamic_data/author_value`, because Bricks reads `WP_User` directly) |

## Bricks tag

`{linked_author:field}` returns the team member's value, or the WordPress user's when none is linked. Registered through [`Bricks_Tags`](bricks-builder-tweaks.md#registering-dynamic-tags-bricks_tags).

| Field | Linked | Not linked |
|---|---|---|
| `name` (default) | Title | Display name |
| `bio` | Content or excerpt | User description |
| `url` | Permalink | Author archive URL |
| `photo` | Featured image URL | Avatar URL (300px) |
| `role` | Job Title terms, comma separated | empty |
| `id` | Team member ID | empty |

`photo` also works in image fields (returned as `[url]` in image context).

## Migrating from the OnePointHealth snippet

The snippet stored the same `linked_author` meta, so existing links keep working. Turn off "Add the Author field" if the site keeps its own field group. `{echo:oph_post_author_field(x)}` becomes `{linked_author:x}`; `qualifications` and the site's role meta key are not carried over (role now reads the Job Title taxonomy).

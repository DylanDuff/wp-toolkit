# Shopify Product Fields (ACF)

Tweak: `inc/tweaks/acf-shopify-products.php` (ACF tab, id `ddwpt_acf_shopify_products`). Code in `inc/shopify/`, assets `assets/js/acf-shopify-product(s).js` and `assets/css/acf-shopify.css`.

Two ACF field types backed by the Shopify **Storefront API**, plus Bricks loop tags. Folded in from the standalone **ACF Shopify Product Field** plugin (v0.6.2) as a drop-in replacement.

| Field type | Stores | UI |
|---|---|---|
| `shopify_product` | one product GID (`gid://shopify/Product/123`) | Select2 search |
| `shopify_products` | ordered array of GIDs | Relationship-style search + sortable list. Settings: placeholder, max, restrict to collection |

## Settings

| Option | Notes |
|---|---|
| `ddwpt_acf_shopify_products_enabled` | Tweak toggle |
| `ddwpt_acf_shopify_products_shop_domain` | Normalised to `shop.myshopify.com` (protocol and trailing slash stripped) |
| `ddwpt_acf_shopify_products_storefront_token` | Custom `shopify_token` field type: password input, blank keeps the saved token. Storefront tokens are public-read scoped, stored in plain text like the standalone plugin did. Included in settings export |

## Replacing the standalone plugin

Everything a site built on the standalone plugin depends on keeps its name:

- Field type names `shopify_product` / `shopify_products` and their settings keys, so field groups and stored values load unchanged.
- Global helpers `aspf_get_product()`, `aspf_get_product_field()`, `aspf_get_products()`, `aspf_get_products_field()` (plus the internal `aspf_get_cached_*` ones), defined in `inc/shopify/functions.php`.
- Filter `aspf_product_cache_ttl` (default 15 minutes), transient keys `aspf_product_{full|summary}_{md5}`, WP_Error codes `aspf_*`.
- AJAX action and nonce `aspf_search_products`, CSS classes `aspf-*`.
- Bricks tags `{shopify_products:FIELD}` and `{shopify_product_title|image|handle|gid|vendor|price|currency}`, group "Shopify Products (ACF)".

**Migration.** The standalone plugin kept credentials in the `aspf_settings` array option. On the first load where that plugin is inactive and `aspf_settings` holds credentials, the tweak copies them into its own options (only where those are empty), switches itself on if it has never been toggled, and sets `ddwpt_acf_shopify_products_migrated` so it runs once. `aspf_settings` is left in place, so reactivating the standalone plugin still works, and `Client::credentials()` falls back to it per key.

**Both active.** The standalone plugin declares the same global functions and field types, so while `ASPF_PLUGIN_FILE` is defined the tweak registers nothing (no globals, field types, AJAX or Bricks tags) and shows an admin notice asking for the standalone plugin to be deactivated. Plugin load order doesn't matter: tweak files load on `plugins_loaded`, after every plugin file.

## Load timing

ACF fires `acf/include_field_types` on `init` priority 5, before tweak callbacks, so field types and the AJAX handler are registered at file-load time behind `get_option('ddwpt_acf_shopify_products_enabled')` (same pattern as `acf-abilities-api.php`). Bricks tags are registered in the callback because `BRICKS_VERSION` is only defined once the theme loads.

## Bricks

Enable "Use dynamic data as loop source" on a loop element and use `{shopify_products:FIELD_NAME}` (field name or key). It resolves the field on the current post, then falls back to the options page under the same name. Bricks' Array query type `json_decode()`s the value, so the tag returns a JSON string.

- Item tags go through `Bricks_Tags::register()` and read keys from `\Bricks\Query::get_loop_object()`. `{shopify_product_image}` returns `[url]` in image context.
- The loop source is wired by hand rather than through `Bricks_Tags`, because `Bricks_Tags` escapes content and that would break the JSON. Its `render_content` / `render_data` filters run at `PHP_INT_MAX`: other plugins on those filters (WPSR, BricksExtras) assume string content and have fataled when they ran after it.

## Storefront API

`DDWPTweaks\Shopify\Client` (`inc/shopify/class-shopify-client.php`), API version `2024-10`.

- `search_products($term)`: title search; a blank term is sent as `' '`, which Shopify treats as "no filter" (an empty string matches nothing).
- `search_products_in_collection($gid, $term)`: `Collection.products` has no search argument, so it fetches the first 250 and filters in PHP.
- `get_products($gids)`: summaries in one `nodes()` call, cached per product, so only misses hit the API. `price` is `minVariantPrice`.
- `get_product($gid)`: full product (images, variants, price range). Misses aren't cached.

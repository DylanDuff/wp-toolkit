<?php

namespace DDWPTweaks\Shopify;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around the Shopify Storefront GraphQL API, used by the
 * Shopify Product(s) ACF fields. Ported from the standalone
 * "ACF Shopify Product Field" plugin; transient keys, error codes and the
 * `aspf_product_cache_ttl` filter keep their original names so caches and
 * site code carry over. See docs/acf-shopify-products.md.
 */
final class Client
{
    public const API_VERSION = '2024-10';

    public const OPTION_DOMAIN = 'ddwpt_acf_shopify_products_shop_domain';
    public const OPTION_TOKEN  = 'ddwpt_acf_shopify_products_storefront_token';

    /** Option the standalone plugin stored its credentials in: ['shop_domain' => …, 'storefront_token' => …]. */
    public const LEGACY_OPTION = 'aspf_settings';

    /** Default transient TTL for product lookups. Filter with `aspf_product_cache_ttl`. */
    private const DEFAULT_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    private string $shop_domain;
    private string $storefront_token;

    public function __construct(string $shop_domain, string $storefront_token)
    {
        $this->shop_domain      = $shop_domain;
        $this->storefront_token = $storefront_token;
    }

    /**
     * Saved credentials. Falls back to the standalone plugin's option per key,
     * so a site that switched over before the migration ran still resolves.
     */
    public static function credentials(): array
    {
        $legacy = get_option(self::LEGACY_OPTION, []);
        $legacy = is_array($legacy) ? $legacy : [];

        return [
            'shop_domain'      => (string) (get_option(self::OPTION_DOMAIN, '') ?: ($legacy['shop_domain'] ?? '')),
            'storefront_token' => (string) (get_option(self::OPTION_TOKEN, '') ?: ($legacy['storefront_token'] ?? '')),
        ];
    }

    /** "https://shop.myshopify.com/" → "shop.myshopify.com" */
    public static function normalize_domain($value): string
    {
        $domain = trim(sanitize_text_field((string) $value));
        return untrailingslashit(preg_replace('#^https?://#', '', $domain));
    }

    /** @return self|\WP_Error */
    public static function from_settings()
    {
        $credentials = self::credentials();

        if ($credentials['shop_domain'] === '' || $credentials['storefront_token'] === '') {
            return new \WP_Error('aspf_missing_credentials', 'Shopify shop domain and Storefront API token must be configured under Tools → WP Toolkit → ACF before this field can be used.');
        }

        return new self($credentials['shop_domain'], $credentials['storefront_token']);
    }

    /**
     * Search products by title. Blank terms return Shopify's default listing.
     *
     * @return array|\WP_Error List of ['gid', 'title', 'handle', 'image'].
     */
    public function search_products(string $term, int $limit = 20)
    {
        $query = <<<'GRAPHQL'
query SearchProducts($query: String!, $first: Int!) {
  products(query: $query, first: $first) {
    edges { node { id title handle featuredImage { url(transform: { maxWidth: 64, maxHeight: 64 }) } } }
  }
}
GRAPHQL;

        $term = trim($term);

        // Shopify treats "" as "match nothing" but whitespace as "no filter",
        // so a blank term is sent as a single space to get the default listing.
        $data = $this->request($query, [
            'query' => $term === '' ? ' ' : sprintf('title:*%s*', $term),
            'first' => self::clamp_limit($limit),
        ]);

        if (is_wp_error($data)) {
            return $data;
        }

        return array_map([self::class, 'search_result'], array_column($data['products']['edges'] ?? [], 'node'));
    }

    /**
     * Search by title within one collection. Collection.products takes no
     * search argument, so this fetches the first 250 products and filters in
     * PHP; larger collections only search their first page.
     *
     * @return array|\WP_Error Same shape as search_products().
     */
    public function search_products_in_collection(string $collection_gid, string $term, int $limit = 20)
    {
        $query = <<<'GRAPHQL'
query SearchCollectionProducts($id: ID!, $first: Int!) {
  collection(id: $id) {
    products(first: $first) {
      edges { node { id title handle featuredImage { url(transform: { maxWidth: 64, maxHeight: 64 }) } } }
    }
  }
}
GRAPHQL;

        $data = $this->request($query, ['id' => $collection_gid, 'first' => 250]);

        if (is_wp_error($data)) {
            return $data;
        }

        if (empty($data['collection'])) {
            return new \WP_Error('aspf_collection_not_found', 'The configured Shopify collection could not be found.');
        }

        $term    = trim($term);
        $results = [];

        foreach (array_column($data['collection']['products']['edges'] ?? [], 'node') as $node) {
            if ($term !== '' && stripos($node['title'], $term) === false) {
                continue;
            }

            $results[] = self::search_result($node);

            if (count($results) >= self::clamp_limit($limit)) {
                break;
            }
        }

        return $results;
    }

    /**
     * Summaries (gid, title, handle, image, vendor, price, currency) for a
     * list of GIDs, in the order given. `price` is minVariantPrice (the
     * "from" price); `image` is a 600px transform. Each product is cached on
     * its own, so only cache misses go to the API, in one nodes() request.
     *
     * @return array|\WP_Error
     */
    public function get_products(array $gids)
    {
        $gids = array_values(array_unique(array_filter($gids)));
        if (!$gids) {
            return [];
        }

        $by_id   = [];
        $missing = [];

        foreach ($gids as $gid) {
            $cached = get_transient($this->cache_key('summary', $gid));
            if ($cached !== false) {
                $by_id[$gid] = $cached;
            } else {
                $missing[] = $gid;
            }
        }

        if ($missing) {
            $query = <<<'GRAPHQL'
query GetProducts($ids: [ID!]!) {
  nodes(ids: $ids) {
    id
    ... on Product {
      title handle vendor
      featuredImage { url(transform: { maxWidth: 600, maxHeight: 600 }) }
      priceRange { minVariantPrice { amount currencyCode } }
    }
  }
}
GRAPHQL;

            $data = $this->request($query, ['ids' => $missing]);
            if (is_wp_error($data)) {
                return $data;
            }

            foreach ($data['nodes'] ?? [] as $node) {
                if (empty($node) || !isset($node['title'])) {
                    continue;
                }

                $product = self::search_result($node) + [
                    'image'    => $node['featuredImage']['url'] ?? '',
                    'vendor'   => $node['vendor'] ?? '',
                    'price'    => $node['priceRange']['minVariantPrice']['amount'] ?? '',
                    'currency' => $node['priceRange']['minVariantPrice']['currencyCode'] ?? '',
                ];

                $by_id[$node['id']] = $product;
                set_transient($this->cache_key('summary', $node['id']), $product, $this->cache_ttl());
            }
        }

        return array_values(array_filter(array_map(static fn($gid) => $by_id[$gid] ?? null, $gids)));
    }

    /**
     * Collections for the "Restrict to Collection" setting and filter.
     *
     * @return array|\WP_Error List of ['gid', 'title', 'handle'].
     */
    public function get_collections(int $limit = 250)
    {
        $query = <<<'GRAPHQL'
query GetCollections($first: Int!) {
  collections(first: $first, sortKey: TITLE) { edges { node { id title handle } } }
}
GRAPHQL;

        $data = $this->request($query, ['first' => max(1, min(250, $limit))]);
        if (is_wp_error($data)) {
            return $data;
        }

        return array_map(static fn($node) => [
            'gid'    => $node['id'],
            'title'  => $node['title'],
            'handle' => $node['handle'],
        ], array_column($data['collections']['edges'] ?? [], 'node'));
    }

    /**
     * Full product data for a stored GID. Only successful lookups are cached,
     * so a deleted product is retried rather than cached as a miss.
     *
     * @return array|\WP_Error
     */
    public function get_product(string $gid)
    {
        if ($gid === '') {
            return new \WP_Error('aspf_missing_gid', 'No Shopify product GID provided.');
        }

        $cache_key = $this->cache_key('full', $gid);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $query = <<<'GRAPHQL'
query GetProduct($id: ID!) {
  product(id: $id) {
    id title handle description descriptionHtml vendor productType tags availableForSale onlineStoreUrl
    featuredImage { url altText }
    images(first: 10) { edges { node { url altText } } }
    priceRange {
      minVariantPrice { amount currencyCode }
      maxVariantPrice { amount currencyCode }
    }
    variants(first: 50) {
      edges {
        node {
          id title availableForSale quantityAvailable
          price { amount currencyCode }
          selectedOptions { name value }
        }
      }
    }
  }
}
GRAPHQL;

        $data = $this->request($query, ['id' => $gid]);
        if (is_wp_error($data)) {
            return $data;
        }

        if (empty($data['product'])) {
            return new \WP_Error('aspf_not_found', 'No Shopify product found for the stored GID.');
        }

        set_transient($cache_key, $data['product'], $this->cache_ttl());

        return $data['product'];
    }

    /** @return array|\WP_Error The response's `data`. */
    private function request(string $query, array $variables = [])
    {
        $response = wp_remote_post(sprintf('https://%s/api/%s/graphql.json', $this->shop_domain, self::API_VERSION), [
            'timeout' => 15,
            'headers' => [
                'Content-Type'                      => 'application/json',
                'X-Shopify-Storefront-Access-Token' => $this->storefront_token,
            ],
            'body' => wp_json_encode(['query' => $query, 'variables' => $variables]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            return new \WP_Error('aspf_http_error', sprintf('Shopify Storefront API returned HTTP %d.', $status));
        }

        if (!empty($body['errors'])) {
            return new \WP_Error('aspf_graphql_error', is_array($body['errors']) ? wp_json_encode($body['errors']) : (string) $body['errors']);
        }

        return $body['data'] ?? [];
    }

    /** One search result row from a product node. */
    private static function search_result(array $node): array
    {
        return [
            'gid'    => $node['id'],
            'title'  => $node['title'],
            'handle' => $node['handle'] ?? '',
            'image'  => $node['featuredImage']['url'] ?? '',
        ];
    }

    private static function clamp_limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    /** Same key scheme as the standalone plugin, so its cached entries are reused. */
    private function cache_key(string $variant, string $gid): string
    {
        return 'aspf_product_' . $variant . '_' . md5($this->shop_domain . '|' . $gid);
    }

    private function cache_ttl(): int
    {
        return (int) apply_filters('aspf_product_cache_ttl', self::DEFAULT_CACHE_TTL);
    }
}

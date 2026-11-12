<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class SchemaOrgService
{
    /**
     * Parse product data from schema.org JSON-LD.
     *
     * Assumes $collection is a collection of schema.org JSON-LD. The one we need has
     *
     * @type = Product. From there, extract out data for the field we want.
     */
    public static function parseSchemaOrg(Collection $collection, string $field, ?string $pageUrl = null): ?string
    {
        $schema = self::findProduct($collection, $pageUrl);

        if (! $schema) {
            return null;
        }

        $value = match ($field) {
            // Get title from name.
            'title' => data_get($schema, 'name'),
            // Full description.
            'description' => data_get($schema, 'description'),
            // First try for lowest, then price, finally priceSpecification price.
            'price' => data_get($schema, 'offers.lowPrice', data_get($schema, 'offers.price', data_get($schema, 'offers.0.price', data_get($schema, 'offers.priceSpecification.price')))),
            // Currency.
            'price_currency' => data_get($schema, 'offers.priceCurrency', data_get($schema, 'offers.0.priceCurrency', data_get($schema, 'offers.priceSpecification.priceCurrency', 'USD'))),
            // Image: a string, an array of strings, an ImageObject ({url: ...}), or an array of those.
            'image' => self::firstImageString(data_get($schema, 'image')),
            // Availability should be a string, sometimes array of strings.
            'availability' => is_string(data_get($schema, 'offers.availability', data_get($schema, 'offers.0.availability')))
                ? data_get($schema, 'offers.availability', data_get($schema, 'offers.0.availability'))
                : (is_string(data_get($schema, 'offers.availability.0', data_get($schema, 'offers.0.availability.0')))
                    ? data_get($schema, 'offers.availability.0', data_get($schema, 'offers.0.availability.0'))
                    : null),
            default => null
        };

        // Schema.org fields are occasionally nested arrays/objects; coerce scalars to
        // string and anything non-stringable to null so the ?string contract always holds.
        if (is_array($value)) {
            return null;
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Pick the Product node for the page.
     *
     * Blocks may be a list (Morele: [ProductGroup, Product, Product, ...]) or carry
     * an @graph, and a ProductGroup may list its variants in hasVariant. All of
     * these are flattened first. Then, in order: the Product whose url is the page
     * address, a Product with offers, the first Product.
     *
     * @param  Collection<int, mixed>  $collection
     * @return array<string, mixed>|null
     */
    public static function findProduct(Collection $collection, ?string $pageUrl = null): ?array
    {
        $products = array_values(array_filter(
            self::flattenNodes($collection->all()),
            static fn (array $node): bool => self::isProduct($node),
        ));

        if ($products === []) {
            return null;
        }

        if (filled($pageUrl)) {
            $page = self::normaliseUrl((string) $pageUrl);

            foreach ($products as $product) {
                $url = data_get($product, 'url');

                if (is_string($url) && self::normaliseUrl($url) === $page) {
                    return $product;
                }
            }
        }

        foreach ($products as $product) {
            if (filled(data_get($product, 'offers'))) {
                return $product;
            }
        }

        return $products[0];
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<array<string, mixed>>
     */
    protected static function flattenNodes(array $items, int $depth = 0): array
    {
        $nodes = [];

        if ($depth > 5) {
            return $nodes;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (array_is_list($item)) {
                array_push($nodes, ...self::flattenNodes($item, $depth + 1));

                continue;
            }

            $nodes[] = $item;

            if (is_array($graph = data_get($item, '@graph'))) {
                array_push($nodes, ...self::flattenNodes(array_is_list($graph) ? $graph : [$graph], $depth + 1));
            }

            if (is_array($variants = data_get($item, 'hasVariant'))) {
                array_push($nodes, ...self::flattenNodes(array_is_list($variants) ? $variants : [$variants], $depth + 1));
            }
        }

        return $nodes;
    }

    /**
     * Match the Product type case-insensitively, and tolerate an array @type
     * (e.g. ["Product", "Thing"]). Some sites use a lowercase "product" type.
     *
     * @param  array<string, mixed>  $node
     */
    protected static function isProduct(array $node): bool
    {
        $types = array_map(
            static fn ($type): string => strtolower((string) $type),
            (array) data_get($node, '@type'),
        );

        return in_array('product', $types, true);
    }

    protected static function normaliseUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = (string) preg_replace('/#.*$/', '', $url);
        $url = (string) preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/');
    }

    /**
     * Resolve the first usable image URL string from a schema.org image value, which may be
     * a string, an array of strings, an ImageObject (`{"url": ...}`), or an array of those.
     */
    private static function firstImageString(mixed $image): ?string
    {
        if (is_string($image)) {
            return $image;
        }

        if (! is_array($image)) {
            return null;
        }

        // Single ImageObject: {"@type": "ImageObject", "url": "..."}.
        if (is_string($url = data_get($image, 'url'))) {
            return $url;
        }

        foreach ($image as $item) {
            if (is_string($item)) {
                return $item;
            }

            if (is_array($item) && is_string($url = data_get($item, 'url'))) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Extract a product field from HTML microdata (itemscope/itemprop) as a fallback to
     * JSON-LD. Anchored to a schema.org/Product itemscope. Returns null when the HTML is
     * blank, has no Product microdata, lacks the field, or any Crawler error occurs.
     */
    public static function parseMicrodata(?string $html, string $field): ?string
    {
        if (blank($html)) {
            return null;
        }

        $itemprop = match ($field) {
            'title' => 'name',
            'description' => 'description',
            'price' => 'price',
            'price_currency' => 'priceCurrency',
            'image' => 'image',
            'availability' => 'availability',
            default => null,
        };

        if ($itemprop === null) {
            return null;
        }

        try {
            $product = (new Crawler($html))
                ->filter('[itemscope][itemtype*="schema.org/Product"]')
                ->first();

            if (! $product->count()) {
                return null;
            }

            $node = $product->filter('[itemprop="'.$itemprop.'"]')->first();

            return $node->count() ? self::microdataValue($node) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve a microdata property node's value: the `content` attribute, else a media
     * element's `src`, else a link element's `href`, else the trimmed text content.
     */
    private static function microdataValue(Crawler $node): ?string
    {
        $content = $node->attr('content');
        if ($content !== null && trim($content) !== '') {
            return trim($content);
        }

        $tag = strtolower($node->nodeName());

        if ($tag === 'data' && filled($value = $node->attr('value'))) {
            return trim($value);
        }

        if (in_array($tag, ['img', 'source', 'iframe', 'embed', 'video', 'audio', 'track'], true)
            && filled($src = $node->attr('src'))) {
            return trim($src);
        }

        if (in_array($tag, ['a', 'link', 'area'], true) && filled($href = $node->attr('href'))) {
            return trim($href);
        }

        return filled($text = trim($node->text(''))) ? $text : null;
    }
}

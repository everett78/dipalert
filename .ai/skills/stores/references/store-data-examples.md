# Store Data Examples

## Real-World Store Configurations

These examples are from `database/seeders/Stores/` and represent tested, working configurations.

### Amazon US (CSS Selectors + Regex)

```php
[
    'name' => 'Amazon US',
    'domains' => [
        ['domain' => 'amazon.com'],
        ['domain' => 'www.amazon.com'],
    ],
    'scrape_strategy' => [
        'title' => ['type' => 'selector', 'value' => 'title'],
        'price' => ['type' => 'selector', 'value' => '.a-price > .a-offscreen'],
        'image' => ['type' => 'regex', 'value' => '~"hiRes":"(.+?)"~'],
    ],
]
```

**Notes:**
- Amazon title comes from the `<title>` tag
- Price uses Amazon's specific `.a-price > .a-offscreen` hidden element
- Image uses regex to extract high-res URL from embedded JSON (`"hiRes":"..."`)

### eBay US (Open Graph Meta Tags)

```php
[
    'name' => 'eBay US',
    'domains' => [
        ['domain' => 'ebay.com'],
        ['domain' => 'www.ebay.com'],
    ],
    'scrape_strategy' => [
        'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
        'price' => ['type' => 'selector', 'value' => '.x-price-primary'],
        'image' => ['type' => 'selector', 'value' => 'meta[property=og:image]|content'],
    ],
]
```

**Notes:**
- Title and image use Open Graph meta tags with pipe attribute extraction
- Price uses eBay's specific price class

### Schema.org Store (JSON-LD)

```php
[
    'scrape_strategy' => [
        'title' => ['type' => 'schema_org', 'value' => null],
        'price' => ['type' => 'schema_org', 'value' => null],
        'image' => ['type' => 'schema_org', 'value' => null],
        'availability' => ['type' => 'schema_org', 'value' => null],
    ],
]
```

**Notes:**
- No value needed for schema_org type
- Relies on `@type: Product` JSON-LD embedded in the page
- Most reliable method when available

## Store Import/Export JSON Format

The share/import format includes only portable fields:

```json
{
    "name": "Amazon US",
    "slug": "amazon-us",
    "domains": [
        {"domain": "amazon.com"},
        {"domain": "www.amazon.com"}
    ],
    "scrape_strategy": {
        "title": {"type": "selector", "value": "title"},
        "price": {"type": "selector", "value": ".a-price > .a-offscreen"},
        "image": {"type": "regex", "value": "~\"hiRes\":\"(.+?)\"~"}
    },
    "settings": {
        "scraper_service": "http",
        "scraper_service_settings": "",
        "test_url": "https://www.amazon.com/dp/B0EXAMPLE",
        "locale_settings": {
            "locale": "en_US",
            "currency": "USD"
        }
    }
}
```

**Import validation requires:** name, domains (array), scrape_strategy.title (type + value), scrape_strategy.price (type + value), settings.scraper_service (valid enum).

## Store with API Scraper and Advanced Settings

```php
[
    'name' => 'JS-Heavy Store',
    'domains' => [['domain' => 'jsstore.com'], ['domain' => 'www.jsstore.com']],
    'scrape_strategy' => [
        'title' => ['type' => 'selector', 'value' => 'h1.product-title'],
        'price' => ['type' => 'selector', 'value' => '.dynamic-price'],
        'image' => ['type' => 'selector', 'value' => '.product-gallery img|src', 'prepend' => 'https://jsstore.com'],
    ],
    'settings' => [
        'scraper_service' => 'api',
        'scraper_service_settings' => "device=Desktop Firefox\nsleep=1000",
        'locale_settings' => ['locale' => 'en_US', 'currency' => 'USD'],
    ],
]
```

**Notes:**
- Uses `api` scraper for JavaScript-rendered content
- `sleep=1000` gives the page time to render
- Image uses `prepend` to construct absolute URL from relative `src`

## Store with Availability Matching

```php
[
    'scrape_strategy' => [
        'title' => ['type' => 'schema_org', 'value' => null],
        'price' => ['type' => 'schema_org', 'value' => null],
        'image' => ['type' => 'schema_org', 'value' => null],
        'availability' => [
            'type' => 'schema_org',
            'value' => null,
            'match' => [
                'default' => 'in_stock',
                'out_of_stock' => ['type' => 'regex', 'value' => 'OutOfStock|SoldOut'],
                'pre_order' => ['type' => 'regex', 'value' => 'PreOrder|PreSale'],
                'discontinued' => ['type' => 'regex', 'value' => 'Discontinued'],
            ],
        ],
    ],
]
```

**Notes:**
- Schema.org availability returns values like `https://schema.org/OutOfStock`
- Regex match is ideal here since the value contains the full URL
- First matching rule wins; `default` is the fallback
- Available match statuses: `out_of_stock`, `pre_order`, `back_order`, `special_order`, `discontinued`

## Store with Cookies

```php
[
    'name' => 'Cookie Store',
    'cookies' => 'consent=accepted; region=US; session=abc123',
    // ...
]
```

**Notes:**
- Cookies are sent with every scrape request for this store
- Useful for stores that block requests without consent/session cookies
- Format: standard HTTP cookie string

## Factory Usage in Tests

```php
// Basic store
$store = Store::factory()->create();

// Store with specific domains
$store = Store::factory()->create([
    'domains' => [
        ['domain' => 'mystore.com'],
        ['domain' => 'www.mystore.com'],
    ],
]);

// Store from URL
$store = Store::factory()->forUrl('https://www.example.com/product')->create();

// Store with custom strategy
$store = Store::factory()->create([
    'scrape_strategy' => [
        'title' => ['type' => 'schema_org', 'value' => null],
        'price' => ['type' => 'selector', 'value' => '.price'],
        'image' => ['type' => 'selector', 'value' => 'meta[property=og:image]|content'],
    ],
]);

// Store with API scraper
$store = Store::factory()->create([
    'settings' => [
        'scraper_service' => 'api',
        'scraper_service_settings' => "device=Desktop Firefox\nsleep=1000",
    ],
]);
```

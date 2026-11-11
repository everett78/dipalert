---
name: stores
description: This skill should be used when the user asks to "create a store", "edit a store", "debug a store", "import a store", "export a store", "test a store", "fix store domain matching", discusses store configuration, store settings, store scrape strategies, auto store creation, store seeding, or troubleshoots store-related issues.
version: 0.1.0
---

# Stores

## Overview

A Store represents a retailer domain (e.g., Amazon, eBay) and holds all configuration for how to scrape product data from that retailer's pages. Stores are the central configuration entity — every URL belongs to a store, and the store's scrape strategy determines how data is extracted.

## Database Schema

**Table:** `stores`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int | Primary key |
| `name` | string | Display name (e.g., "Amazon US") |
| `slug` | string | Auto-generated from name via Spatie HasSlug |
| `initials` | string, nullable | 2-char code, auto-generated from name if empty |
| `domains` | json | Array of `{"domain": "example.com"}` objects |
| `scrape_strategy` | json | Extraction rules per field (title, price, image, availability) |
| `settings` | json | Scraper service, locale, currency, test URL, API options |
| `notes` | text, nullable | Rich text notes |
| `cookies` | text, nullable | HTTP cookies string (`cookie1=value; cookie2=value`) |
| `user_id` | foreign, nullable | Owner user |
| `timestamps` | | created_at, updated_at |

## Model: `App\Models\Store`

### Relationships

- `user()` — BelongsTo `User`
- `urls()` — HasMany `Url`
- `products()` — HasManyThrough `Product` (via `Url`)

### Key Accessors

- `initials` — Auto-generates 2-letter code from name ("Example Store" -> "ES", "Amazon" -> "AM")
- `domains_html` — Comma-separated domains as HtmlString
- `scraper_service` — From `settings.scraper_service`, defaults to `http`
- `scraper_options` — Parses `settings.scraper_service_settings` newline-separated `key=value` pairs into array
- `test_url` — From `settings.test_url`
- `locale` — From `settings.locale_settings.locale`, falls back to `CurrencyHelper::getLocale()`
- `currency` — From `settings.locale_settings.currency`, falls back to `CurrencyHelper::getCurrency()`

### Domain Matching

The `scopeDomainFilter(Builder $query, string|array $domains)` scope filters stores by domain using `whereJsonContains` against the `domains` JSON column. It accepts a single domain string or array.

`hasDomain($domain)` is an instance method that checks if a store contains a specific domain.

**Important:** `ScrapeUrl::getStore()` resolves a store by extracting the URL host via `Uri::of($url)->host()` and querying with `domainFilter($host)->oldest()->first()`. The `oldest()` ensures deterministic results when multiple stores share a domain.

### Deletion Cascade

When a store is deleted (via model `booted` event):
1. All affected products are collected first
2. URLs are deleted individually (triggers URL model events which cascade to prices)
3. All affected product price caches are updated

## Store JSON Structure

### `domains` Column
```json
[
    {"domain": "example.com"},
    {"domain": "www.example.com"}
]
```
Always register both `www` and non-`www` variants.

### `scrape_strategy` Column

Each field (title, price, image, availability) has a strategy entry. See the `url-scraping` skill for complete strategy type documentation.

```json
{
    "title":  {"type": "selector", "value": "meta[property=og:title]|content"},
    "price":  {"type": "selector", "value": ".a-price > .a-offscreen"},
    "image":  {"type": "regex", "value": "~\"hiRes\":\"(.+?)\"~"},
    "availability": {
        "type": "selector",
        "value": ".stock-label",
        "match": {
            "default": "in_stock",
            "out_of_stock": {"type": "match", "value": "Out of Stock"},
            "pre_order": {"type": "regex", "value": "pre.?order"}
        }
    }
}
```

### `settings` Column
```json
{
    "scraper_service": "http",
    "scraper_service_settings": "device=Desktop Firefox\nsleep=1000",
    "test_url": "https://example.com/product",
    "locale_settings": {
        "locale": "en_US",
        "currency": "USD"
    }
}
```

- `scraper_service` — `http` (curl, fast) or `api` (browser-based, JS rendering)
- `scraper_service_settings` — Only used for API scraper. Newline-separated `key=value` options passed to seleniumbase
- `test_url` — Saved when testing a store via the Test page
- `locale_settings` — Used by `CurrencyHelper` for price parsing and formatting

## Auto Store Creation

`AutoCreateStore` (`app/Services/AutoCreateStore.php`) automatically creates a store when a URL is submitted for an unrecognized domain.

### Flow

1. `createStoreFromUrl($url)` extracts the host and checks for an existing store via `domainFilter`
2. If no store exists, it fetches the page HTML and runs `strategyParse()`
3. `strategyParse()` attempts extraction for title, price, and image in priority order:
   - **Schema.org** JSON-LD (`@type: Product`) — checked first, most reliable
   - **CSS selectors** from `config/price_buddy.php` `auto_create_store_strategies`
   - **Regex patterns** from the same config
4. If title and price extraction succeed, `getStoreAttributes()` builds the store data
5. The `CreateStoreAction` action creates the store with locale/currency defaults

### Domain Handling During Auto-Creation

```php
$host = strtolower(Uri::of($this->url)->host());
if (str_starts_with($host, 'www.')) {
    $host = substr($host, 4);
}
// Creates both variants
$attributes['domains'] = [
    ['domain' => $host],
    ['domain' => 'www.'.$host],
];
```

The store name is set to `ucfirst($host)` (e.g., "Example.com").

### Auto-Detection Config

`config/price_buddy.php` defines the fallback selectors and regex patterns. Key price selectors include:
- `meta[property="product:price:amount"]|content`
- `meta[property="og:price:amount"]|content`
- `.a-price .a-offscreen` (Amazon)
- `[itemProp="price"]|content`
- `.price`, `.product-price`, `[class*="price"]`

## Filament Admin UI

### Resource: `StoreResource`

**Location:** `app/Filament/Resources/StoreResource.php`

### Pages

| Page | Class | Purpose |
|------|-------|---------|
| List | `StoreResource/Pages/ListStores.php` | Store listing with product count, scraper badge |
| Create | `StoreResource/Pages/CreateStore.php` | New store form with "Create & test" option |
| Edit | `StoreResource/Pages/EditStore.php` | Edit form with Share, Test, Delete actions |
| Test | `StoreResource/Pages/TestStore.php` | Test scrape with URL, shows results widget |

### Form Sections

1. **Basics** — Name (required)
2. **Domains** — Repeater of domain entries
3. **Scrape Strategy** — Per-field strategy inputs (title, price, image, availability) built via `HasScraperTrait::makeStrategyInput()`. Each has type select, value input, prepend/append fields. Schema.org type hides value/prepend/append
4. **Availability Match** — Dynamic form groups for each non-InStock `StockStatus` case. Each has match type (exact/regex) and value. First match wins, with configurable default fallback
5. **Scraper Service** — HTTP vs API radio with API-only settings textarea
6. **Locale** — Locale and currency selects
7. **Cookies** — Cookie string input
8. **Notes** — RichEditor

### HasScraperTrait

`app/Filament/Concerns/HasScraperTrait.php` provides:
- `makeStrategyInput($key, $default, $required)` — Creates the type/value/prepend/append field group
- `getScraperSettings()` — Creates the scraper service section

### Actions

- **ImportStoreAction** — Modal with JSON textarea, validates with `ImportStore` rule, creates via `CreateStoreAction`. Keyboard shortcut: `mod+i`
- **ShareStoreAction** — Modal displaying JSON export (name, slug, domains, scrape_strategy, settings) with copy-to-clipboard
- **TestAfterEdit trait** — Adds "Save & test" button that redirects to TestStore page after saving

### Test Store Page

1. Enter a product URL and click "Test url scrape"
2. `ScrapeUrl::new($url)->scrape(['store' => $store, 'use_cache' => false])` runs with cache bypass
3. Test URL is saved to `settings.test_url`
4. `TestResultsWidget` displays scraped title, price, image, availability with stock status resolution details

## Validation Rules

### `StoreUrl` (`app/Rules/StoreUrl.php`)

Used when adding URLs to products. Validates that:
1. The URL's domain belongs to an existing store, OR
2. A store can be auto-created from the URL (`AutoCreateStore::canAutoCreateFromUrl()`)
3. The scrape returns a valid title and price (unless product is unavailable)

### `ImportStore` (`app/Rules/ImportStore.php`)

Validates JSON import structure requires:
- `name` — Store name
- `domains` — Array of domain objects
- `scrape_strategy.title` — Title strategy with type and value
- `scrape_strategy.price` — Price strategy with type and value
- `settings.scraper_service` — Valid `ScraperService` enum value

## Factory & Seeding

### `StoreFactory` (`database/factories/StoreFactory.php`)

**Defaults:**
- Random name, domains `[['domain' => 'example.com']]`
- Selector strategies using `og:title`, `og:price:amount`, `og:image` meta tags
- HTTP scraper service

**States:**
- `forUrl(string $url)` — Generates name and domains from a URL's hostname

### `StoreSeeder` (`database/seeders/StoreSeeder.php`)

Loads country-specific store data from `database/seeders/Stores/`:
- `usa.php` — Amazon US, eBay US
- `australia.php` — 15 stores including Amazon AU, JB Hi-Fi, Good Guys, BWS, etc.

Each seeder entry includes real-world scrape strategies for that retailer.

## Testing

### Test Files

| File | Coverage |
|------|----------|
| `tests/Feature/Filament/StoreTest.php` | Admin CRUD (list, create, edit forms) |
| `tests/Feature/Api/StoreApiTest.php` | API endpoints (15 tests: CRUD, auth, filtering, sorting, relationships) |
| `tests/Feature/Models/StoreTest.php` | Model behavior (initials, domains, scraper options, deletion cascade) |
| `tests/Unit/Rules/ImportStoreTest.php` | Import JSON validation |
| `tests/Unit/Services/AutoCreateStoreTest.php` | Auto-detection from HTML (meta tags, selectors, regex, schema.org) |

### Testing Patterns

```php
// Create store with factory
$store = Store::factory()->create(['name' => 'Test Store']);

// Create store for a specific URL
$store = Store::factory()->forUrl('https://example.com/product')->create();

// Test domain matching
Store::query()->domainFilter('example.com')->first();

// Test auto-creation from HTML
AutoCreateStore::new($url, $html)->strategyParse();

// Test Filament form in Livewire test
livewire(EditStore::class, ['record' => $store->id])
    ->fillForm([...])
    ->call('save')
    ->assertNotified();
```

## Debugging Stores

### Common Issues

**Store not found for a URL** — Check domain registration. The host extracted from the URL must exactly match a `domain` entry in the store's `domains` JSON. Test with `ScrapeUrl::new($url)->getStore()`.

**Auto-creation fails** — `AutoCreateStore` requires both title and price extraction to succeed. Test with `AutoCreateStore::new($url)->strategyParse()` to see what was detected. If the page needs JavaScript, auto-creation uses HTTP by default and may fail.

**Wrong store matched** — `getStore()` uses `oldest()->first()`, so the earliest-created store with a matching domain wins. Check for duplicate domain entries across stores.

**Scraper options not applied** — The `scraper_service_settings` field must be newline-separated `key=value` format. The accessor `scraper_options` parses these. Invalid entries (missing `=`) are silently filtered out.

**Locale/currency issues** — If prices display incorrectly, check `settings.locale_settings` on the store. `CurrencyHelper::toFloat()` uses locale-aware parsing. A store with `en_US` locale parses `1,234.56` differently than `de_DE` which expects `1.234,56`.

### Tinker Debugging

```php
// Inspect a store's full config
$store = Store::find(1);
dd($store->scrape_strategy, $store->settings, $store->scraper_options);

// Test domain resolution
Store::query()->domainFilter('www.amazon.com')->get();

// Test auto-detection on a URL
$auto = AutoCreateStore::new('https://example.com/product');
dd($auto->strategyParse(), $auto->getStoreAttributes());

// Full scrape test with a specific store
ScrapeUrl::new('https://example.com/product')->scrape(['store' => $store]);
```

## Additional Resources

- **`references/store-data-examples.md`** — Real-world store configuration examples from seeders
- **`url-scraping` skill** — Complete documentation on scrape strategy types, selector syntax, and the scraping pipeline

---
name: url-scraping
description: This skill should be used when the user asks to "scrape a URL", "fix scraping", "debug scraping", "add a store", "configure selectors", "extract price", "extract data from a page", discusses scraping strategies, store configuration, price extraction, availability detection, or troubleshoots why a URL is not returning data.
version: 0.1.0
---

# URL Scraping

## Overview

Price Buddy extracts product data (title, price, image, availability) from retailer URLs. The pipeline is: **Store lookup -> HTTP/API fetch -> Strategy-based extraction -> Price storage**. Each store defines its own scrape strategy specifying how to extract each field from the page HTML.

## Core Architecture

### Scraping Pipeline

1. A URL is submitted (via UI, command, or scheduled job)
2. `ScrapeUrl` resolves the **Store** by matching the URL's domain against `Store.domains`
3. The appropriate **scraper service** (HTTP or API) fetches the page HTML
4. Each field (title, price, image, availability) is extracted using the store's **scrape strategy**
5. Extracted price is normalized to a float and stored as a `Price` record
6. The parent `Product.price_cache` is updated with denormalized data from all URLs

### Key Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `ScrapeUrl` | `app/Services/ScrapeUrl.php` | Main scraping orchestrator |
| `AutoCreateStore` | `app/Services/AutoCreateStore.php` | Auto-detect store settings from a URL |
| `SchemaOrgService` | `app/Services/SchemaOrgService.php` | Parse JSON-LD Schema.org product data |
| `PriceFetcherService` | `app/Services/PriceFetcherService.php` | Dispatches batch price update jobs |
| `Store` model | `app/Models/Store.php` | Holds domains, scrape_strategy, settings |
| `Url` model | `app/Models/Url.php` | Product URL with `updatePrice()` and `scrape()` |
| `Price` model | `app/Models/Price.php` | Historical price record |
| `Product` model | `app/Models/Product.php` | Aggregates URLs, holds `price_cache` |
| `CurrencyHelper` | `app/Services/Helpers/CurrencyHelper.php` | Price string to float conversion |
| `AppSettings` | `app/Settings/AppSettings.php` | Runtime settings (cache TTL, schedule, retries) |

### Enums

| Enum | Values | Purpose |
|------|--------|---------|
| `ScraperStrategyType` | `schema_org`, `selector`, `xpath`, `regex`, `json` | How to extract a field |
| `ScraperService` | `http`, `api` | Which scraper backend to use |
| `StockStatus` | `in_stock`, `pre_order`, `back_order`, `special_order`, `out_of_stock`, `discontinued` | Availability states |

## Store Configuration

### Domain Matching

Stores have a `domains` JSON column containing an array of `{"domain": "example.com"}` objects. The `domainFilter` scope matches a URL's host against these. Auto-created stores register both `example.com` and `www.example.com`.

### Scrape Strategy

The `scrape_strategy` JSON column defines extraction rules per field:

```php
[
    'title' => ['type' => 'schema_org', 'value' => null],
    'price' => ['type' => 'selector', 'value' => '.price-current'],
    'image' => ['type' => 'selector', 'value' => 'meta[property="og:image"]|content'],
    'availability' => ['type' => 'selector', 'value' => '.stock-status'],
]
```

Each field entry has:
- **type** - One of `ScraperStrategyType` values
- **value** - The selector/xpath/regex/json-path (null for `schema_org`)
- **prepend** / **append** (optional) - Strings to prepend/append to extracted value

### Strategy Types

**schema_org** - Extracts from JSON-LD `@type: Product`. No value needed. Looks for `offers.lowPrice`, `offers.price`, or `offers.priceSpecification.price` for price. Best option when available.

**selector** - CSS selector with special syntax:
- `.price` - Get text content of element
- `.selector|attribute` - Get attribute value (pipe delimiter). Example: `meta[property="og:image"]|content`
- `!.selector` - Get raw HTML (exclamation prefix). Example: `!.product-description`

**xpath** - XPath expression. Use `@attribute` for attributes, `text()` for text.

**regex** - Regular expression. Capture group `()` extracts the value. Example: `~"price":\s?"(.*?)"~`

**json** - JSON dot-notation path for JSON responses.

### Store Settings

The `settings` JSON column holds:
- `scraper_service` - `http` (curl, fast) or `api` (browser-based, JS rendering)
- `scraper_service_settings` - Newline-separated `key=value` pairs passed as scraper options
- `test_url` - A URL for testing the store's scrape configuration
- `locale_settings.locale` / `locale_settings.currency` - For price formatting

### Cookies

The `cookies` text column stores cookies to send with requests (useful for stores that require session/consent cookies).

### Availability Match Config

The `scrape_strategy.availability.match` config maps scraped availability values to `StockStatus` cases:

```php
'availability' => [
    'type' => 'selector',
    'value' => '.stock-label',
    'match' => [
        'default' => 'in_stock',
        'out_of_stock' => ['type' => 'match', 'value' => 'Out of Stock'],
        'pre_order' => ['type' => 'regex', 'value' => 'pre.?order'],
    ],
],
```

Match types: `match` (exact string comparison) or `regex` (pattern match). If no match config exists, any non-empty availability value maps to `OutOfStock`.

## Scraper Services

**HTTP** (`ScraperService::Http`) - Fast curl-based requests via `jez500/web-scraper-for-laravel`. Default for most stores.

**API** (`ScraperService::Api`) - Browser-based requests through a scraper API service (configured via `SCRAPER_BASE_URL` env, default `http://scraper:3000`). Use for JavaScript-rendered pages. Slower and more resource-intensive.

## Auto Store Creation

`AutoCreateStore` automatically detects scrape settings when adding a URL for a new domain. It tries extraction methods in priority order:

1. **Schema.org** JSON-LD
2. **CSS selectors** from `config/price_buddy.php` `auto_create_store_strategies`
3. **Regex patterns** from the same config

The config file at `config/price_buddy.php` contains the default selectors and regex patterns tried during auto-detection. Common selectors include `meta[property="og:title"]|content`, `.price`, `[itemProp="price"]|content`, etc.

## Retry & Caching

- `ScrapeUrl::scrape()` retries up to `max_attempts_to_scrape` times (default 3, configurable in AppSettings)
- On retry, `use_cache` is set to `false` to bypass stale cached responses
- If scrape returns `false` (no store found), retries stop immediately
- Cache TTL is controlled by `AppSettings::scrape_cache_ttl` (default 720 minutes)
- Sleep between scrapes is configurable via `AppSettings::sleep_seconds_between_scrape` (default 10 seconds)

## Price Storage

- Prices are normalized to floats via `CurrencyHelper::toFloat()` with locale/currency awareness
- `price_factor` on `Url` supports bulk items (e.g., price_factor=6 for a 6-pack gives unit_price)
- Each scrape creates a new `Price` record (historical tracking)
- `Product.price_cache` is a denormalized JSON column updated after each price change via `Product::updatePriceCache()`

## Jobs & Scheduling

- `FetchAll` command (`lando artisan app:fetch-all`) triggers `PriceFetcherService::updateAllPrices()`
- Products are chunked (default 10) and dispatched as `UpdateAllPricesJob` batches
- Each product dispatches `UpdateProductPricesJob` which calls `Product::updatePrices()`
- Schedule is controlled by `AppSettings::scrape_schedule` cron expression (default `0 6 * * *`)
- Job timeout is 20 minutes (`PriceFetcherService::JOB_TIMEOUT`)

## Debugging Scraping Issues

### Common Problems

**"No store found for URL"** - The URL's domain doesn't match any store's `domains` array. Check domain spelling, www vs non-www variants. Use `Store::query()->domainFilter($host)->first()` to test.

**"Missing title/price when scraping"** - The scrape strategy selectors don't match the page HTML. Common causes:
- Page uses JavaScript rendering but store uses HTTP scraper (switch to API)
- CSS class names changed on the retailer's site
- Page returns different HTML to bots (anti-scraping)
- Cached response is stale (retry bypasses cache automatically)

**Price extraction returns null** - The selector matches but `CurrencyHelper::toFloat()` can't parse the value. Check for currency symbols, thousands separators, or non-numeric text in the matched content.

**Availability incorrectly showing out of stock** - Check the `match` config in the store's scrape strategy. Without a match config, any non-empty availability value maps to `OutOfStock`.

### Debugging Techniques

1. **Check logs** - Scraping errors log to the `db` channel with URL context. Check the activity log in the admin UI.
2. **Test scrape in tinker** - `ScrapeUrl::new('https://example.com/product')->scrape()` returns the full result array including `body`, `errors`, and extracted fields.
3. **Test store resolution** - `ScrapeUrl::new('https://example.com/product')->getStore()` returns the matched store or null.
4. **Test auto-detection** - `AutoCreateStore::new('https://example.com/product')->strategyParse()` shows what would be auto-detected.
5. **Inspect raw HTML** - The scrape result includes `body` with the fetched HTML. Check if selectors match the actual page content.
6. **Test selector parsing** - `ScrapeUrl::parseSelector('.selector|attr')` returns the parsed array to verify selector syntax.
7. **Use the store test URL** - Each store has a `test_url` setting specifically for testing its scrape configuration in the Filament admin UI.

### Additional Resources

- **`references/scrape-strategy-examples.md`** - Detailed examples of scrape strategies for common retailer patterns

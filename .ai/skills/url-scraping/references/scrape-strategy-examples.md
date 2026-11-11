# Scrape Strategy Examples

## Schema.org (Preferred)

Many modern retailers embed JSON-LD structured data. This is the most reliable extraction method.

```php
// Store scrape_strategy
[
    'title' => ['type' => 'schema_org', 'value' => null],
    'price' => ['type' => 'schema_org', 'value' => null],
    'image' => ['type' => 'schema_org', 'value' => null],
    'availability' => ['type' => 'schema_org', 'value' => null],
]
```

Schema.org parsing (`SchemaOrgService`) looks for `@type: Product` in JSON-LD and extracts:
- **title** from `name`
- **price** from `offers.lowPrice` -> `offers.price` -> `offers.priceSpecification.price`
- **image** from `image` (string or first array element)
- **availability** from `offers.availability` (e.g., `https://schema.org/InStock`)

## CSS Selector Patterns

### Basic text extraction
```php
'price' => ['type' => 'selector', 'value' => '.product-price']
// Extracts: <span class="product-price">$29.99</span> -> "$29.99"
```

### Attribute extraction (pipe syntax)
```php
'image' => ['type' => 'selector', 'value' => 'meta[property="og:image"]|content']
// Extracts: <meta property="og:image" content="https://img.example.com/photo.jpg"> -> URL
```

### Raw HTML extraction (exclamation prefix)
```php
'description' => ['type' => 'selector', 'value' => '!.product-description']
// Extracts inner HTML instead of text content
```

### Common price selectors
```php
// Meta tag price
'meta[property="product:price:amount"]|content'
'meta[property="og:price:amount"]|content'

// Microdata
'[itemProp="price"]|content'

// Amazon
'.a-price .a-offscreen'

// Generic
'.price'
'.product-price'
'[class*="price"]'
```

## Regex Patterns

```php
// JSON embedded price
'price' => ['type' => 'regex', 'value' => '~"price":\s?"(.*?)"~']

// Price in HTML tags
'price' => ['type' => 'regex', 'value' => '~>\$(\d+(\.\d{2})?)<~']

// Amazon high-res image
'image' => ['type' => 'regex', 'value' => '~"hiRes":"(.+?)"~']
```

## Availability Match Configs

### Simple exact match
```php
'availability' => [
    'type' => 'selector',
    'value' => '.stock-status',
    'match' => [
        'out_of_stock' => ['type' => 'match', 'value' => 'Out of Stock'],
    ],
]
```

### Regex match for flexible text
```php
'availability' => [
    'type' => 'schema_org',
    'value' => null,
    'match' => [
        'default' => 'in_stock',
        'out_of_stock' => ['type' => 'regex', 'value' => 'OutOfStock|SoldOut'],
        'pre_order' => ['type' => 'regex', 'value' => 'PreOrder|PreSale'],
        'discontinued' => ['type' => 'regex', 'value' => 'Discontinued'],
    ],
]
```

### Schema.org availability values
Schema.org typically returns full URLs like `https://schema.org/InStock`. The match config should account for this:
```php
'out_of_stock' => ['type' => 'regex', 'value' => 'OutOfStock']
// Matches "https://schema.org/OutOfStock"
```

## Prepend/Append for Partial URLs

When images use relative paths:
```php
'image' => [
    'type' => 'selector',
    'value' => '.product-image img|src',
    'prepend' => 'https://example.com',
]
```

## Price Factor Usage

For bulk/multi-pack items, set `price_factor` on the `Url` model:
- A 6-pack at $12.00 with `price_factor = 6` stores `unit_price = 2.00`
- The `price` column stores the full scraped amount ($12.00)
- The `unit_price` column stores `price / price_factor`

## Testing a Store in Tinker

```php
// Full scrape test
$result = ScrapeUrl::new('https://example.com/product')->scrape();
dd($result);

// Check which store resolves
$store = ScrapeUrl::new('https://example.com/product')->getStore();

// Test auto-detection for a new domain
$auto = AutoCreateStore::new('https://newstore.com/product');
dd($auto->strategyParse());

// Test specific selector parsing
ScrapeUrl::parseSelector('.price|data-amount');
// Returns: ['.price', 'attr', ['data-amount']]

// Test availability matching
StockStatus::matchFromScrapedValue('Out of Stock', $store->scrape_strategy['availability']['match'] ?? null);
```

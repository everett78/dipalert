<?php

namespace App\Services;

use App\Dto\AvailabilityStrategyDto;
use App\Dto\FetchContext;
use App\Dto\StandardStrategyDto;
use App\Enums\FetchOutcome;
use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Enums\StockStatus;
use App\Exceptions\BlockedUrlException;
use App\Exceptions\StoreBusyException;
use App\Exceptions\StorePausedException;
use App\Models\Store;
use App\Services\Helpers\SettingsHelper;
use App\Services\Scrapers\FetchOutcomeClassifier;
use App\Services\Scrapers\PacedScraper;
use App\Services\Scrapers\ScraperFactory;
use App\Settings\AppSettings;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Jez500\WebScraperForLaravel\Exceptions\DomSelectorException;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Psr\Log\LoggerInterface;

class ScrapeUrl
{
    public const string SELECTOR_ATTR_DELIMITER = '|';

    public const string SELECTOR_HTML_PREFIX = '!';

    /**
     * For the title and image, limit the length.
     */
    public const int MAX_STR_LENGTH = 1000;

    /**
     * Splits a title on its last separator, eg the ":" in "Wireless Mouse: Amazon.com.au".
     * A dash only counts when surrounded by whitespace so hyphenated words survive.
     */
    protected const string TITLE_SEPARATOR_PATTERN = '/^(?P<head>.*\S)(?:\s*[|:»·]\s*|\s+[-–—]\s+)(?P<tail>[^|:»·]+?)\s*$/u';

    /**
     * A segment that is nothing but a hostname, eg "Amazon.com.au" or "www.kogan.com".
     */
    protected const string TITLE_DOMAIN_PATTERN = '/^(?:www\.)?[\p{L}\p{N}][\p{L}\p{N}-]*(?:\.\p{L}{2,})+$/u';

    /**
     * How many trailing noise segments to strip from a title before giving up.
     */
    protected const int TITLE_MAX_NOISE_SEGMENTS = 5;

    /**
     * Shortest title we are willing to leave behind after stripping noise.
     */
    protected const int TITLE_MIN_LENGTH = 3;

    /**
     * Marks a scrape result as a soft 404. Read via self::resolveStockStatus() rather
     * than the scraped `availability` value, which a store's match config can override.
     */
    public const string NOT_FOUND_KEY = 'not_found';

    /**
     * Titles that mean "this page does not exist". A bare "404" is deliberately NOT
     * enough: plenty of real products carry it as a model number (eg "Roland SP-404",
     * "Peugeot 404"), so it only counts alongside error wording or as the whole title.
     */
    protected const string NOT_FOUND_TITLE_PATTERN = '/\bnot\s+found\b|\bdoes\s+not\s+exist\b|\b(?:error|page)\s*[-–—:|]?\s*404\b|\b404\s*[-–—:|]?\s*(?:error|page|not)\b|^\s*404\s*$/i';

    /**
     * Body markers for a soft 404. Each is anchored to the attribute or key it belongs
     * to so a stray "template-404" in bundled CSS or JSON cannot condemn a real product
     * page.
     */
    protected const string NOT_FOUND_BODY_PATTERN = '/class=["\'][^"\']*\btemplate-404\b|data-page-type=["\']404["\']|"pageType"\s*:\s*"404"|rel=["\']canonical["\'][^>]*href=["\'][^"\']*\/404\/?["\']|href=["\'][^"\']*\/404\/?["\'][^>]*rel=["\']canonical["\']/i';

    /**
     * Result keys added by DipAlert.
     */
    public const string OUTCOME_KEY = 'outcome';

    public const string STATUS_KEY = 'status';

    public const string METHOD_KEY = 'method';

    public const string FROM_CACHE_KEY = 'from_cache';

    public const string FINAL_URL_KEY = 'final_url';

    protected PacedScraper $webScraper;

    protected FetchContext $ctx;

    protected LoggerInterface $logger;

    protected int $scraperRequestTimeout = 30;

    /**
     * Whether a caller set the request timeout (e.g. a time budget). Otherwise
     * FlareSolverr gets its own, longer client timeout.
     */
    protected bool $requestTimeoutSet = false;

    protected int $scraperConnectTimeout = 30;

    protected string $scraperService = 'api';

    protected int $maxAttempts;

    protected array $keys = [
        'title',
        'description',
        'price',
        'image',
        'availability',
    ];

    public bool $sendUiNotifications = true;

    public bool $logErrors = true;

    public function __construct(protected string $url)
    {
        // @phpstan-ignore-next-line - withContext is valid.
        $this->logger = Log::channel('db')->withContext(['url' => $url]);
        $this->maxAttempts = SettingsHelper::getSetting('max_attempts_to_scrape', 3);
        $this->ctx = FetchContext::panel();
    }

    public static function new(string $url): self
    {
        return resolve(static::class, ['url' => $url]);
    }

    public function withContext(?FetchContext $ctx): self
    {
        $this->ctx = $ctx ?? FetchContext::panel();

        return $this;
    }

    public function getContext(): FetchContext
    {
        return $this->ctx;
    }

    /**
     * The outcome of a scrape result. A result without the key (no store for the
     * address, or a result built elsewhere) is classified from what it holds.
     *
     * @param  array<string, mixed>|false|null  $result
     */
    public static function outcomeOf(array|false|null $result): FetchOutcome
    {
        $outcome = data_get($result, self::OUTCOME_KEY);

        if ($outcome instanceof FetchOutcome) {
            return $outcome;
        }

        if (is_string($outcome) && ($enum = FetchOutcome::tryFrom($outcome))) {
            return $enum;
        }

        $result = is_array($result) ? $result : [];
        $availabilityStrategy = data_get($result, 'store.scrape_strategy.availability');

        return FetchOutcomeClassifier::classify(
            is_numeric(data_get($result, self::STATUS_KEY)) ? (int) data_get($result, self::STATUS_KEY) : null,
            (string) data_get($result, 'body', ''),
            (array) data_get($result, 'errors', []),
            filled(data_get($result, 'price')),
            $result !== [] && self::resolveStockStatus($result, $availabilityStrategy)->isUnavailable(),
        );
    }

    public function setMaxAttempts(int $maxAttempts): self
    {
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    public function setLogErrors(bool $logErrors): self
    {
        $this->logErrors = $logErrors;

        return $this;
    }

    public function setSendUiNotifications(bool $sendUiNotifications): self
    {
        $this->sendUiNotifications = $sendUiNotifications;

        return $this;
    }

    public function setScraper(string $scraper, ?Store $store = null): self
    {
        $this->scraperService = $scraper;

        $requestTimeout = $scraper === ScraperService::FlareSolverr->value && ! $this->requestTimeoutSet
            ? (int) config('dipalert.flaresolverr_client_timeout_s', 70)
            : $this->getRequestTimeout();

        $this->webScraper = ScraperFactory::make($scraper, $this->url, $store, $this->ctx)
            ->setConnectTimeout($this->getConnectTimeout())
            ->setRequestTimeout($requestTimeout);

        return $this;
    }

    public function scrape(array $options = []): array
    {
        $attempt = 0;
        $output = [];
        $maxAttempts = $this->ctx->interactive ? 1 : $this->maxAttempts;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // Don't use cache if previous attempt failed.
            if ($attempt > 1) {
                $options['use_cache'] = false;
            }

            $output = $this->scrapeUrl($options);

            if ($output === false) {
                $attempt = $maxAttempts;
                $output = [];
            }

            if (! empty($output['title'])) {
                break;
            }

            // Another request right away would not help after a block, a refused
            // address or a skipped fetch (and would hit a blocking store again).
            if ($this->isFinalFailure($output)) {
                break;
            }
        }

        $availabilityStrategy = data_get($output, 'store.scrape_strategy.availability');
        $isUnavailable = self::resolveStockStatus($output, $availabilityStrategy)->isUnavailable();

        if ($output !== []) {
            $output[self::OUTCOME_KEY] = FetchOutcomeClassifier::classify(
                $output[self::STATUS_KEY] ?? null,
                (string) ($output['body'] ?? ''),
                (array) ($output['errors'] ?? []),
                filled($output['price'] ?? null),
                $isUnavailable,
            );
        }

        foreach (['price', 'title'] as $required) {
            // Skip price requirement when product is unavailable.
            if ($required === 'price' && $isUnavailable) {
                continue;
            }

            if (empty($output[$required])) {
                $this->errorLog('Error scraping URL '.$attempt.' times', [
                    'attempts' => $attempt,
                    'error' => __('Missing :field when scraping', ['field' => $required]),
                    'outcome' => data_get($output, self::OUTCOME_KEY)?->value,
                    'status' => $output[self::STATUS_KEY] ?? null,
                    'scrape_errors' => $output['errors'] ?? [],
                    'scraped_html' => $output['body'] ?? '',
                ]);
                $this->errorNotification('Missing required field: '.$required);

                return $output;
            }
        }

        return $output;
    }

    /**
     * Whether the attempt ended in a way another immediate attempt cannot fix.
     *
     * @param  array<string, mixed>  $output
     */
    protected function isFinalFailure(array $output): bool
    {
        $errors = (array) ($output['errors'] ?? []);

        foreach ([FetchOutcomeClassifier::ERROR_URL_REFUSED, FetchOutcomeClassifier::ERROR_SKIPPED, FetchOutcomeClassifier::ERROR_BUSY] as $kind) {
            if (array_key_exists($kind, $errors)) {
                return true;
            }
        }

        return FetchOutcomeClassifier::isBlocked($output[self::STATUS_KEY] ?? null, (string) ($output['body'] ?? ''));
    }

    protected function scrapeUrl(array $options = []): array|false
    {
        $store = data_get($options, 'store') ?? $this->getStore();
        $useCache = (bool) data_get($options, 'use_cache', $this->ctx->useCache);

        if (! $store) {
            $this->errorLog('No store found for URL');
            $this->errorNotification('No store found for URL');

            return false;
        }

        $output = [
            'store' => $store,
            self::METHOD_KEY => $store->scraper_service,
            self::STATUS_KEY => null,
            self::FROM_CACHE_KEY => false,
            'body' => '',
            'errors' => [],
        ];

        try {
            $this->setScraper($store->scraper_service, $store);

            $scraper = $this->webScraper->from($this->url)
                ->setCacheMinsTtl(AppSettings::new()->scrape_cache_ttl)
                ->setUseCache($useCache)
                ->setOptions($store->scraper_options);

            if ($store->cookies) {
                $scraper->setCookies($store->cookies);
            }

            $page = $scraper->get();
        } catch (BlockedUrlException $e) {
            return $this->failedOutput($output, FetchOutcomeClassifier::ERROR_URL_REFUSED, $e->getMessage(), $store);
        } catch (StorePausedException $e) {
            return $this->failedOutput($output, FetchOutcomeClassifier::ERROR_SKIPPED, $e->getMessage(), $store);
        } catch (StoreBusyException $e) {
            return $this->failedOutput($output, FetchOutcomeClassifier::ERROR_BUSY, $e->getMessage(), $store);
        } catch (Exception $e) {
            $this->errorLog('Error scraping URL', [
                'error' => $e->getMessage(),
            ]);

            return $this->failedOutput($output, FetchOutcomeClassifier::ERROR_NETWORK, $e->getMessage(), $store);
        }

        $output[self::STATUS_KEY] = $scraper->getStatusCode();
        $output[self::FROM_CACHE_KEY] = $scraper->isFromCache();
        $output[self::FINAL_URL_KEY] = $scraper->getFinalUrl();
        $output['body'] = $page->getBody();

        if ($errors = $scraper->getErrors()) {
            $output['errors'] = self::keyErrors($errors);

            $this->errorLog('Error scraping URL', [
                'store_id' => $store->getKey(),
                'errors' => $output['errors'],
            ]);
            $this->errorNotification('Error scraping URL check logs');

            return $output;
        }

        // A block page is not parsed: its title or a price on it means nothing.
        if (FetchOutcomeClassifier::isBlocked($output[self::STATUS_KEY], $output['body'])) {
            foreach ($this->keys as $key) {
                $output[$key] = null;
            }

            return $output;
        }

        try {
            $strategy = $store->scrape_strategy;

            foreach ($this->keys as $key) {
                $slot = $strategy->{$key} ?? null;
                $output[$key] = $slot instanceof StandardStrategyDto
                    ? $this->scrapeOption($page, $slot, $key)
                    : null;
            }

            $output['errors'] = $scraper->getErrors();
            $output = $this->applyNotFoundPageGuards($output);
        } catch (Exception $e) {
            $this->errorLog('Error scraping URL', [
                'error' => $e->getMessage(),
            ]);
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    protected function failedOutput(array $output, string $kind, string $message, Store $store): array
    {
        $output['errors'] = [$kind => ['message' => $message, 'code' => 0]];

        $this->errorLog('Error scraping URL', [
            'store_id' => $store->getKey(),
            'errors' => $output['errors'],
        ]);
        $this->errorNotification($message);

        return $output;
    }

    /**
     * Errors from DipAlert scrapers are keyed by kind. A plain list (other
     * scraper classes) counts as a network error.
     *
     * @param  array<int|string, mixed>  $errors
     * @return array<int|string, mixed>
     */
    public static function keyErrors(array $errors): array
    {
        if ($errors === [] || ! array_is_list($errors)) {
            return $errors;
        }

        return [FetchOutcomeClassifier::ERROR_NETWORK => $errors[0]] + $errors;
    }

    /**
     * Soft 404s still return HTML with a title and often an unrelated dollar
     * amount in banners (e.g. "Orders Over $899!"). Treat those pages as
     * discontinued and drop any scraped price so shipping thresholds are never
     * stored as product prices.
     */
    protected function applyNotFoundPageGuards(array $output): array
    {
        if (! $this->looksLikeNotFoundPage(
            (string) ($output['title'] ?? ''),
            (string) ($output['body'] ?? ''),
        )) {
            return $output;
        }

        $output['price'] = null;
        $output['availability'] = 'https://schema.org/Discontinued';
        $output[self::NOT_FOUND_KEY] = true;

        return $output;
    }

    protected function looksLikeNotFoundPage(string $title, string $body): bool
    {
        if ($title !== '' && preg_match(self::NOT_FOUND_TITLE_PATTERN, $title) === 1) {
            return true;
        }

        if ($body === '') {
            return false;
        }

        return preg_match(self::NOT_FOUND_BODY_PATTERN, $body) === 1;
    }

    /**
     * Resolve the stock status for a whole scrape result.
     *
     * A page flagged as a soft 404 is Discontinued whatever the store says. Reading the
     * injected `availability` value alone is not enough: a store with a per-status match
     * config re-interprets it, finds no rule for it and falls back to that config's
     * default (usually InStock), silently undoing the guard.
     *
     * Some stores (e.g. Amazon) drop the price element entirely when a product is out of
     * stock. A store can opt in to reading a page that has a title but no price as
     * OutOfStock, so the product can still be created and healing does not "repair" the
     * price selector onto an unrelated price on the page.
     *
     * @param  array<string, mixed>|null  $scrapeResult
     */
    public static function resolveStockStatus(?array $scrapeResult, ?AvailabilityStrategyDto $availabilityStrategy): StockStatus
    {
        if (data_get($scrapeResult, self::NOT_FOUND_KEY)) {
            return StockStatus::Discontinued;
        }

        $status = StockStatus::resolveAvailability(data_get($scrapeResult, 'availability'), $availabilityStrategy);

        $store = data_get($scrapeResult, 'store');

        if ($status === StockStatus::InStock
            && $store instanceof Store
            && $store->missing_price_out_of_stock
            && filled(data_get($scrapeResult, 'title'))
            && blank(data_get($scrapeResult, 'price'))) {
            return StockStatus::OutOfStock;
        }

        return $status;
    }

    public function getStore(): ?Store
    {
        $host = Uri::of($this->url)->host();

        if (blank($host)) {
            return null;
        }

        return Store::query()->domainFilter($host)->oldest()->first();
    }

    protected function scrapeOption(WebScraperInterface $scraper, StandardStrategyDto $options, string $field): ?string
    {
        try {
            return StrategyExtractor::extract($scraper, $options, $field, $this->url);
        } catch (DomSelectorException $e) {
            $this->errorLog('Error scraping URL', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->errorNotification($e->getMessage());
        }

        return null;
    }

    public static function getMethodFromType(string $type): string
    {
        return match ($type) {
            ScraperStrategyType::Regex->value => 'getRegex',
            ScraperStrategyType::Json->value => 'getJson',
            ScraperStrategyType::xPath->value => 'getXpath',
            ScraperStrategyType::SchemaOrg->value => 'getSchemaOrg',
            default => 'getSelector'
        };
    }

    /**
     * Wrap a user-supplied regex pattern in delimiters if it doesn't already have them.
     *
     * PHP's preg_* functions require pattern delimiters; bare patterns saved in the
     * store strategy config (e.g. `https?://schema.org/(\w+)`) raise a warning and
     * silently return no matches. Picks a delimiter that does not appear in the pattern.
     */
    public static function ensureRegexDelimiters(string $regex): string
    {
        if ($regex === '') {
            return $regex;
        }

        if (self::hasMatchingDelimiters($regex)) {
            return $regex;
        }

        foreach (['#', '~', '%', '@', '!'] as $delimiter) {
            if (! str_contains($regex, $delimiter)) {
                return $delimiter.$regex.$delimiter;
            }
        }

        return '#'.str_replace('#', '\\#', $regex).'#';
    }

    /**
     * Determine whether a pattern is already wrapped in valid PHP regex delimiters.
     *
     * Accepts a known single-char delimiter (/ # ~ % @ ! |) repeated at the end, or
     * an opening paired delimiter ( { [ < closed by its partner ) } ] > — in either
     * case optionally followed by valid modifier letters. A bare pattern that merely
     * starts with a non-alphanumeric char (e.g. `$([0-9.]+)`) is NOT treated as
     * delimited, so it gets wrapped instead of failing preg_* silently.
     */
    private static function hasMatchingDelimiters(string $regex): bool
    {
        if (strlen($regex) < 2) {
            return false;
        }

        $paired = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];
        $single = ['/', '#', '~', '%', '@', '!', '|'];

        $first = $regex[0];

        if (isset($paired[$first])) {
            $closing = $paired[$first];
        } elseif (in_array($first, $single, true)) {
            $closing = $first;
        } else {
            return false;
        }

        // Walk back over trailing modifier letters to find the closing delimiter.
        $index = strlen($regex) - 1;
        while ($index > 0 && str_contains('imsxADSUXJun', $regex[$index])) {
            $index--;
        }

        // The closing delimiter must sit past the opening one (non-empty body).
        return $index >= 1 && $regex[$index] === $closing;
    }

    public static function parseSelector(string $selector): array
    {
        // If starts with exclamation !, return unsanitized HTML.
        if (str_starts_with($selector, self::SELECTOR_HTML_PREFIX)) {
            $selector = substr($selector, 1) ?: '';

            return [$selector, 'html'];
        }

        // If contains a pipe | extract attribute.
        if (! str_contains($selector, self::SELECTOR_ATTR_DELIMITER)) {
            return [$selector, 'text'];
        }

        // We get the attribute value from the selector assuming format is
        // .selector|attribute
        $parts = explode(self::SELECTOR_ATTR_DELIMITER, $selector);
        $attr = array_pop($parts);

        return [implode(self::SELECTOR_ATTR_DELIMITER, $parts), 'attr', [$attr]];
    }

    protected function errorNotification(string $message): void
    {
        if (! $this->sendUiNotifications) {
            return;
        }

        Notification::make()
            ->title('Scrape error')
            ->body($message)
            ->danger()
            ->send();
    }

    protected function errorLog(string $message, array $data = []): void
    {
        if (! $this->logErrors) {
            return;
        }

        $this->logger->error($message, $data);
    }

    public function getRequestTimeout(): int
    {
        return $this->scraperRequestTimeout;
    }

    public function setRequestTimeout(int $scraperRequestTimeout): self
    {
        $this->scraperRequestTimeout = $scraperRequestTimeout;
        $this->requestTimeoutSet = true;

        return $this;
    }

    public function getConnectTimeout(): int
    {
        return $this->scraperConnectTimeout;
    }

    public function setConnectTimeout(int $scraperConnectTimeout): self
    {
        $this->scraperConnectTimeout = $scraperConnectTimeout;

        return $this;
    }

    /**
     * If a scraped field is greater than the max length, return null. This protects the db
     * against incorrect and long strings for url or image, both can't be cropped.
     */
    public static function preSaveMaxLength(?string $value): ?string
    {
        return $value && strlen($value) < self::MAX_STR_LENGTH ? $value : null;
    }

    /**
     * For fields that can be truncated, truncate them, eg title attribute. Like preSaveMaxLength,
     * protect the db from long strings.
     */
    public static function preSaveTruncate(?string $value): ?string
    {
        return Str::limit($value, self::MAX_STR_LENGTH);
    }

    /**
     * Strip the site name noise stores append to product titles, eg the ": Mice: Amazon.com.au"
     * in "Wireless Gaming Mouse (Black): Mice: Amazon.com.au". Only trailing segments that are
     * a bare hostname or the store's own name are removed, so product detail is never lost.
     */
    public static function preSaveCleanTitle(?string $value, ?string $storeName = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $title = trim((string) preg_replace('/\s+/u', ' ', $value));
        $storeName = $storeName === null ? null : mb_strtolower(trim($storeName));

        for ($i = 0; $i < self::TITLE_MAX_NOISE_SEGMENTS; $i++) {
            if (! preg_match(self::TITLE_SEPARATOR_PATTERN, $title, $matches)) {
                break;
            }

            if (! self::isTitleNoiseSegment($matches['tail'], $storeName)) {
                break;
            }

            if (mb_strlen($matches['head']) < self::TITLE_MIN_LENGTH) {
                break;
            }

            $title = $matches['head'];
        }

        return $title;
    }

    /**
     * Is this trailing title segment site name noise rather than product detail?
     */
    protected static function isTitleNoiseSegment(string $segment, ?string $storeName): bool
    {
        $segment = trim($segment);

        if ($storeName !== null && $storeName !== '' && mb_strtolower($segment) === $storeName) {
            return true;
        }

        return (bool) preg_match(self::TITLE_DOMAIN_PATTERN, $segment);
    }
}

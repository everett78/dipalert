<?php

namespace App\Services\Ai;

use App\Dto\FetchContext;
use App\Dto\StandardStrategyDto;
use App\Enums\ScraperService;
use App\Exceptions\PageBlockedException;
use App\Models\Store;
use App\Services\ExtractionBudget;
use App\Services\Scrapers\FetchOutcomeClassifier;
use App\Services\Scrapers\ScraperFactory;
use App\Services\StrategyExtractor;
use Illuminate\Support\Str;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use RuntimeException;
use Throwable;

class HealingContext
{
    /**
     * Max characters of HTML returned to the agent per fetch (token guard).
     * The full raw HTML is retained internally for selector validation.
     */
    protected const int RETURN_BUDGET = 40000;

    protected ?string $html;

    protected bool $usedBrowser = false;

    public readonly FetchContext $ctx;

    public function __construct(
        public readonly string $url,
        public readonly Store $store,
        ?string $initialHtml = null,
        public readonly ?ExtractionBudget $budget = null,
        ?FetchContext $ctx = null,
    ) {
        $this->html = $initialHtml;
        $this->ctx = $ctx ?? FetchContext::panel();
    }

    /**
     * The fetch method for a static or rendered page. Uses the store method; a
     * rendered page of an "http" store goes through FlareSolverr. Never the
     * SeleniumBase browser ("api"), which is not deployed.
     */
    public function serviceFor(bool $rendered): string
    {
        $service = ScraperService::tryFrom((string) $this->store->scraper_service) ?? ScraperService::Http;

        if ($rendered) {
            return ScraperService::FlareSolverr->value;
        }

        return $service === ScraperService::Api ? ScraperService::Http->value : $service->value;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    /**
     * Whether browser (JS-rendered) scraping was used to obtain a usable page.
     * Signals that the resulting store should scrape via the browser service,
     * because the static/HTTP fetch was insufficient (e.g. bot-blocked).
     */
    public function usedBrowser(): bool
    {
        return $this->usedBrowser;
    }

    /**
     * Fetch the page HTML (static or browser-rendered), retain the raw body for
     * validation, and return a model-friendly view for the agent.
     *
     * When the context carries a budget, every fetch draws from it — including the ones
     * the agent makes through FetchPageHtmlTool, which happen in this process between
     * model calls and so are not covered by the model call's own timeout. Once the
     * budget is gone the fetch throws, which ends the agent loop rather than letting it
     * keep spending on a request whose deadline has already passed.
     *
     * @throws RuntimeException when too little budget remains to start a fetch
     */
    public function fetch(bool $rendered, ?int $timeout = null): string
    {
        if ($this->budget !== null) {
            $remaining = $this->budget->remainingSecondsForTimeout();

            if ($remaining === null) {
                throw new RuntimeException('Extraction budget exhausted before fetching '.$this->url);
            }

            // An explicit timeout is an upper bound, never a way past the deadline.
            $timeout = $timeout === null ? $remaining : min($timeout, $remaining);
        }

        $service = $this->serviceFor($rendered);

        $scraper = ScraperFactory::make($service, $this->url, $this->store->exists ? $this->store : null, $this->ctx);

        if ($timeout !== null) {
            $scraper->setConnectTimeout($timeout)->setRequestTimeout($timeout);
        }

        if (! $this->ctx->useCache) {
            $scraper->setUseCache(false);
        }

        if (filled($this->store->cookies)) {
            $scraper->setCookies($this->store->cookies);
        }

        // Pacing exceptions (store paused or busy) and a refused address end the repair.
        $page = $scraper->setOptions($this->store->scraper_options)->get();

        if (FetchOutcomeClassifier::isBlocked($scraper->getStatusCode(), $page->getBody())) {
            throw new PageBlockedException('The page fetched for repair is a block page: '.$this->url);
        }

        // A failed fetch must not replace HTML already loaded (e.g. a good static
        // page when the rendered fetch fails).
        if ($page->getErrors() !== [] || blank($page->getBody())) {
            throw new RuntimeException('Could not fetch the page for repair: '.$this->url);
        }

        $this->html = $page->getBody();

        if ($rendered && $service !== $this->store->scraper_service) {
            $this->usedBrowser = true;
        }

        return $this->htmlForModel((string) $this->html);
    }

    /**
     * Build the HTML view shown to the agent. Leads with the page's structured
     * product signals (JSON-LD, title, meta tags, first h1) so they remain visible
     * even on very large pages where the head/scripts would push them past the
     * return budget, then appends a truncated copy of the raw HTML. Validation
     * always runs against the full raw HTML, so selectors are still checked for real.
     */
    protected function htmlForModel(string $raw): string
    {
        $parts = [];

        if (preg_match_all('#<script\b[^>]*type=["\']?application/ld\+json["\']?[^>]*>.*?</script>#is', $raw, $ld)) {
            $parts = array_merge($parts, $ld[0]);
        }

        if (preg_match('#<title\b[^>]*>.*?</title>#is', $raw, $title)) {
            $parts[] = $title[0];
        }

        if (preg_match_all('#<meta\b[^>]*>#i', $raw, $meta)) {
            $parts = array_merge($parts, $meta[0]);
        }

        if (preg_match('#<h1\b[^>]*>.*?</h1>#is', $raw, $h1)) {
            $parts[] = $h1[0];
        }

        $signal = Str::limit(trim(implode("\n", $parts)), (int) (self::RETURN_BUDGET * 0.6), '');
        $body = Str::limit($raw, max(0, self::RETURN_BUDGET - strlen($signal)), '');

        if ($signal === '') {
            return $body;
        }

        return "<!-- extracted product signals (JSON-LD, title, meta, h1) -->\n".$signal
            ."\n\n<!-- page HTML (truncated) -->\n".$body;
    }

    /**
     * Validate a selector/regex against the loaded HTML.
     *
     * @return array{matched: bool, value: ?string, error: ?string}
     */
    public function validate(string $type, string $value): array
    {
        if (blank($this->html)) {
            return ['matched' => false, 'value' => null, 'error' => 'No HTML loaded yet; call fetch first.'];
        }

        try {
            $dto = StandardStrategyDto::fromArray(['type' => $type, 'value' => $value]);
            $extracted = $dto ? StrategyExtractor::extract($this->scraper(), $dto, 'price', $this->url) : null;
        } catch (Throwable $e) {
            return ['matched' => false, 'value' => null, 'error' => $e->getMessage()];
        }

        return filled($extracted)
            ? ['matched' => true, 'value' => $extracted, 'error' => null]
            : ['matched' => false, 'value' => null, 'error' => 'Selector matched nothing.'];
    }

    protected function scraper(): WebScraperInterface
    {
        return WebScraper::make(ScraperService::Http->value)->setBody((string) $this->html);
    }
}

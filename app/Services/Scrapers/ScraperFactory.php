<?php

namespace App\Services\Scrapers;

use App\Dto\FetchContext;
use App\Enums\ScraperService;
use App\Models\Store;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperApi;

/**
 * The one place that builds a scraper. Every fetch (run, retry, AI repair,
 * store test, logo) gets its scraper here, wrapped in PacedScraper.
 */
class ScraperFactory
{
    public static function make(string $service, string $url, ?Store $store, FetchContext $ctx): PacedScraper
    {
        if ($service === ScraperService::FlareSolverr->value) {
            $inner = resolve(FlareSolverrWebScraper::class)
                ->setInteractive($ctx->interactive)
                ->setUrl($url);
        } else {
            // Through the facade, as before: keeps the Utf8WebScraperHttp binding
            // and lets tests swap WebScraper::make().
            $inner = WebScraper::make($service)->setUrl($url);

            if ($inner instanceof WebScraperApi) {
                $inner->setScraperApiBaseUrl(config('price_buddy.scraper_api_url', 'http://scraper:3000'));
            }
        }

        return new PacedScraper($inner, $store, $ctx);
    }
}

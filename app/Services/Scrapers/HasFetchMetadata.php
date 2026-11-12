<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Facades\Cache;

/**
 * Fetch details the package scrapers do not keep: the HTTP status, the final
 * address after redirects, whether the page came from the cache, and errors
 * keyed by kind ("network" or "url_refused").
 *
 * For classes extending Jez500\WebScraperForLaravel\AbstractWebScraper.
 */
trait HasFetchMetadata
{
    protected ?int $statusCode = null;

    protected ?string $finalUrl = null;

    protected bool $fromCache = false;

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setStatusCode(?int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getFinalUrl(): ?string
    {
        return $this->finalUrl;
    }

    public function setFinalUrl(?string $finalUrl): static
    {
        $this->finalUrl = $finalUrl;

        return $this;
    }

    public function isFromCache(): bool
    {
        return $this->fromCache;
    }

    public function setFromCache(bool $fromCache): static
    {
        $this->fromCache = $fromCache;

        return $this;
    }

    /**
     * Record an error under its kind, so the outcome can be classified later.
     */
    public function setFetchError(string $kind, string $message, int|string $code = 0): static
    {
        $this->errors[$kind] = [
            'message' => $message,
            'code' => $code,
        ];

        return $this;
    }

    /**
     * Whether a fetch now would be served from the page cache.
     */
    public function hasCachedPage(): bool
    {
        return $this->useCache === true
            && filled($this->url)
            && Cache::has($this->getCacheKey((string) $this->url));
    }

    protected function resetFetchMetadata(): void
    {
        $this->statusCode = null;
        $this->finalUrl = null;
        $this->fromCache = false;
        $this->errors = [];
        $this->body = '';
    }

    /**
     * Serve the page from the cache when allowed. Only good pages are cached,
     * so a cache hit counts as status 200.
     */
    protected function loadFromCache(): bool
    {
        if (! $this->hasCachedPage()) {
            return false;
        }

        $this->body = (string) Cache::get($this->getCacheKey((string) $this->url));
        $this->statusCode = 200;
        $this->finalUrl = $this->url;
        $this->fromCache = true;

        return true;
    }

    /**
     * Cache only 2xx responses with a body, never an error or block page.
     */
    protected function storeInCache(): void
    {
        if (! $this->useCache || $this->body === '' || $this->statusCode === null) {
            return;
        }

        if ($this->statusCode < 200 || $this->statusCode >= 300) {
            return;
        }

        Cache::put($this->getCacheKey((string) $this->url), $this->body, now()->addMinutes($this->cacheMinsTtl));
    }
}

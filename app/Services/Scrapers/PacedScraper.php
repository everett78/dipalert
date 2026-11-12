<?php

namespace App\Services\Scrapers;

use App\Dto\FetchContext;
use App\Enums\FetchSource;
use App\Exceptions\BlockedUrlException;
use App\Exceptions\StoreBusyException;
use App\Exceptions\StorePausedException;
use App\Models\FetchPacing;
use App\Models\Store;
use App\Services\Helpers\SettingsHelper;
use App\Services\Security\UrlGuard;
use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Wraps every scraper so each fetch keeps the store's pace: a minimum interval
 * between requests to one host, and a growing pause after a block. The state
 * is in the fetch_pacing table.
 */
class PacedScraper implements WebScraperInterface
{
    public const int MAX_PAUSE_LEVEL = 3;

    protected const int LOCK_SECONDS = 10;

    public function __construct(
        protected WebScraperInterface $inner,
        protected ?Store $store,
        protected FetchContext $ctx,
    ) {}

    public function inner(): WebScraperInterface
    {
        return $this->inner;
    }

    public function getContext(): FetchContext
    {
        return $this->ctx;
    }

    /**
     * @throws BlockedUrlException when the address is refused
     * @throws StorePausedException when the store is paused after a block
     * @throws StoreBusyException when a panel fetch would wait too long
     */
    public function get(): self
    {
        if (! $this->ctx->useCache) {
            $this->inner->setUseCache(false);
        }

        if ($this->ctx->useCache && $this->hasCachedPage()) {
            $this->inner->get();

            return $this;
        }

        $url = $this->inner->getUrl();

        // A refused address must not take a pacing slot or wait for one.
        UrlGuard::new()->assertAllowed($url);

        $host = FetchPacing::hostFromUrl($url);
        $due = $this->reserve($host);

        if ($due->isFuture()) {
            Sleep::until($due);
        }

        $this->inner->get();

        $this->recordResponse($host);

        return $this;
    }

    protected function hasCachedPage(): bool
    {
        return method_exists($this->inner, 'hasCachedPage') && $this->inner->hasCachedPage();
    }

    /**
     * Book the next request slot for the host. Only reads and writes the state
     * under the lock; the waiting happens after the lock is released.
     */
    protected function reserve(string $host): Carbon
    {
        return $this->withLock($host, function () use ($host): Carbon {
            $pacing = FetchPacing::forHost($host);

            if ($pacing->isPaused()) {
                throw new StorePausedException($host, $pacing->paused_until);
            }

            $now = Carbon::now();
            $due = $now->copy();

            if ($pacing->last_fetch_at !== null) {
                $next = $pacing->last_fetch_at->copy()->addSeconds($this->minIntervalSeconds());

                if ($next->greaterThan($due)) {
                    $due = $next;
                }
            }

            $wait = $now->diffInSeconds($due, true);

            if ($this->ctx->interactive && $wait > (int) config('dipalert.interactive_max_wait_seconds', 15)) {
                throw new StoreBusyException($host, (int) ceil($wait));
            }

            $pacing->last_fetch_at = $due;
            $pacing->save();

            return $due;
        });
    }

    /**
     * After a response with a status: a block raises the pause level and pauses
     * the host; any other response resets the level. No status (network error,
     * refused address) changes nothing.
     */
    protected function recordResponse(string $host): void
    {
        $status = $this->getStatusCode();

        if ($status === null) {
            return;
        }

        $blocked = FetchOutcomeClassifier::isBlocked($status, $this->inner->getBody());

        $this->withLock($host, function () use ($host, $blocked): void {
            $pacing = FetchPacing::forHost($host);

            if ($blocked) {
                $pacing->pause_level = min($pacing->pause_level + 1, self::MAX_PAUSE_LEVEL);
                $pacing->paused_until = $this->pauseEnd($pacing->pause_level);
            } else {
                $pacing->pause_level = 0;
                $pacing->paused_until = null;
            }

            $pacing->save();
        });
    }

    /**
     * Level 1: until the next scheduled run (or a short pause outside a run).
     * Level 2: until the run after it, so the next run skips the store.
     * Level 3: a long pause.
     */
    public function pauseEnd(int $level): Carbon
    {
        $now = Carbon::now();

        if ($level >= self::MAX_PAUSE_LEVEL) {
            return $now->copy()->addHours((int) config('dipalert.pause_max_hours', 24));
        }

        if ($level === 1 && $this->ctx->source !== FetchSource::Run) {
            return $now->copy()->addMinutes((int) config('dipalert.pause_outside_run_minutes', 60));
        }

        return $this->nextRunStart($now, $level - 1)
            ?? $now->copy()->addMinutes((int) config('dipalert.pause_outside_run_minutes', 60));
    }

    protected function nextRunStart(Carbon $now, int $skip): ?Carbon
    {
        $expression = (string) SettingsHelper::getSetting('scrape_schedule', '0 6 * * *');

        try {
            $next = (new CronExpression($expression))
                ->getNextRunDate($now->toDateTimeImmutable(), $skip, false, $now->getTimezone()->getName());
        } catch (Throwable) {
            return null;
        }

        return Carbon::instance($next)->setTimezone($now->getTimezone());
    }

    protected function minIntervalSeconds(): int
    {
        $interval = $this->store?->min_interval_seconds;

        return max(0, (int) ($interval ?? config('dipalert.default_min_interval_seconds', 10)));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withLock(string $host, Closure $callback): mixed
    {
        $lock = Cache::lock('pacing:'.$host, self::LOCK_SECONDS);

        try {
            $lock->block((int) config('dipalert.pacing_lock_wait_seconds', 5));
        } catch (LockTimeoutException $e) {
            if ($this->ctx->interactive) {
                throw new StoreBusyException($host, $this->minIntervalSeconds());
            }

            try {
                $lock->block(30);
            } catch (LockTimeoutException) {
                throw new StoreBusyException($host, $this->minIntervalSeconds());
            }
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function getStatusCode(): ?int
    {
        return method_exists($this->inner, 'getStatusCode') ? $this->inner->getStatusCode() : null;
    }

    public function getFinalUrl(): ?string
    {
        return method_exists($this->inner, 'getFinalUrl') ? $this->inner->getFinalUrl() : null;
    }

    public function isFromCache(): bool
    {
        return method_exists($this->inner, 'isFromCache') && $this->inner->isFromCache();
    }

    /*
     * Setters pass the call on and return the proxy, so a chain such as
     * from()->setUseCache()->get() never skips it.
     */

    public function from(string $url): self
    {
        $this->inner = $this->inner->from($url);

        return $this;
    }

    public function setUrl(string $url): self
    {
        $this->inner->setUrl($url);

        return $this;
    }

    public function setUseCache(bool $useCache): self
    {
        $this->inner->setUseCache($useCache);

        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->inner->setOptions($options);

        return $this;
    }

    public function setCacheMinsTtl(int $cacheMinsTtl): self
    {
        $this->inner->setCacheMinsTtl($cacheMinsTtl);

        return $this;
    }

    public function setConnectTimeout(int $scraperConnectTimeout): self
    {
        $this->inner->setConnectTimeout($scraperConnectTimeout);

        return $this;
    }

    public function setRequestTimeout(int $scraperRequestTimeout): self
    {
        $this->inner->setRequestTimeout($scraperRequestTimeout);

        return $this;
    }

    public function setBody(string $body): self
    {
        $this->inner->setBody($body);

        return $this;
    }

    public function setCookies(string $cookies): self
    {
        $this->inner->setCookies($cookies);

        return $this;
    }

    /*
     * Readers return the inner result.
     */

    public function getRequest(): PendingRequest
    {
        return $this->inner->getRequest();
    }

    public function buildHeaders(): array
    {
        return $this->inner->buildHeaders();
    }

    public function getUrl(): string
    {
        return $this->inner->getUrl();
    }

    public function getOptions(): array
    {
        return $this->inner->getOptions();
    }

    public function getUseCache(): bool
    {
        return $this->inner->getUseCache();
    }

    public function getCacheMinsTtl(): int
    {
        return $this->inner->getCacheMinsTtl();
    }

    public function getConnectTimeout(): int
    {
        return $this->inner->getConnectTimeout();
    }

    public function getRequestTimeout(): int
    {
        return $this->inner->getRequestTimeout();
    }

    public function getDom(): Crawler
    {
        return $this->inner->getDom();
    }

    public function getSelector(string $selector, string|Closure $nodeContent = 'text', array $nodeContentArgs = []): Collection
    {
        return $this->inner->getSelector($selector, $nodeContent, $nodeContentArgs);
    }

    public function getXpath(string $xpath, string|Closure $nodeContent = 'text', array $nodeContentArgs = []): Collection
    {
        return $this->inner->getXpath($xpath, $nodeContent, $nodeContentArgs);
    }

    public function getJson(string $path): Collection
    {
        return $this->inner->getJson($path);
    }

    public function getRegex(string $regex): Collection
    {
        return $this->inner->getRegex($regex);
    }

    public function getSchemaOrg(): Collection
    {
        return $this->inner->getSchemaOrg();
    }

    public function getErrors(): array
    {
        return $this->inner->getErrors();
    }

    public function getBody(): string
    {
        return $this->inner->getBody();
    }
}

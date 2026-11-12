<?php

namespace App\Services\Scrapers;

use App\Exceptions\BlockedUrlException;
use App\Services\Security\UrlGuard;
use Illuminate\Support\Facades\Http;
use Jez500\WebScraperForLaravel\AbstractWebScraper;
use Throwable;

/**
 * Fetches a page through FlareSolverr (a real browser), for stores that block
 * plain requests. Sends POST {FLARESOLVERR_URL}/v1 with cmd "request.get".
 */
class FlareSolverrWebScraper extends AbstractWebScraper
{
    use HasFetchMetadata;

    protected bool $interactive = false;

    public function __construct()
    {
        parent::__construct();

        $this->scraperRequestTimeout = (int) config('dipalert.flaresolverr_client_timeout_s', 70);
    }

    /**
     * The package from() returns a fresh instance; keep the mode and timeouts.
     */
    public function from(string $url): self
    {
        /** @var self $self */
        $self = parent::from($url);

        $self->setInteractive($this->interactive);
        $self->setConnectTimeout($this->getConnectTimeout());
        $self->setRequestTimeout($this->getRequestTimeout());

        return $self;
    }

    public function setInteractive(bool $interactive): self
    {
        $this->interactive = $interactive;

        return $this;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    /**
     * The browser timeout sent to FlareSolverr, in milliseconds. It stays below
     * the HTTP client timeout so FlareSolverr answers before the client gives up.
     */
    public function getMaxTimeoutMs(): int
    {
        $configured = (int) ($this->interactive
            ? config('dipalert.flaresolverr_interactive_timeout_ms', 45000)
            : config('dipalert.flaresolverr_timeout_ms', 60000));

        $clientLimit = ($this->getRequestTimeout() - 2) * 1000;

        return max(1000, min($configured, $clientLimit));
    }

    /**
     * @throws BlockedUrlException when the page address is refused
     */
    public function get(): self
    {
        $this->resetFetchMetadata();

        $guard = UrlGuard::new();
        $guard->assertAllowed((string) $this->url);

        if ($this->loadFromCache()) {
            return $this;
        }

        $endpoint = $this->endpoint();

        if ($endpoint === null) {
            $this->setFetchError(FetchOutcomeClassifier::ERROR_NETWORK, 'FlareSolverr URL is not configured (FLARESOLVERR_URL).');

            return $this;
        }

        try {
            $guard->assertServiceAllowed($endpoint);
        } catch (BlockedUrlException $e) {
            $this->setFetchError(FetchOutcomeClassifier::ERROR_NETWORK, 'FlareSolverr address is not allowed: '.$e->getMessage());

            return $this;
        }

        try {
            $response = Http::connectTimeout($this->getConnectTimeout())
                ->timeout($this->getRequestTimeout())
                ->acceptJson()
                ->post($endpoint, $this->payload());
        } catch (Throwable $e) {
            $this->setFetchError(FetchOutcomeClassifier::ERROR_NETWORK, 'FlareSolverr request failed: '.$e->getMessage(), $e->getCode());
            logger()->error($e->getMessage());

            return $this;
        }

        $json = $response->json();

        if (! is_array($json) || data_get($json, 'status') !== 'ok') {
            $message = is_array($json) ? (string) data_get($json, 'message', 'unknown error') : 'invalid response';
            $this->setFetchError(FetchOutcomeClassifier::ERROR_NETWORK, 'FlareSolverr error: '.$message, $response->status());

            return $this;
        }

        $finalUrl = (string) data_get($json, 'solution.url', $this->url);
        $this->finalUrl = $finalUrl;
        $this->statusCode = is_numeric(data_get($json, 'solution.status')) ? (int) data_get($json, 'solution.status') : null;

        if ($finalUrl !== '' && ! $guard->isAllowed($finalUrl)) {
            $this->setFetchError(FetchOutcomeClassifier::ERROR_URL_REFUSED, "Refused URL {$finalUrl}: redirected to an internal address");

            return $this;
        }

        $this->body = (string) data_get($json, 'solution.response', '');

        $this->storeInCache();

        return $this;
    }

    protected function endpoint(): ?string
    {
        $base = trim((string) config('dipalert.flaresolverr_url', ''));

        return $base === '' ? null : rtrim($base, '/').'/v1';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [
            'cmd' => 'request.get',
            'url' => $this->url,
            'maxTimeout' => $this->getMaxTimeoutMs(),
        ];

        if ($cookies = $this->cookieList()) {
            $payload['cookies'] = $cookies;
        }

        return $payload;
    }

    /**
     * Turn the store cookie header text "a=1; b=2" into the list FlareSolverr wants.
     *
     * @return list<array{name: string, value: string}>
     */
    public function cookieList(): array
    {
        $cookies = [];

        foreach (explode(';', $this->cookies) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);

            if ($name !== '') {
                $cookies[] = ['name' => $name, 'value' => trim($value)];
            }
        }

        return $cookies;
    }
}

<?php

namespace App\Services\Scrapers;

use App\Exceptions\BlockedUrlException;
use App\Services\Helpers\HtmlEncodingHelper;
use App\Services\Security\UrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Jez500\WebScraperForLaravel\WebScraperHttp;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * The package scraper, with the response body converted to UTF-8 before it is
 * cached or parsed (#203). AppServiceProvider binds it in place of WebScraperHttp.
 *
 * DipAlert additions: the address and every redirect target pass UrlGuard, the
 * status and final address are kept, errors are keyed by kind, and only good
 * pages are cached.
 */
class Utf8WebScraperHttp extends WebScraperHttp
{
    use HasFetchMetadata;

    public function getRequest(): PendingRequest
    {
        return parent::getRequest()->withOptions([
            'allow_redirects' => [
                'max' => (int) config('dipalert.max_redirects', 5),
                'strict' => false,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, UriInterface $uri): void {
                    $target = (string) $uri;
                    UrlGuard::new()->assertAllowed($target);
                    $this->finalUrl = $target;
                },
            ],
        ]);
    }

    /**
     * @throws BlockedUrlException when the address itself is refused
     */
    public function get(): self
    {
        $this->resetFetchMetadata();

        UrlGuard::new()->assertAllowed((string) $this->url);

        if ($this->loadFromCache()) {
            return $this;
        }

        $this->finalUrl = $this->url;

        try {
            $response = $this->getRequest()->get($this->url);

            $this->statusCode = $response->status();
            $this->body = HtmlEncodingHelper::toUtf8($response->body(), $response->header('Content-Type'));
        } catch (Throwable $e) {
            $blocked = self::blockedUrlFrom($e);

            if ($blocked !== null) {
                $this->setFetchError(FetchOutcomeClassifier::ERROR_URL_REFUSED, $blocked->getMessage());
                $this->finalUrl = $blocked->url;
            } else {
                $this->setFetchError(FetchOutcomeClassifier::ERROR_NETWORK, $e->getMessage(), $e->getCode());
            }

            logger()->error($e->getMessage());

            return $this;
        }

        $this->storeInCache();

        return $this;
    }

    /**
     * A refused redirect surfaces as BlockedUrlException, possibly wrapped by Guzzle.
     */
    protected static function blockedUrlFrom(Throwable $e): ?BlockedUrlException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof BlockedUrlException) {
                return $current;
            }
        }

        return null;
    }
}

<?php

namespace App\Services\Scrapers;

use App\Enums\FetchOutcome;

/**
 * Decides the outcome of a fetch. isBlocked() is the only block rule; the
 * pacing proxy uses it too.
 */
class FetchOutcomeClassifier
{
    public const string ERROR_NETWORK = 'network';

    public const string ERROR_URL_REFUSED = 'url_refused';

    public const string ERROR_SKIPPED = 'skipped';

    public const string ERROR_BUSY = 'busy';

    /**
     * A block is status 403 or 429, or a block page marker from the config. A 503
     * without a marker is a network error (usually a short outage of the store).
     */
    public static function isBlocked(?int $status, string $body): bool
    {
        if ($status === 403 || $status === 429) {
            return true;
        }

        if ($body === '') {
            return false;
        }

        foreach ((array) config('dipalert.block_markers', []) as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>  $errors  errors keyed by kind (url_refused, skipped, busy, network)
     */
    public static function classify(?int $status, string $body, array $errors, bool $priceFound, bool $markedUnavailable): FetchOutcome
    {
        if (array_key_exists(self::ERROR_URL_REFUSED, $errors)) {
            return FetchOutcome::UrlRefused;
        }

        if (array_key_exists(self::ERROR_SKIPPED, $errors) || array_key_exists(self::ERROR_BUSY, $errors)) {
            return FetchOutcome::Skipped;
        }

        if (self::isBlocked($status, $body)) {
            return FetchOutcome::Blocked;
        }

        if (array_key_exists(self::ERROR_NETWORK, $errors) || ($status !== null && $status >= 500)) {
            return FetchOutcome::NetworkError;
        }

        if (! $priceFound && ! $markedUnavailable) {
            return FetchOutcome::NoPrice;
        }

        return FetchOutcome::Ok;
    }
}

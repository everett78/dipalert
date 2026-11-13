<?php

namespace App\Actions;

use App\Enums\ScraperService;
use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use Exception;

class CreateStoreAction
{
    public function __invoke(array $attributes): ?Store
    {
        if (empty(data_get($attributes, 'settings.locale_settings.locale'))) {
            data_set($attributes, 'settings.locale_settings.locale', CurrencyHelper::getLocale());
        }

        if (empty(data_get($attributes, 'settings.locale_settings.currency'))) {
            data_set($attributes, 'settings.locale_settings.currency', CurrencyHelper::getCurrency());
        }

        if (empty(data_get($attributes, 'settings.scraper_service'))) {
            data_set($attributes, 'settings.scraper_service', ScraperService::Http->value);
        }

        // A new Ceneo store is a comparison site (offer list per store), which
        // always fetches with plain http.
        if (self::isCeneo($attributes)) {
            data_set($attributes, 'settings.comparison', Store::COMPARISON_CENEO);
            data_set($attributes, 'settings.scraper_service', ScraperService::Http->value);
        }

        try {
            return Store::create($attributes);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function isCeneo(array $attributes): bool
    {
        foreach ((array) data_get($attributes, 'domains', []) as $domain) {
            $domain = strtolower((string) (is_array($domain) ? ($domain['domain'] ?? '') : $domain));

            if ($domain === 'ceneo.pl' || $domain === 'www.ceneo.pl') {
                return true;
            }
        }

        return false;
    }
}

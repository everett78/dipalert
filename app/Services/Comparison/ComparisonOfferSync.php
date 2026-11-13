<?php

namespace App\Services\Comparison;

use App\Actions\CreateStoreAction;
use App\Dto\ComparisonOfferDto;
use App\Enums\FetchOutcome;
use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Models\Store;
use App\Models\Url;

/**
 * Turns the offer list of a comparison link into child links, one per store,
 * and saves their prices. Never opens the offer links.
 */
class ComparisonOfferSync
{
    /**
     * @param  list<ComparisonOfferDto>  $offers
     * @return int number of offers saved
     */
    public function sync(Url $parent, array $offers): int
    {
        $seen = [];
        $now = now();

        foreach ($offers as $offer) {
            $store = $this->storeFor($offer, $parent);

            if ($store === null) {
                continue;
            }

            /** @var Url $child */
            $child = Url::query()->firstOrCreate(
                ['parent_url_id' => $parent->getKey(), 'comparison_key' => $offer->shopKey],
                [
                    'product_id' => $parent->product_id,
                    'store_id' => $store->getKey(),
                    'url' => $parent->url,
                    'price_factor' => $parent->price_factor ?: 1,
                ],
            );

            if ((int) $child->store_id !== (int) $store->getKey()) {
                $child->forceFill(['store_id' => $store->getKey()])->save();
            }

            $child->updatePrice($offer->price);

            $child->forceFill([
                'last_seen_at' => $now,
                'last_outcome' => FetchOutcome::Ok->value,
                'last_attempt_at' => $now,
            ])->saveQuietly();

            $seen[] = $child->getKey();
        }

        // Offers no longer on the list are not an error, but stop being the
        // visible line of their store (no new price, no "Problems" entry).
        Url::query()
            ->where('parent_url_id', $parent->getKey())
            ->whereNotIn('id', $seen)
            ->update(['last_outcome' => FetchOutcome::NoPrice->value]);

        return count($seen);
    }

    /**
     * The store with the offer's domain, or a new one (created without
     * fetching its page; a new store queues its logo).
     */
    protected function storeFor(ComparisonOfferDto $offer, Url $parent): ?Store
    {
        $domain = $offer->shopKey;

        $store = Store::query()->domainFilter([$domain, 'www.'.$domain])->oldest()->first();

        if ($store !== null) {
            return $store;
        }

        $schemaOrg = ['type' => ScraperStrategyType::SchemaOrg->value, 'value' => null];

        return (new CreateStoreAction)([
            'user_id' => $parent->product?->user_id,
            'name' => $offer->shopName,
            'domains' => [['domain' => $domain], ['domain' => 'www.'.$domain]],
            'scrape_strategy' => ['title' => $schemaOrg, 'price' => $schemaOrg, 'image' => $schemaOrg],
            'settings' => ['scraper_service' => ScraperService::Http->value],
        ]);
    }

    /**
     * When the comparison page could not be read, its child links get the
     * outcome of the page.
     */
    public function markChildren(Url $parent, FetchOutcome $outcome): void
    {
        Url::query()
            ->where('parent_url_id', $parent->getKey())
            ->update(['last_outcome' => $outcome->value, 'last_attempt_at' => now()]);
    }
}

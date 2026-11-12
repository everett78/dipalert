<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Url;
use Illuminate\Support\Collection;

/**
 * Orders products so that consecutive products have different stores, where
 * possible. This shortens waits for a store's interval; it is not a protection
 * (PacedScraper is).
 */
class FetchScheduler
{
    /**
     * Group by the store of the first link, then take one product from each
     * group in turn.
     *
     * @param  Collection<int, Product>  $products  with "urls" loaded (or loadable)
     * @return list<int> product ids
     */
    public static function order(Collection $products): array
    {
        /** @var array<string, list<int>> $groups */
        $groups = [];

        foreach ($products as $product) {
            /** @var ?Url $first */
            $first = $product->urls->sortBy('id')->first();
            $key = $first?->store_id !== null ? 'store:'.$first->store_id : 'none';
            $groups[$key][] = (int) $product->getKey();
        }

        $ordered = [];

        while ($groups !== []) {
            foreach ($groups as $key => $ids) {
                $ordered[] = array_shift($ids);

                if ($ids === []) {
                    unset($groups[$key]);
                } else {
                    $groups[$key] = $ids;
                }
            }
        }

        return $ordered;
    }
}

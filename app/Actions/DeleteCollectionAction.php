<?php

namespace App\Actions;

use App\Models\Collection as ProductCollection;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a collection with its subcollections, in one transaction. Products
 * are detached; with $deleteProducts the products (with their price history)
 * that belong to no collection outside the deleted ones are deleted too, and
 * the rest are detached.
 */
class DeleteCollectionAction
{
    /**
     * @return array{collections: int, deleted_products: int, detached_products: int}
     */
    public function execute(ProductCollection $collection, bool $deleteProducts = false): array
    {
        return DB::transaction(function () use ($collection, $deleteProducts): array {
            $ids = $collection->children()->pluck('id')->push($collection->getKey())->map(fn ($id): int => (int) $id)->all();

            $productIds = DB::table('collection_product')
                ->whereIn('collection_id', $ids)
                ->distinct()
                ->pluck('product_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $toDelete = [];

            if ($deleteProducts && $productIds !== []) {
                $keptElsewhere = DB::table('collection_product')
                    ->whereIn('product_id', $productIds)
                    ->whereNotIn('collection_id', $ids)
                    ->distinct()
                    ->pluck('product_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $toDelete = array_values(array_diff($productIds, $keptElsewhere));
            }

            // Model events remove each product's links and price history.
            Product::query()->whereIn('id', $toDelete)->get()->each(fn (Product $product) => $product->delete());

            DB::table('collection_product')->whereIn('collection_id', $ids)->delete();

            // Children first: the parent link restricts deletion.
            ProductCollection::query()->whereIn('id', $ids)->whereNotNull('parent_id')->delete();
            ProductCollection::query()->whereIn('id', $ids)->delete();

            return [
                'collections' => count($ids),
                'deleted_products' => count($toDelete),
                'detached_products' => count($productIds) - count($toDelete),
            ];
        });
    }
}

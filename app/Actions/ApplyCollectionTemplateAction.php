<?php

namespace App\Actions;

use App\Models\Collection as ProductCollection;
use App\Models\Product;

/**
 * Copies a collection template into a product: change thresholds the product
 * does not have yet (null), and attribute keys it is missing (with an empty
 * value). Existing values are never overwritten.
 */
class ApplyCollectionTemplateAction
{
    public function execute(ProductCollection $collection, Product $product): void
    {
        $template = $collection->template;
        $changes = [];

        if ($product->notify_change_percent === null && $template->notifyChangePercent !== null) {
            $changes['notify_change_percent'] = $template->notifyChangePercent;
        }

        if ($product->notify_change_amount === null && $template->notifyChangeAmount !== null) {
            $changes['notify_change_amount'] = $template->notifyChangeAmount;
        }

        if ($product->notify_change_direction === null && $template->notifyChangeDirection !== null) {
            $changes['notify_change_direction'] = $template->notifyChangeDirection;
        }

        $attributes = (array) ($product->attributes ?? []);
        $added = false;

        foreach ($template->attributes as $field) {
            if (! array_key_exists($field['key'], $attributes)) {
                $attributes[$field['key']] = '';
                $added = true;
            }
        }

        if ($added) {
            $changes['attributes'] = $attributes;
        }

        if ($changes !== []) {
            $product->forceFill($changes)->save();
        }
    }
}

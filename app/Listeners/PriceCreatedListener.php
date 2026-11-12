<?php

namespace App\Listeners;

use App\Events\PriceCreatedEvent;
use App\Notifications\PriceAlertNotification;
use Exception;

class PriceCreatedListener
{
    public function __construct() {}

    /**
     * Rules in order: the change threshold from the previous read of this link,
     * then the existing rules (every change, target price, percent from the first
     * price). The first rule that notifies ends the check, so a new price sends
     * at most one e-mail.
     */
    public function handle(PriceCreatedEvent $event): void
    {
        // Need a product proceed.
        if (! $product = $event->price->product) {
            return;
        }

        // Need a url proceed.
        if (! $url = $event->price->url) {
            return;
        }

        if ($url->getAvailabilityStatus() !== null) {
            return;
        }

        try {
            if ($product->shouldNotifyOnThreshold($url, $event->price)
                || $product->shouldNotifyOnPriceChange($event->price)) {
                $previousPrice = (float) $url->previousPrice($event->price)?->price;
                $product->user?->notify(new PriceAlertNotification($url, $previousPrice, $event->price));
                $event->price->update(['notified' => true]);

                return;
            }

            // Notify the user if the price is within the range.
            if ($product->shouldNotifyOnPrice($event->price) && $url->shouldNotifyOnPrice($event->price)) {
                $product->user?->notify(new PriceAlertNotification($url));
                $event->price->update(['notified' => true]);
            }
        } catch (Exception $e) {
            // Log the error.
            logger()->error('Error sending price alert notification: '.$e->getMessage(), [
                'product' => $product->title,
                'product_id' => $product->getKey(),
                'url' => $event->price->url,
                'url_id' => $event->price->getKey(),
            ]);
        }
    }
}

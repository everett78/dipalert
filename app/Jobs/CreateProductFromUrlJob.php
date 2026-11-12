<?php

namespace App\Jobs;

use App\Dto\FetchContext;
use App\Models\Url;
use App\Models\User;
use App\Notifications\CreateProductFailedNotification;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates a product from a URL in the background, with AI repair. Used when a
 * panel fetch found no price (R19): the repair must not run while the request
 * waits behind the Cloudflare tunnel limit.
 */
class CreateProductFromUrlJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * A failed scrape is reported to the user. A retry would only repeat it.
     */
    public $tries = 1;

    public $timeout = 300;

    public function __construct(
        public string $url,
        public int $userId,
        public bool $createStore = true,
        public float $priceFactor = 1,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        try {
            $urlModel = Url::createFromUrl(
                url: $this->url,
                userId: $user->getKey(),
                createStore: $this->createStore,
                priceFactor: $this->priceFactor,
                ctx: FetchContext::panelQueued(),
            );
        } catch (Exception $e) {
            logger()->warning('Failed to create product from URL: '.$e->getMessage(), ['url' => $this->url]);
            $urlModel = false;
        }

        if ($urlModel === false) {
            $user->notify(new CreateProductFailedNotification($this->url));
        }
    }

    public function failed(?Throwable $exception): void
    {
        User::find($this->userId)?->notify(new CreateProductFailedNotification($this->url));
    }
}

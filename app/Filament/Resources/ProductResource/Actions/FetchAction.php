<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Jobs\UpdateAllPricesJob;
use App\Models\Product;
use Exception;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Log;

class FetchAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'fetch';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Fetch'));

        $this->successNotificationTitle(__('Prices updating in the background'));

        $this->failureNotificationTitle(__('Couldn\'t fetch the product, refer to logs'));

        $this->color('gray');

        $this->keyBindings(['mod+f']);

        $this->icon('heroicon-o-rocket-launch');

        $this->action(function (): void {
            try {
                /** @var Product $product */
                $product = $this->getRecord();

                UpdateAllPricesJob::dispatch([$product->id]);

                $this->success();
            } catch (Exception $e) {
                Log::channel('db')->error($e->getMessage());
                $this->failure();
            }
        });
    }
}

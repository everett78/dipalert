<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Jobs\UpdateAllPricesJob;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class FetchBulkAction extends BulkAction
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'fetch';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Fetch prices'));

        $this->successNotificationTitle(__('Prices updating in the background'));

        $this->failureNotificationTitle(__('Couldn\'t fetch the product, refer to logs'));

        $this->color('gray');

        $this->icon('heroicon-o-rocket-launch');

        $this->action(function (): void {
            $this->process(static function (Collection $records) {
                UpdateAllPricesJob::dispatch(
                    $records->pluck('id')->toArray()
                );
            });

            $this->success();
        });

        $this->deselectRecordsAfterCompletion();
    }
}

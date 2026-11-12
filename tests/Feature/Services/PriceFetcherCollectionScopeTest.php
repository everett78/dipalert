<?php

namespace Tests\Feature\Services;

use App\Jobs\UpdateProductPricesJob;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceFetcherService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PriceFetcherCollectionScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_only_in_inactive_collections_are_not_checked(): void
    {
        $user = User::factory()->create();

        $enabledTop = ProductCollection::factory()->create(['user_id' => $user->id]);
        $disabledTop = ProductCollection::factory()->disabled()->create(['user_id' => $user->id]);
        $enabledChildOfDisabled = ProductCollection::factory()->childOf($disabledTop)->create();

        $noCollection = Product::factory()->create(['user_id' => $user->id]);
        $inEnabled = Product::factory()->create(['user_id' => $user->id]);
        $inDisabled = Product::factory()->create(['user_id' => $user->id]);
        $inChildOfDisabled = Product::factory()->create(['user_id' => $user->id]);
        $inBoth = Product::factory()->create(['user_id' => $user->id]);

        $inEnabled->collections()->attach($enabledTop);
        $inDisabled->collections()->attach($disabledTop);
        $inChildOfDisabled->collections()->attach($enabledChildOfDisabled);
        $inBoth->collections()->attach([$disabledTop->id, $enabledTop->id]);

        $checked = Product::query()->shouldBeChecked()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$noCollection->id, $inEnabled->id, $inBoth->id], $checked);

        Bus::fake();
        PriceFetcherService::new()->updateAllPrices();

        Bus::assertBatched(function (PendingBatch $batch) use ($inDisabled, $inChildOfDisabled, $noCollection): bool {
            $ids = $batch->jobs->map(fn (UpdateProductPricesJob $job): int => $job->productId)->all();

            return in_array($noCollection->id, $ids, true)
                && ! in_array($inDisabled->id, $ids, true)
                && ! in_array($inChildOfDisabled->id, $ids, true);
        });
    }
}

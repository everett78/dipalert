<?php

namespace Tests\Feature\Actions;

use App\Actions\ApplyCollectionTemplateAction;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApplyCollectionTemplateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function collection(User $user): ProductCollection
    {
        return ProductCollection::factory()->create([
            'user_id' => $user->id,
            'template' => [
                'notify_change_percent' => 5,
                'notify_change_amount' => 50,
                'notify_change_direction' => 'both',
                'attributes' => [
                    ['key' => 'power', 'label' => 'Moc', 'type' => 'number', 'unit' => 'W'],
                    ['key' => 'colour', 'label' => 'Kolor', 'type' => 'text'],
                ],
            ],
        ]);
    }

    public function test_copies_missing_values_only(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'notify_change_percent' => 10,
            'notify_change_amount' => null,
            'notify_change_direction' => 'up',
            'attributes' => ['power' => '1500'],
        ]);

        (new ApplyCollectionTemplateAction)->execute($this->collection($user), $product);
        $product->refresh();

        $this->assertSame('10.00', $product->notify_change_percent);
        $this->assertSame('50.00', $product->notify_change_amount);
        $this->assertSame('up', $product->notify_change_direction);
        $this->assertSame(['power' => '1500', 'colour' => ''], $product->attributes);
    }

    public function test_copies_the_direction_when_missing(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id, 'notify_change_direction' => null]);

        (new ApplyCollectionTemplateAction)->execute($this->collection($user), $product);

        $this->assertSame('both', $product->fresh()->notify_change_direction);
    }

    public function test_choosing_a_collection_in_the_product_form_copies_the_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $collection = $this->collection($user);
        $product = Product::factory()->create(['user_id' => $user->id, 'notify_change_percent' => null]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['collection_ids' => [(string) $collection->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertTrue($product->collections->contains($collection));
        $this->assertSame('5.00', $product->notify_change_percent);
        $this->assertArrayHasKey('power', $product->attributes);
    }

    public function test_change_in_a_product_with_two_collections_asks_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $product = Product::factory()->create(['user_id' => $user->id, 'notify_change_percent' => 5]);
        $product->collections()->attach([
            ProductCollection::factory()->create(['user_id' => $user->id])->id,
            ProductCollection::factory()->create(['user_id' => $user->id])->id,
        ]);

        $page = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);

        $this->assertFalse($page->instance()->changesSharedSettings());

        $page->fillForm(['notify_change_percent' => 7]);

        $this->assertTrue($page->instance()->changesSharedSettings());
    }

    public function test_product_is_deleted_only_after_typing_its_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Odkurzacz X']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('deleteProduct', data: ['confirm_name' => 'Odkurzacz'])
            ->assertHasActionErrors(['confirm_name']);

        $this->assertModelExists($product);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('deleteProduct', data: ['confirm_name' => 'Odkurzacz X'])
            ->assertHasNoActionErrors();

        $this->assertModelMissing($product);
    }
}

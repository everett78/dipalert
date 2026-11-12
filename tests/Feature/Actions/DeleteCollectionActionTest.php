<?php

namespace Tests\Feature\Actions;

use App\Actions\DeleteCollectionAction;
use App\Filament\Resources\CollectionResource\Pages\ListCollections;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeleteCollectionActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected ProductCollection $top;

    protected ProductCollection $child;

    protected ProductCollection $other;

    protected Product $onlyHere;

    protected Product $alsoElsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->top = ProductCollection::factory()->create(['user_id' => $this->user->id, 'name' => 'Dom']);
        $this->child = ProductCollection::factory()->childOf($this->top)->create(['name' => 'Kuchnia']);
        $this->other = ProductCollection::factory()->create(['user_id' => $this->user->id, 'name' => 'Prezenty']);

        $this->onlyHere = Product::factory()->create(['user_id' => $this->user->id]);
        $this->alsoElsewhere = Product::factory()->create(['user_id' => $this->user->id]);

        $this->onlyHere->collections()->attach($this->child);
        $this->alsoElsewhere->collections()->attach([$this->child->id, $this->other->id]);
    }

    public function test_by_default_products_are_only_detached(): void
    {
        $result = (new DeleteCollectionAction)->execute($this->top);

        $this->assertSame(['collections' => 2, 'deleted_products' => 0, 'detached_products' => 2], $result);
        $this->assertModelMissing($this->top);
        $this->assertModelMissing($this->child);
        $this->assertModelExists($this->onlyHere);
        $this->assertModelExists($this->alsoElsewhere);
        $this->assertSame([$this->other->id], $this->alsoElsewhere->collections()->pluck('collections.id')->all());
    }

    public function test_deleting_products_keeps_those_in_another_collection(): void
    {
        $result = (new DeleteCollectionAction)->execute($this->top, deleteProducts: true);

        $this->assertSame(1, $result['deleted_products']);
        $this->assertSame(1, $result['detached_products']);
        $this->assertModelMissing($this->onlyHere);
        $this->assertModelExists($this->alsoElsewhere);
        $this->assertModelExists($this->other);
    }

    public function test_deleting_in_the_panel_requires_the_name(): void
    {
        $this->actingAs($this->user);

        Livewire::test(ListCollections::class)
            ->callTableAction('deleteCollection', $this->top, data: ['confirm_name' => 'dom'])
            ->assertHasTableActionErrors(['confirm_name']);

        $this->assertModelExists($this->top);

        Livewire::test(ListCollections::class)
            ->callTableAction('deleteCollection', $this->top, data: ['confirm_name' => 'Dom'])
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($this->top);
        $this->assertModelMissing($this->child);
    }

    public function test_others_collections_are_not_listed(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListCollections::class)->assertCanNotSeeTableRecords([$this->top, $this->child]);
    }
}

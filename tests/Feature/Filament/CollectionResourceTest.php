<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CollectionResource\Pages\CreateCollection;
use App\Filament\Resources\CollectionResource\Pages\EditCollection;
use App\Models\Collection as ProductCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_a_subcollection_with_a_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $parent = ProductCollection::factory()->create(['user_id' => $user->id, 'name' => 'Dom']);

        Livewire::test(CreateCollection::class)
            ->fillForm([
                'name' => 'Odkurzacze',
                'parent_id' => $parent->id,
                'icon' => 'heroicon-o-home',
                'enabled' => true,
                'template' => [
                    'notify_change_percent' => 5,
                    'notify_change_direction' => 'down',
                    'attributes' => [
                        ['key' => 'power', 'label' => 'Moc', 'type' => 'number', 'unit' => 'W'],
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $collection = ProductCollection::query()->where('name', 'Odkurzacze')->firstOrFail();

        $this->assertSame($user->id, $collection->user_id);
        $this->assertSame($parent->id, $collection->parent_id);
        $this->assertSame(5.0, $collection->template->notifyChangePercent);
        $this->assertSame('power', $collection->template->attributes[0]['key']);

        Livewire::test(EditCollection::class, ['record' => $collection->getRouteKey()])
            ->assertFormSet([
                'template.notify_change_percent' => 5.0,
                'template.notify_change_direction' => 'down',
            ]);
    }

    public function test_another_users_collection_cannot_be_opened(): void
    {
        $this->actingAs(User::factory()->create());
        $collection = ProductCollection::factory()->create();

        $this->get(\App\Filament\Resources\CollectionResource::getUrl('edit', ['record' => $collection]))->assertNotFound();
    }
}

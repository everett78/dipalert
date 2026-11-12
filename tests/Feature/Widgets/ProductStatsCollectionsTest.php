<?php

namespace Tests\Feature\Widgets;

use App\Filament\Widgets\ProductStats;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductStatsCollectionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function product(string $title): Product
    {
        return Product::factory()
            ->addUrlWithPrices('https://shop-'.uniqid().'.example/p', [100, 90])
            ->create(['user_id' => $this->user->id, 'title' => $title, 'favourite' => true]);
    }

    public function test_groups_by_collections_by_default(): void
    {
        $home = ProductCollection::factory()->create(['user_id' => $this->user->id, 'name' => 'Dom', 'description' => 'Rzeczy do domu']);
        $kitchen = ProductCollection::factory()->childOf($home)->create(['name' => 'Kuchnia']);
        $disabled = ProductCollection::factory()->disabled()->create(['user_id' => $this->user->id, 'name' => 'Wyłączona']);

        $direct = $this->product('Lampa');
        $inKitchen = $this->product('Czajnik');
        $inBoth = $this->product('Robot');
        $loose = $this->product('Słuchawki');

        $direct->collections()->attach($home);
        $inKitchen->collections()->attach($kitchen);
        $inBoth->collections()->attach([$home->id, $disabled->id]);

        $groups = ProductStats::getCollectionGroups();

        $this->assertSame('collections', ProductStats::grouping());
        $this->assertCount(1, $groups['collections'], 'Only active top-level collections');
        $this->assertSame('Dom', $groups['collections'][0]['collection']->name);
        $this->assertSame(1, $groups['collections'][0]['children'][0]['count']);
        $this->assertEqualsCanonicalizing([$direct->id, $inBoth->id], $groups['collections'][0]['products']->pluck('id')->all());
        $this->assertSame([$loose->id], $groups['uncollected']->pluck('id')->all());

        Livewire::test(ProductStats::class)
            ->assertSee('Dom')
            ->assertSee('Rzeczy do domu')
            ->assertSee('Kuchnia')
            ->assertSee('Bez kolekcji')
            ->assertSee('Słuchawki');
    }
}

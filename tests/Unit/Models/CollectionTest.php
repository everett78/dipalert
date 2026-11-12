<?php

namespace Tests\Unit\Models;

use App\Dto\CollectionTemplateDto;
use App\Models\Collection as ProductCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subcollection_cannot_have_children(): void
    {
        $top = ProductCollection::factory()->create();
        $child = ProductCollection::factory()->childOf($top)->create();

        $this->expectException(InvalidArgumentException::class);

        ProductCollection::factory()->childOf($child)->create();
    }

    public function test_a_collection_with_children_cannot_get_a_parent(): void
    {
        $top = ProductCollection::factory()->create();
        ProductCollection::factory()->childOf($top)->create();
        $other = ProductCollection::factory()->create(['user_id' => $top->user_id]);

        $this->expectException(InvalidArgumentException::class);

        $top->update(['parent_id' => $other->id]);
    }

    public function test_active_scope(): void
    {
        $enabledTop = ProductCollection::factory()->create();
        $disabledTop = ProductCollection::factory()->disabled()->create();
        $childOfEnabled = ProductCollection::factory()->childOf($enabledTop)->create();
        $childOfDisabled = ProductCollection::factory()->childOf($disabledTop)->create();
        $disabledChild = ProductCollection::factory()->childOf($enabledTop)->disabled()->create();

        $active = ProductCollection::query()->active()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$enabledTop->id, $childOfEnabled->id], $active);
        $this->assertFalse($childOfDisabled->fresh()->isActive());
        $this->assertFalse($disabledChild->fresh()->isActive());
    }

    public function test_slug_follows_the_name(): void
    {
        $collection = ProductCollection::factory()->create(['name' => 'Odkurzacze pionowe']);

        $this->assertSame('odkurzacze-pionowe', $collection->slug);
    }

    public function test_template_is_a_dto(): void
    {
        $collection = ProductCollection::factory()->create(['template' => [
            'notify_change_percent' => '5',
            'notify_change_direction' => 'both',
            'attributes' => [
                ['key' => 'power', 'label' => 'Moc', 'type' => 'number', 'unit' => 'W'],
                ['key' => '', 'label' => 'ignored'],
            ],
        ]]);

        $template = $collection->fresh()->template;

        $this->assertInstanceOf(CollectionTemplateDto::class, $template);
        $this->assertSame(5.0, $template->notifyChangePercent);
        $this->assertNull($template->notifyChangeAmount);
        $this->assertSame('both', $template->notifyChangeDirection);
        $this->assertSame([['key' => 'power', 'label' => 'Moc', 'type' => 'number', 'unit' => 'W']], $template->attributes);
    }
}

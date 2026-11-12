<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProductResource;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use App\Models\Tag;
use App\Services\Dashboard\DashboardLayoutService;
use App\Services\Dashboard\DashboardSections;
use App\Services\Helpers\SettingsHelper;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class ProductStats extends Widget
{
    protected static ?int $sort = 10;

    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.product-stats-grouped';

    public static function getProductsGrouped(): array
    {
        return Product::query()
            ->currentUser()
            ->favourite()
            ->published()
            ->with('tags')
            ->orderBy('weight')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Product $product) => isset($product->price_cache[0]))
            ->groupBy(fn (Product $product): string => self::categorySignature($product->tags))
            ->map(fn (Collection $products): array => [
                'heading' => self::categoryHeading($products->first()->tags),
                'signature' => self::categorySignature($products->first()->tags),
                'products' => $products,
                'weight' => $products->first()->tags->count() > 0
                    ? $products->first()->tags->max('weight')
                    : 0,
            ])
            ->sortBy('weight')
            ->toArray();
    }

    public static function categorySignature(\Illuminate\Support\Collection $tags): string
    {
        return $tags->isEmpty()
            ? 'uncategorized'
            : $tags->pluck('id')->sort()->values()->implode('-');
    }

    /**
     * Deterministic display heading for a tag-set, so the same combination of
     * tags always produces one group with a stable label regardless of the
     * order tags were attached in.
     */
    public static function categoryHeading(\Illuminate\Support\Collection $tags): string
    {
        return $tags->isEmpty()
            ? 'Uncategorized'
            : $tags->sortBy('name')->pluck('name')->implode(', ');
    }

    public const string GROUP_COLLECTIONS = 'collections';

    public const string GROUP_TAGS = 'tags';

    public static function grouping(): string
    {
        return SettingsHelper::getSetting('dashboard_grouping', self::GROUP_COLLECTIONS) === self::GROUP_TAGS
            ? self::GROUP_TAGS
            : self::GROUP_COLLECTIONS;
    }

    /**
     * Products shown on the dashboard (favourite, published, with a price).
     *
     * @return Collection<int, Product>
     */
    protected static function dashboardProducts(): Collection
    {
        return Product::query()
            ->currentUser()
            ->favourite()
            ->published()
            ->with(['tags', 'collections'])
            ->orderBy('weight')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Product $product) => isset($product->price_cache[0]))
            ->values();
    }

    /**
     * Groups by collection: active top-level collections (a card with the icon
     * or image and the description) with their subcollections and product
     * counts; products attached straight to the top-level collection in
     * "Pozostałe"; products without a collection in "Bez kolekcji". A product
     * in several collections shows in each.
     *
     * @return array{collections: list<array<string, mixed>>, uncollected: Collection<int, Product>}
     */
    public static function getCollectionGroups(): array
    {
        $products = self::dashboardProducts();

        $collections = ProductCollection::query()
            ->currentUser()
            ->topLevel()
            ->active()
            ->with(['children' => fn ($query) => $query->where('enabled', true)->withCount('products')])
            ->orderBy('weight')
            ->orderBy('name')
            ->get();

        $groups = $collections->map(function (ProductCollection $collection) use ($products): array {
            return [
                'collection' => $collection,
                'children' => $collection->children->map(fn (ProductCollection $child): array => [
                    'collection' => $child,
                    'count' => (int) $child->products_count,
                    'url' => ProductResource::getUrl('index').'?tableFilters[collections][values][0]='.$child->getKey(),
                ])->all(),
                'products' => $products
                    ->filter(fn (Product $product): bool => $product->collections->contains('id', $collection->getKey()))
                    ->values(),
            ];
        })->all();

        return [
            'collections' => $groups,
            'uncollected' => $products->filter(fn (Product $product): bool => $product->collections->isEmpty())->values(),
        ];
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $layout = new DashboardLayoutService($user);
        $sectionsService = new DashboardSections($user);
        $grouping = self::grouping();

        $groups = $grouping === self::GROUP_TAGS
            ? $this->orderGroups(self::getProductsGrouped(), $layout)
            : [];

        return [
            'grouping' => $grouping,
            'collectionGroups' => $grouping === self::GROUP_COLLECTIONS ? self::getCollectionGroups() : null,
            'groups' => $groups,
            'sections' => $layout->sections(),
            'sectionData' => [
                'stat_bar' => $sectionsService->statBar(),
                'buy_now' => $sectionsService->buyNow(),
                'recently_dropped' => $sectionsService->recentlyDropped(),
                'needs_attention' => $sectionsService->needsAttention(),
            ],
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function orderGroups(array $groups, DashboardLayoutService $layout): array
    {
        $order = $layout->categoryOrder();

        $groups = collect($groups)->map(function (array $group) use ($layout): array {
            $group['collapsed'] = $layout->isCategoryCollapsed($group['signature']);

            return $group;
        });

        if ($order === []) {
            return $groups->values()->all(); // already weight-sorted by getProductsGrouped()
        }

        $rank = array_flip($order); // signature => index

        return $groups
            ->sortBy(fn (array $group): int => $rank[$group['signature']] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorderProducts(array $orderedIds): void
    {
        $owned = Product::query()
            ->where('user_id', auth()->id())
            ->whereIn('id', $orderedIds)
            ->pluck('id')
            ->all();

        $ownedIds = array_map('intval', $owned);

        DB::transaction(function () use ($orderedIds, $ownedIds): void {
            $weight = 0;
            foreach ($orderedIds as $id) {
                if (! in_array((int) $id, $ownedIds, true)) {
                    continue;
                }
                Product::query()
                    ->where('user_id', auth()->id())
                    ->where('id', $id)
                    ->update(['weight' => $weight]);
                $weight++;
            }
        });
    }

    /**
     * @param  array<int, string>  $orderedSignatures
     */
    public function reorderCategories(array $orderedSignatures): void
    {
        (new DashboardLayoutService(auth()->user()))->setCategoryOrder($orderedSignatures);
    }

    /**
     * Move a product into another category by replacing its tags with the
     * destination group's tag-set, then persist the destination ordering.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function moveProductToCategory(int $productId, string $targetSignature, array $orderedIds): void
    {
        $product = Product::query()
            ->where('user_id', auth()->id())
            ->find($productId);

        if (! $product instanceof Product) {
            return;
        }

        $product->tags()->sync($this->tagIdsForSignature($targetSignature));

        $this->reorderProducts($orderedIds);
    }

    /**
     * Resolve a category signature (sorted tag IDs joined by '-', or the
     * 'uncategorized' sentinel) to the current user's matching tag IDs.
     *
     * @return array<int, int>
     */
    private function tagIdsForSignature(string $signature): array
    {
        if ($signature === 'uncategorized' || $signature === '') {
            return [];
        }

        $ids = array_map('intval', explode('-', $signature));

        return Tag::query()
            ->where('user_id', auth()->id())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Re-render when the page's Customize modal changes section visibility.
     */
    #[On('dashboard-sections-updated')]
    public function refreshSections(): void {}

    public function toggleCategoryCollapse(string $signature): void
    {
        (new DashboardLayoutService(auth()->user()))->toggleCategoryCollapse($signature);
    }
}

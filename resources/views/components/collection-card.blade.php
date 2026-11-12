@props(['group'])
@php
    /** @var \App\Models\Collection $collection */
    $collection = $group['collection'];
    $imageUrl = filled($collection->image_path) ? \Illuminate\Support\Facades\Storage::disk('public')->url($collection->image_path) : null;
@endphp
<div class="mb-8" data-collection="{{ $collection->id }}" wire:key="col-{{ $collection->id }}">
    <div class="mb-4 flex items-start gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="" class="h-12 w-12 rounded-lg object-cover" />
        @elseif ($collection->icon)
            <x-filament::icon :icon="$collection->icon" class="h-10 w-10 text-primary-500" />
        @else
            <x-filament::icon icon="heroicon-o-rectangle-group" class="h-10 w-10 text-gray-400" />
        @endif
        <div class="min-w-0">
            <h3 class="text-xl md:text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $collection->name }}</h3>
            @if (filled($collection->description))
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $collection->description }}</p>
            @endif
        </div>
    </div>

    @if (! empty($group['children']))
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($group['children'] as $child)
                <a href="{{ $child['url'] }}" class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-gray-950/5 hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-white/5">
                    <x-filament::icon :icon="$child['collection']->icon ?: 'heroicon-o-folder'" class="h-5 w-5 text-gray-400" />
                    <span class="flex-1 truncate font-medium">{{ $child['collection']->name }}</span>
                    <x-filament::badge color="gray">{{ $child['count'] }}</x-filament::badge>
                </a>
            @endforeach
        </div>
    @endif

    @if ($group['products']->isNotEmpty())
        @if (! empty($group['children']))
            <h4 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('Pozostałe') }}</h4>
        @endif
        <div class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($group['products'] as $product)
                <div wire:key="col-{{ $collection->id }}-prod-{{ $product->id }}">
                    <x-product-card :product="$product" :draggable="false" />
                </div>
            @endforeach
        </div>
    @endif
</div>

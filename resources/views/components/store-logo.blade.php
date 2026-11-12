@props(['store' => null, 'storeId' => null, 'withName' => false, 'size' => 20])
@php
    /** @var ?\App\Models\Store $store */
    $store ??= $storeId ? \App\Models\Store::query()->find($storeId) : null;
    $logoUrl = $store?->logo_url;
@endphp
@if ($store)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 align-middle']) }}>
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="" width="{{ $size }}" height="{{ $size }}"
                 style="width: {{ $size }}px; height: {{ $size }}px; object-fit: contain" class="rounded-sm" />
        @else
            <span class="inline-flex items-center justify-center rounded-sm text-[10px] font-bold"
                  style="width: {{ $size }}px; height: {{ $size }}px; background: rgba(var(--gray-300), .3)">{{ $store->initials }}</span>
        @endif
        @if ($withName)
            <span>{{ $store->name }}</span>
        @endif
    </span>
@endif

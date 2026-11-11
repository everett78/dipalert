@php
    $options = $options ?? (object) [];
    $cachedData = $cachedData ?? [];
    $type = $type ?? 'line';
    $maxHeight = $maxHeight ?? null;
@endphp
<div
    x-data="pbChart({
            cachedData: @js($cachedData),
            options: @js($options),
            type: @js($type),
        })"
>
    <canvas
        x-ref="canvas"
        @if ($maxHeight)
            style="max-height: {{ $maxHeight }}"
        @endif
    ></canvas>
</div>

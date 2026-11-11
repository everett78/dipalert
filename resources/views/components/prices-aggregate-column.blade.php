@php
    use App\Enums\Icons;
    use Filament\Support\Colors\Color;

    if (empty($items) && is_callable($getState)) {
        $items = $getState();
    }

    $color = Color::Gray;

    $iconMap = [
        'min' => Icons::Min->value,
        'max' => Icons::Max->value,
        'avg' => Icons::TrendNone->value,
    ];
@endphp
@if ($items)
    <ul class="my-2 text-sm min-w-0 max-w-md w-36"
        style="{{ Filament\Support\get_color_css_variables($color, shades: [400, 600]) }}">
        @foreach($items as $name => $price)
            <li class="text-custom-600 dark:text-custom-400 whitespace-nowrap flex gap-2">
                <x-filament::icon :icon="$iconMap[$name]" class="w-4"/>
                <span>{{ ucwords($name) }}</span> <span>({{ $price }})</span>

            </li>
        @endforeach
    </ul>
@endif


@php
    use Illuminate\Support\Str;
@endphp
<x-filament-panels::page class="fi-dashboard-page" xmlns:x-filament="http://www.w3.org/1999/html">
    <div>


        @foreach($about as $name => $items)
            <x-filament::section :heading="Str::of($name)->replace('_', ' ')->title()" class="mb-4">
                @foreach($items as $key => $val)
                    @include('filament.pages.status.item', ['key' => $key, 'val' => $val])
                @endforeach
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>



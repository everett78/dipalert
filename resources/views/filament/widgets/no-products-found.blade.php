<x-filament-widgets::widget>
    <x-filament::section>
        <h2 class="font-black text-2xl mb-5">
            {{ $heading }}
        </h2>
        <p class="text-md mb-6 text-gray-500 dark:text-gray-400">
            {{ $description }}
        </p>
        <x-filament::button
            tag="a"
            icon="heroicon-m-plus-circle"
            href="{{ $cta_url }}"
            size="lg"
        >
            {{ $cta_text }}
        </x-filament::button>
    </x-filament::section>
</x-filament-widgets::widget>

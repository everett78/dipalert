<x-filament-widgets::widget>

    <form wire:submit.prevent="submit">
        <div class="mb-8">
            {{ $this->form }}
            <p class="text-gray-500 fi-color-gray mt-2">{{ __('The domain of the URL must be in the list of available stores') }}</p>
        </div>

        <x-filament::button wire:loading.attr="disabled" type="submit" wire:loading.class="hidden">
            Add URL
        </x-filament::button>

        <div wire:loading>
            Looking for prices...
        </div>
    </form>
</x-filament-widgets::widget>

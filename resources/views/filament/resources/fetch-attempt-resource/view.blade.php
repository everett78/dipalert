@php
    /** @var \App\Models\FetchAttempt $record */
    $record = $this->getRecord();
    $page = $this->getSavedPage();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('Result') }}</dt><dd>{{ $record->outcome->getLabel() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Time') }}</dt><dd>{{ $record->created_at?->format('Y-m-d H:i:s') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Store') }}</dt><dd>{{ $record->store?->name ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Method') }}</dt><dd>{{ $record->method ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('HTTP status') }}</dt><dd>{{ $record->http_status ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Source') }}</dt><dd>{{ $record->source->value }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500">URL</dt><dd class="break-all">{{ $record->url?->url ?? '—' }}</dd></div>
            @if (filled($record->final_url) && $record->final_url !== $record->url?->url)
                <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Final address') }}</dt><dd class="break-all">{{ $record->final_url }}</dd></div>
            @endif
            @if (filled($record->message))
                <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Message') }}</dt><dd class="break-all">{{ $record->message }}</dd></div>
            @endif
        </dl>
    </x-filament::section>

    @if ($page !== null)
        <x-filament::section :heading="__('Saved page')">
            {{-- Sandboxed without allow-scripts: the store page can never run code in the panel. --}}
            <iframe
                sandbox
                referrerpolicy="no-referrer"
                srcdoc="{{ $page }}"
                class="h-[70vh] w-full rounded border border-gray-200 bg-white dark:border-white/10"
                data-test="saved-page"
            ></iframe>
        </x-filament::section>
    @else
        <x-filament::section>
            <p class="text-sm text-gray-500">{{ __('No saved page for this attempt.') }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>

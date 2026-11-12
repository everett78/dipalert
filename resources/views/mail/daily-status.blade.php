@php
    use App\Services\DailyStatusReport;
    $runLabels = [
        DailyStatusReport::RUN_OK => 'zakończony',
        DailyStatusReport::RUN_NOT_STARTED => 'NIE ZACZĄŁ SIĘ',
        DailyStatusReport::RUN_NOT_FINISHED => 'NIE ZAKOŃCZYŁ SIĘ',
        DailyStatusReport::RUN_IN_PROGRESS => 'w toku',
    ];
    $sources = ['run' => 'przebiegi', 'due' => 'własny interwał', 'retry' => 'ponowienia', 'panel' => 'panel'];
@endphp
<x-mail::message>
# {{ $report['subject'] }}

Okres: {{ $report['from']->format('Y-m-d H:i') }} – {{ $report['to']->format('Y-m-d H:i') }}

## Przebiegi

@forelse ($report['runs'] as $run)
- **{{ $run['at']->format('Y-m-d H:i') }}** — {{ $runLabels[$run['status']] ?? $run['status'] }}@if ($run['total'] !== null) (zadania: {{ $run['total'] }}, nieudane: {{ $run['failed'] }})@endif

@empty
Brak przebiegów w harmonogramie w tym okresie.
@endforelse

## Odczyty

Wszystkie próby: **{{ $report['totals']['all'] }}**
@foreach ($report['totals']['by_outcome'] as $label => $count)
- {{ $label }}: {{ $count }}
@endforeach

@if ($report['totals']['by_source'] !== [])
Według źródła:
@foreach ($report['totals']['by_source'] as $source => $count)
- {{ $sources[$source] ?? $source }}: {{ $count }}
@endforeach
@endif

@if (! empty($report['fallbackOnly']))
## Działa tylko zapas

@foreach ($report['fallbackOnly'] as $row)
- {{ $row['product'] }} — {{ $row['store'] }} (oferta z porównywarki, link bezpośredni nie działa)
@endforeach
@endif

@if ($report['paused'] !== [])
## Wstrzymane sklepy

@foreach ($report['paused'] as $paused)
- **{{ $paused['host'] }} wstrzymane do {{ $paused['until']?->format('Y-m-d H:i') }}**
@endforeach
@endif

@if ($report['problems'] !== [] || $report['skippedStores'] !== [])
## Problemy ({{ $report['issueCount'] }})

@foreach ($report['problems'] as $label => $links)
**{{ $label }}**
@foreach ($links as $problem)
- {{ $problem['product'] }} — @if (! empty($problem['store_logo']) && isset($message))<img src="{{ $message->embed($problem['store_logo']) }}" alt="" width="16" height="16" style="width: 16px; height: 16px; vertical-align: middle" /> @endif{{ $problem['store'] }}
@endforeach

@endforeach
@foreach ($report['skippedStores'] as $store => $count)
- pominięte (przerwa sklepu): {{ $store }}, linki: {{ $count }}
@endforeach

<x-mail::button :url="$problemsUrl">
Strona „Problemy”
</x-mail::button>
@endif

## Stan

- Najstarsze zadanie w kolejce: {{ $report['oldestQueuedJob'] === null ? 'brak' : $report['oldestQueuedJob'].' min' }}
- Ostatnia kopia bazy: {{ $report['backup']['at']?->format('Y-m-d H:i') ?? 'brak' }}@if ($report['backup']['size'] !== null) ({{ number_format($report['backup']['size'] / 1048576, 1, ',', ' ') }} MB)@endif

- FlareSolverr: {{ ['up' => 'odpowiada', 'down' => 'NIE ODPOWIADA', 'not_configured' => 'nie skonfigurowany'][$report['flareSolverr']['status']] ?? $report['flareSolverr']['status'] }}
- Wersja: {{ $report['version'] }}
</x-mail::message>

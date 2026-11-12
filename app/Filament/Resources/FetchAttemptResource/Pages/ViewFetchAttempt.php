<?php

namespace App\Filament\Resources\FetchAttemptResource\Pages;

use App\Filament\Resources\FetchAttemptResource;
use App\Models\FetchAttempt;
use App\Services\FailedPageStore;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewFetchAttempt extends ViewRecord
{
    protected static string $resource = FetchAttemptResource::class;

    protected static string $view = 'filament.resources.fetch-attempt-resource.view';

    public function getTitle(): string|Htmlable
    {
        /** @var FetchAttempt $record */
        $record = $this->getRecord();

        return $record->outcome->getLabel().': '.($record->url->product->title ?? '—');
    }

    public function getSavedPage(): ?string
    {
        /** @var FetchAttempt $record */
        $record = $this->getRecord();

        return FailedPageStore::get($record->page_path);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadHtml')
                ->label(__('Download HTML'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->getSavedPage() !== null)
                ->action(function (): StreamedResponse {
                    /** @var FetchAttempt $record */
                    $record = $this->getRecord();
                    $html = (string) $this->getSavedPage();

                    return response()->streamDownload(
                        function () use ($html): void {
                            echo $html;
                        },
                        FetchAttemptResource::downloadName($record),
                        ['Content-Type' => 'application/octet-stream'],
                    );
                }),
        ];
    }
}

<?php

namespace App\Filament\Resources\LogMessageResource\Pages;

use App\Filament\Resources\LogMessageResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewLogMessage extends ViewRecord
{
    protected static string $resource = LogMessageResource::class;

    protected static string $view = 'filament.pages.log_message.view';

    public function getTitle(): string|Htmlable
    {
        // @phpstan-ignore-next-line - This is a dynamic property defined in external package.
        return $this->record->message;
    }
}

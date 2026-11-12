<?php

namespace App\Filament\Resources\FetchAttemptResource\Pages;

use App\Filament\Resources\FetchAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListFetchAttempts extends ListRecords
{
    protected static string $resource = FetchAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

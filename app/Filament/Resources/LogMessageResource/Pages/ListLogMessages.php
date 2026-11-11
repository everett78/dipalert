<?php

namespace App\Filament\Resources\LogMessageResource\Pages;

use App\Filament\Resources\LogMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListLogMessages extends ListRecords
{
    protected static string $resource = LogMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

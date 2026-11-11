<?php

namespace App\Filament\Resources\LogMessageResource\Pages;

use App\Filament\Resources\LogMessageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLogMessage extends EditRecord
{
    protected static string $resource = LogMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

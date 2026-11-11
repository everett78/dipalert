<?php

namespace App\Filament\Resources\StoreResource\Actions;

use App\Filament\Actions\CreateAction as Action;

class CreateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'add_store';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resourceName('store')
            ->resourceUrl('create');
    }
}

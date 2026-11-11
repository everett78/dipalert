<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Filament\Actions\CreateAction as Action;

class CreateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'add_product';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resourceName('product')
            ->resourceUrl('create');
    }
}

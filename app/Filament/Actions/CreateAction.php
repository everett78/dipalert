<?php

namespace App\Filament\Actions;

use App\Enums\Icons;

class CreateAction extends BaseAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(Icons::Add->value);

        if ($this->resourceName) {
            $this->resourceUrl('create');
        }
    }
}

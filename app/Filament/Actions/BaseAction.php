<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BaseAction extends Action
{
    const DEFAULT_COLOR = 'gray';

    const ROUTE_NAMESPACE = 'filament.admin.resources.';

    protected ?string $resourceName = null;

    public function resourceName(string $resourceName): self
    {
        $this->resourceName = $resourceName;

        return $this;
    }

    public function resourceUrl(string $action, ?Model $record = null): self
    {
        return $this->url(route(self::ROUTE_NAMESPACE.Str::pluralStudly($this->resourceName).'.'.$action, ($record ? ['record' => $record] : []), false));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->color(self::DEFAULT_COLOR);
    }
}

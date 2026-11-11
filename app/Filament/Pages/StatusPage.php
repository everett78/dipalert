<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;

class StatusPage extends Page
{
    protected static string $routePath = '/status';

    protected static ?string $title = 'Status';

    protected static string $view = 'filament.pages.status.index';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRoutePath(): string
    {
        return static::$routePath;
    }

    /**
     * @return int | string | array<string, int | string | null>
     */
    public function getColumns(): int|string|array
    {
        return 1;
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? __('filament-panels::pages/dashboard.title');
    }

    public function getViewData(): array
    {
        $data = parent::getViewData();

        Artisan::call('about', ['--json' => true]);
        $items = json_decode(Artisan::output(), true);
        $data['about'] = ['price_buddy' => $items['price_buddy']] + $items;

        return $data;
    }
}

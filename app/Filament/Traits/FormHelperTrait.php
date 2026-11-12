<?php

namespace App\Filament\Traits;

use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

trait FormHelperTrait
{
    public static function makeFormHeading(string $heading): ViewField
    {
        return ViewField::make(Str::slug($heading))
            ->view('components.form_heading')
            ->viewData(['heading' => $heading]);
    }

    public static function makeSettingsHeading(string $heading, string|HtmlString|null $description = null): ViewField
    {
        return ViewField::make(Str::slug($heading).'-heading')
            ->view('components.settings_heading')
            ->viewData(['heading' => $heading, 'description' => $description]);
    }

    public static function makeSettingsSection(
        string $label,
        string $rootPath,
        string $subPath,
        array $schema = [],
        string|HtmlString|null $description = null,
        bool $flat = false,
        int $cols = 2,
    ): Section|Group {
        $components = [Toggle::make($subPath.'.enabled')->reactive()];

        // Only add the settings group when there are settings. An always-hidden
        // group with the same state path would drop "<subPath>.enabled" on save
        // (hidden components are not dehydrated), e.g. a section with only a toggle.
        if (! empty($schema)) {
            $components[] = Group::make($schema)
                ->columns($cols)
                ->statePath($subPath)
                ->hidden(fn ($get) => ! $get($subPath.'.enabled'))
                ->reactive();
        }

        $inner = Group::make($components)->statePath($rootPath);

        if ($flat) {
            return Group::make([
                self::makeSettingsHeading($label, $description),
                $inner,
            ]);
        }

        return Section::make($label)
            ->description($description)
            ->schema([$inner]);
    }
}

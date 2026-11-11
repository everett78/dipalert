<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum Statuses: string implements HasColor, HasIcon, HasLabel
{
    case Published = 'p';
    case Archived = 'a';

    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function toArray(): array
    {
        return array_combine(self::values(), self::names());
    }

    public static function ignored(): array
    {
        return [
            self::Archived,
        ];
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Archived => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Published => 'heroicon-o-check-circle',
            self::Archived => 'heroicon-o-exclamation-circle',
        };
    }
}

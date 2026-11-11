<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LogLevels: string implements HasColor, HasIcon, HasLabel
{
    case Debug = 'DEBUG';
    case Info = 'INFO';
    case Notice = 'NOTICE';
    case Warning = 'WARNING';
    case Error = 'ERROR';
    case Critical = 'CRITICAL';
    case Alert = 'ALERT';
    case Emergency = 'EMERGENCY';

    public function getLabel(): string
    {
        return $this->name;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Error, self::Critical, self::Emergency => 'danger',
            self::Warning, self::Notice, self::Alert => 'warning',
            self::Info => 'info',
            default => 'gray'
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Error, self::Critical, self::Emergency => 'heroicon-o-exclamation-circle',
            self::Warning, self::Notice, self::Alert => 'heroicon-o-exclamation-triangle',
            default => 'heroicon-o-information-circle'
        };
    }
}

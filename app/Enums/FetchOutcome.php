<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The result of one fetch. Values are stored in the database; only the labels
 * are Polish.
 */
enum FetchOutcome: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Blocked = 'blocked';
    case NoPrice = 'no_price';
    case NetworkError = 'network_error';
    case UrlRefused = 'url_refused';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Blocked => 'blokada',
            self::NoPrice => 'brak ceny',
            self::NetworkError => 'błąd sieci',
            self::UrlRefused => 'adres odrzucony',
            self::Skipped => 'pominięty',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Skipped => 'gray',
            self::NoPrice => 'warning',
            self::Blocked, self::NetworkError, self::UrlRefused => 'danger',
        };
    }
}

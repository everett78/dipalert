<?php

namespace App\Enums;

use Filament\Support\Colors\ColorManager;

enum Trend: string
{
    case Up = 'up';
    case Down = 'down';

    case Lowest = 'lowest';
    case None = 'none';

    public static function getIcon(?string $trend): string
    {
        return match ($trend) {
            self::Down->value, self::Lowest->value => Icons::TrendDown->value,
            self::Up->value => Icons::TrendUp->value,
            default => Icons::TrendNone->value,
        };
    }

    public static function getText(?string $trend): string
    {
        return match ($trend) {
            self::Down->value => __('Below average'),
            self::Lowest->value => __('Lowest recorded'),
            self::Up->value => __('Above average'),
            default => __('No change'),
        };
    }

    public static function getColor(?string $trend): string
    {
        return match ($trend) {
            self::Down->value => 'warning',
            self::Lowest->value => 'success',
            self::Up->value => 'danger',
            default => 'gray',
        };
    }

    public static function getColorRgb(?string $trend): array
    {
        $manager = resolve(ColorManager::class);
        $colorString = self::getColor($trend);

        return $manager->getColors()[$colorString];
    }

    /**
     * Get the trend direction based on the given values.
     *
     * @deprecated use calculateTrend() instead
     */
    public static function getTrendDirection(array $values): string
    {
        if (count($values) === 2 && $values[0] !== $values[1]) {
            return ($values[0] - $values[1]) > 0 ? self::Up->value : self::Down->value;
        }

        return self::None->value;
    }

    /**
     * Get the trend direction based on the given values.
     */
    public static function calculateTrend(float $currentLowest, float $average, float $lowest): string
    {
        if ($currentLowest <= $lowest) {
            return self::Lowest->value;
        }

        if ($currentLowest < $average) {
            return self::Down->value;
        }

        if ($currentLowest > $average) {
            return self::Up->value;
        }

        return self::None->value;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps the page of a failed fetch for the "Problems" page, on the private
 * "local" disk: failed-pages/YYYY-MM-DD/<uuid>.html.
 */
class FailedPageStore
{
    public const string DISK = 'local';

    /**
     * @return string the path on the disk
     */
    public static function save(string $html): string
    {
        $max = (int) config('dipalert.failed_page_max_bytes', 2097152);

        if (strlen($html) > $max) {
            $html = substr($html, 0, $max);
        }

        $path = 'failed-pages/'.now()->format('Y-m-d').'/'.Str::uuid()->toString().'.html';

        Storage::disk(self::DISK)->put($path, $html);

        return $path;
    }

    public static function get(?string $path): ?string
    {
        if (blank($path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->get($path);
    }

    public static function delete(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}

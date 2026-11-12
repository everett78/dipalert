<?php

namespace App\Services;

use App\Dto\FetchContext;
use App\Exceptions\BlockedUrlException;
use App\Exceptions\StoreBusyException;
use App\Exceptions\StorePausedException;
use App\Models\Store;
use App\Services\Scrapers\FetchOutcomeClassifier;
use App\Services\Scrapers\ScraperFactory;
use App\Services\Security\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Finds and saves a store logo: the largest icon from the store's home page
 * (link rel~=icon or apple-touch-icon), or /favicon.ico. Only PNG, JPEG, ICO
 * and WebP up to 512 KB, recognised by their first bytes; never SVG.
 */
class StoreLogoFetcher
{
    public const string DISK = 'public';

    /**
     * File types recognised by their first bytes.
     */
    public const array TYPES = ['png', 'jpg', 'ico', 'webp'];

    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * @return bool whether a logo was saved
     */
    public function fetch(Store $store): bool
    {
        $domain = (string) data_get($store->domains, '0.domain', '');

        if ($domain === '') {
            return false;
        }

        $home = 'https://'.$domain.'/';

        foreach ($this->candidates($store, $home) as $iconUrl) {
            $bytes = $this->download($iconUrl);

            if ($bytes === null) {
                continue;
            }

            $type = self::detectType($bytes);

            if ($type === null) {
                continue;
            }

            $path = 'logos/'.$store->getKey().'.'.$type;
            Storage::disk(self::DISK)->put($path, $bytes);

            $store->forceFill(['logo_path' => $path])->saveQuietly();

            return true;
        }

        return false;
    }

    /**
     * Icon addresses from the home page, largest first, then /favicon.ico.
     *
     * @return list<string>
     */
    public function candidates(Store $store, string $home): array
    {
        $icons = [];

        try {
            $page = ScraperFactory::make($store->scraper_service, $home, $store, FetchContext::panelQueued())
                ->from($home)
                ->get();

            $html = $page->getBody();

            if ($html !== '' && ! FetchOutcomeClassifier::isBlocked($page->getStatusCode(), $html)) {
                $icons = $this->iconsFromHtml($html, $page->getFinalUrl() ?: $home);
            }
        } catch (BlockedUrlException|StorePausedException|StoreBusyException) {
            return [];
        } catch (Throwable $e) {
            logger()->warning('Could not read the store home page for its logo: '.$e->getMessage(), ['store_id' => $store->getKey()]);
        }

        $icons[] = self::resolve($home, '/favicon.ico');

        return array_values(array_unique($icons));
    }

    /**
     * @return list<string>
     */
    public function iconsFromHtml(string $html, string $baseUrl): array
    {
        $found = [];

        try {
            (new Crawler($html))->filter('link[rel][href]')->each(function (Crawler $node) use (&$found, $baseUrl): void {
                $rel = ' '.strtolower((string) $node->attr('rel')).' ';

                if (! str_contains($rel, ' icon ') && ! str_contains($rel, 'apple-touch-icon')) {
                    return;
                }

                $href = trim((string) $node->attr('href'));

                if ($href === '' || str_starts_with($href, 'data:') || str_ends_with(strtolower(strtok($href, '?') ?: ''), '.svg')
                    || str_contains(strtolower((string) $node->attr('type')), 'svg')) {
                    return;
                }

                $found[] = [
                    'url' => self::resolve($baseUrl, $href),
                    'size' => self::largestSize((string) $node->attr('sizes'), str_contains($rel, 'apple-touch-icon')),
                ];
            });
        } catch (Throwable) {
            return [];
        }

        usort($found, fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return array_column($found, 'url');
    }

    /**
     * Download an image directly (not through the page scraper, which changes
     * the encoding of the body). Every address and redirect passes UrlGuard.
     */
    public function download(string $url): ?string
    {
        $guard = UrlGuard::new();
        $max = (int) config('dipalert.logo_max_bytes', 524288);

        try {
            $guard->assertAllowed($url);

            $response = Http::timeout((int) config('dipalert.logo_timeout_seconds', 10))
                ->connectTimeout((int) config('dipalert.logo_timeout_seconds', 10))
                ->withOptions([
                    'allow_redirects' => [
                        'max' => (int) config('dipalert.max_redirects', 5),
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function ($request, $response, UriInterface $uri) use ($guard): void {
                            $guard->assertAllowed((string) $uri);
                        },
                    ],
                ])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $length = $response->header('Content-Length');

        if (is_numeric($length) && (int) $length > $max) {
            return null;
        }

        $body = $response->body();

        return $body === '' || strlen($body) > $max ? null : $body;
    }

    /**
     * The file type from its first bytes, or null (SVG and anything else).
     */
    public static function detectType(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($bytes, "\x00\x00\x01\x00") => 'ico',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    protected static function largestSize(string $sizes, bool $appleTouch): int
    {
        $largest = 0;

        foreach (preg_split('/\s+/', strtolower(trim($sizes))) ?: [] as $size) {
            if (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
                $largest = max($largest, (int) $m[1] * (int) $m[2]);
            }
        }

        // An apple-touch-icon without sizes is usually 180x180.
        return $largest > 0 ? $largest : ($appleTouch ? 180 * 180 : 16 * 16);
    }

    protected static function resolve(string $base, string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$port.$href;
        }

        $path = $parts['path'] ?? '/';
        $dir = str_ends_with($path, '/') ? $path : (dirname($path) === '.' ? '/' : rtrim(dirname($path), '/').'/');

        return $scheme.'://'.$host.$port.$dir.$href;
    }
}

<?php

namespace App\Services;

use App\Services\Security\UrlGuard;
use Illuminate\Support\Facades\Http;

class OllamaService
{
    public const int DEFAULT_TIMEOUT = 10;

    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * Fetch the list of model names available on an Ollama server via /api/tags.
     *
     * @return array<int, string>
     *
     * @throws \App\Exceptions\BlockedUrlException when the address is not allowed
     * @throws \Illuminate\Http\Client\ConnectionException
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function listModels(string $baseUrl, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        // Ollama usually runs in the home network: allowed only when listed in
        // DIPALERT_ALLOWED_INTERNAL_HOSTS.
        UrlGuard::new()->assertServiceAllowed($baseUrl);

        $response = Http::baseUrl(rtrim($baseUrl, '/'))
            ->timeout($timeout)
            ->throw()
            ->get('/api/tags');

        return collect($response->json('models', []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }
}

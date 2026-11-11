<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ManifestController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $name = config('app.name', 'PriceBuddy');
        $scheme = parse_url($request->url(), PHP_URL_SCHEME);
        $domain = parse_url($request->url(), PHP_URL_HOST);
        $url = $scheme.'://'.$domain;

        return (new JsonResponse([
            'id' => Str::slug(str_replace('.', '-', $domain)),
            'theme_color' => '#000000',
            'background_color' => '#000000',
            'icons' => [
                ['purpose' => 'maskable', 'sizes' => '512x512', 'src' => '/images/icon.png', 'type' => 'image/png'],
                ['purpose' => 'any', 'sizes' => '512x512', 'src' => '/images/icon.png', 'type' => 'image/png'],
            ],
            'orientation' => 'any',
            'display' => 'standalone',
            'dir' => 'auto',
            'lang' => 'en-AU',
            'name' => $name,
            'short_name' => $name,
            'description' => 'Price tracking app',
            'start_url' => $url,
        ]))
            ->withHeaders([
                'Pragma' => 'public',
                'Expires' => gmdate('D, d M Y H:i:s \G\M\T', 886400),
            ])
            ->setMaxAge(886400)
            ->setPublic();
    }
}

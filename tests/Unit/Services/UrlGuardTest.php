<?php

namespace Tests\Unit\Services;

use App\Exceptions\BlockedUrlException;
use App\Services\Security\UrlGuard;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    /**
     * @dataProvider refusedUrls
     */
    public function test_refuses_internal_and_reserved_addresses(string $url): void
    {
        $this->assertFalse(UrlGuard::new()->isAllowed($url), "Expected {$url} to be refused");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function refusedUrls(): array
    {
        return [
            'private 192.168' => ['http://192.168.0.21:30098/'],
            'private 10' => ['https://10.1.2.3/'],
            'loopback' => ['http://127.0.0.1/'],
            'localhost' => ['http://localhost/'],
            'localhost subdomain' => ['http://app.localhost/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 unique local' => ['http://[fd00::1]/'],
            'ipv4 mapped private' => ['http://[::ffff:192.168.0.1]/'],
            'link local metadata' => ['http://169.254.169.254/'],
            'file scheme' => ['file:///etc/passwd'],
            'no host' => ['not a url'],
        ];
    }

    public function test_allows_a_public_address(): void
    {
        $this->assertTrue(UrlGuard::new()->isAllowed('https://www.example.com/product/1'));
    }

    public function test_refuses_a_name_that_resolves_to_a_private_address(): void
    {
        $this->hostResolver->set('shop.example', ['192.168.1.50']);

        $this->expectException(BlockedUrlException::class);

        UrlGuard::new()->assertAllowed('https://shop.example/item');
    }

    public function test_refuses_a_name_with_one_private_address_among_public_ones(): void
    {
        $this->hostResolver->set('mixed.example', ['93.184.216.34', '10.0.0.7']);

        $this->assertSame(UrlGuard::INTERNAL, UrlGuard::new()->check('https://mixed.example/'));
    }

    public function test_unresolvable_name_is_refused(): void
    {
        $this->hostResolver->set('nowhere.example', []);

        $this->assertSame(UrlGuard::UNRESOLVED, UrlGuard::new()->check('https://nowhere.example/'));
    }

    public function test_allowed_internal_host_only_for_service_connections(): void
    {
        config(['dipalert.allowed_internal_hosts' => ['192.168.0.21:30098', 'ollama.lan']]);
        $this->hostResolver->set('ollama.lan', ['192.168.0.30']);

        $guard = UrlGuard::new();

        // As a service the app connects to: allowed.
        $guard->assertServiceAllowed('http://192.168.0.21:30098/v1');
        $guard->assertServiceAllowed('http://ollama.lan:11434');

        // As a page to fetch: still refused.
        $this->assertFalse($guard->isAllowed('http://192.168.0.21:30098/v1'));
        $this->assertFalse($guard->isAllowed('http://ollama.lan:11434'));

        // Another port of a host listed with a port is not allowed.
        $this->expectException(BlockedUrlException::class);
        $guard->assertServiceAllowed('http://192.168.0.21:22/');
    }
}

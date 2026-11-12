<?php

namespace Tests\Support;

use App\Services\Security\HostResolver;

/**
 * Resolves every host to a public address unless a test sets other addresses,
 * so tests never make real DNS queries.
 */
class FakeHostResolver implements HostResolver
{
    public const string PUBLIC_IP = '93.184.216.34';

    /** @var array<string, list<string>> */
    protected array $hosts = [];

    /** @var list<string> */
    protected array $default = [self::PUBLIC_IP];

    /**
     * @param  list<string>  $ips
     */
    public function set(string $host, array $ips): self
    {
        $this->hosts[strtolower($host)] = $ips;

        return $this;
    }

    /**
     * @param  list<string>  $ips
     */
    public function setDefault(array $ips): self
    {
        $this->default = $ips;

        return $this;
    }

    public function resolve(string $host): array
    {
        return $this->hosts[strtolower($host)] ?? $this->default;
    }
}

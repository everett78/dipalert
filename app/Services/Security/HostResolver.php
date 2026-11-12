<?php

namespace App\Services\Security;

interface HostResolver
{
    /**
     * Resolve a host name to its IPv4 and IPv6 addresses.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}

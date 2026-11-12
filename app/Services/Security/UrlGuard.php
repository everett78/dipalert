<?php

namespace App\Services\Security;

use App\Exceptions\BlockedUrlException;

/**
 * Refuses addresses in the home network and other internal or reserved ranges
 * (SSRF guard). Called before every page fetch, after every redirect and before
 * every request to a service whose address comes from the settings.
 */
class UrlGuard
{
    public const string INVALID = 'invalid';

    public const string SCHEME = 'scheme';

    public const string INTERNAL = 'internal';

    public const string UNRESOLVED = 'unresolved';

    /**
     * IPv4 ranges that must never be reached server-side.
     *
     * @var list<string>
     */
    protected const array BLOCKED_IPV4 = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // RFC1918 private
        '100.64.0.0/10',    // RFC6598 carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // RFC3927 link-local (incl. cloud metadata)
        '172.16.0.0/12',    // RFC1918 private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // RFC1918 private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
        '255.255.255.255/32', // broadcast
    ];

    /**
     * IPv6 ranges that must never be reached server-side.
     *
     * @var list<string>
     */
    protected const array BLOCKED_IPV6 = [
        '::1/128',      // loopback
        '::/128',       // unspecified
        '64:ff9b::/96', // NAT64
        '100::/64',     // discard-only
        '2001:db8::/32', // documentation
        'fc00::/7',     // unique local address
        'fe80::/10',    // link-local
        'ff00::/8',     // multicast
    ];

    public function __construct(protected HostResolver $resolver) {}

    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * Why the address is refused, or null when it is a public http(s) address.
     */
    public function check(string $url): ?string
    {
        if (trim($url) === '') {
            return self::INVALID;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return self::INVALID;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return self::SCHEME;
        }

        $host = self::normaliseHost($parts['host']);

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return self::INTERNAL;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            return self::UNRESOLVED;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return self::INTERNAL;
            }
        }

        return null;
    }

    public function isAllowed(string $url): bool
    {
        return $this->check($url) === null;
    }

    /**
     * @throws BlockedUrlException
     */
    public function assertAllowed(string $url): void
    {
        if (($reason = $this->check($url)) !== null) {
            throw new BlockedUrlException($url, $reason);
        }
    }

    /**
     * Like assertAllowed, but also lets through the home network services listed
     * in DIPALERT_ALLOWED_INTERNAL_HOSTS (FlareSolverr, Ollama). Use it only for
     * a connection of the app to such a service, never for a page to fetch.
     *
     * @throws BlockedUrlException
     */
    public function assertServiceAllowed(string $url): void
    {
        if ($this->isAllowedInternalService($url)) {
            return;
        }

        $this->assertAllowed($url);
    }

    /**
     * Whether the http(s) address points to a host (or host:port) listed in
     * DIPALERT_ALLOWED_INTERNAL_HOSTS.
     */
    protected function isAllowedInternalService(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = self::normaliseHost($parts['host']);
        $hostPort = isset($parts['port']) ? $host.':'.$parts['port'] : $host;
        $allowed = array_map(
            fn ($entry): string => strtolower(trim((string) $entry)),
            (array) config('dipalert.allowed_internal_hosts', []),
        );

        return in_array($host, $allowed, true) || in_array($hostPort, $allowed, true);
    }

    /**
     * Lowercase, strip IPv6 brackets, and convert IDN to ASCII so unicode variants
     * of blocked hosts cannot bypass the checks.
     */
    public static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host, '[]'));

        if ($host !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host);

            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        return $host;
    }

    public static function isPublicIp(string $ip): bool
    {
        $ip = self::unwrapMappedIpv4($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4 as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach (self::BLOCKED_IPV6 as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Convert an IPv4-mapped IPv6 address (::ffff:1.2.3.4) to its IPv4 form so it
     * is checked against the IPv4 block list.
     */
    protected static function unwrapMappedIpv4(string $ip): string
    {
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);

            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $tail;
            }

            $packed = @inet_pton($ip);

            if ($packed !== false && strlen($packed) === 16) {
                return (string) inet_ntop(substr($packed, 12));
            }
        }

        return $ip;
    }

    protected static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton($subnet);

        if ($ipPacked === false || $subnetPacked === false || strlen($ipPacked) !== strlen($subnetPacked)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipPacked, $subnetPacked, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $remainder) & 0xFF);

        return (ord($ipPacked[$bytes]) & ord($mask)) === (ord($subnetPacked[$bytes]) & ord($mask));
    }
}

<?php

namespace App\Services\Security;

class DnsHostResolver implements HostResolver
{
    /**
     * IPv4 through gethostbynamel (reads /etc/hosts too), IPv6 through AAAA records.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $ips = [];

        foreach (gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}

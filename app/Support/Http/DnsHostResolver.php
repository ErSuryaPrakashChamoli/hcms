<?php

namespace App\Support\Http;

/** System DNS: every A and AAAA record of the name (plus the libc lookup as a fallback for /etc/hosts entries). */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];
        foreach ((@dns_get_record($host, DNS_A | DNS_AAAA)) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        foreach ((@gethostbynamel($host)) ?: [] as $ip) {
            $ips[] = $ip;
        }

        return array_values(array_unique(array_filter($ips)));
    }
}

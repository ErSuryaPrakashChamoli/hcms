<?php

namespace App\Support\Http;

/**
 * Production readiness closure: the SSRF boundary for every outbound request to a tenant-configured
 * destination (webhook endpoints, workflow webhook nodes, SSO token and userinfo endpoints).
 *
 * A URL passes only when all of these hold:
 * - The scheme is https (http only when PEOPLEOS_OUTBOUND_ALLOW_HTTP is on).
 * - It carries no credentials, and its port is on the allowed list.
 * - The host is not a reserved local name (localhost, *.localhost, *.local, *.internal, *.home.arpa,
 *   metadata hosts) and not an ambiguous numeric form (`127.1`, `2130706433`, `0x7f.0.0.1`).
 * - The host resolves (after DNS), and **every** resolved address is public. Refused: loopback,
 *   RFC 1918, carrier-grade NAT, link-local (including the cloud metadata address 169.254.169.254 and
 *   fd00:ec2::254), unspecified, documentation, benchmarking, multicast and reserved ranges, IPv6
 *   unique-local / link-local / site-local, and IPv4 embedded in IPv6 (mapped, compatible, NAT64,
 *   6to4, Teredo), which is checked as IPv4.
 *
 * The result carries the validated address, so the connection is pinned to it (no second lookup, hence
 * no DNS rebinding). Callers never follow redirects. Operators may exempt exact host names through
 * PEOPLEOS_OUTBOUND_ALLOWED_HOSTS (for example an on-premises receiver); tenants cannot.
 */
final class OutboundUrlGuard
{
    /** CIDR blocks that are never a public destination. */
    public const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    public const BLOCKED_V6 = [
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    private const RESERVED_NAMES = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback', 'metadata', 'metadata.google.internal', 'instance-data', 'instance-data.ec2.internal'];

    private const RESERVED_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa', '.localdomain'];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Full check including DNS. Returns the parts needed to pin the connection.
     *
     * @return array{url: string, scheme: string, host: string, port: int, ip: string, ips: list<string>}
     */
    public function inspect(string $url): array
    {
        $parts = $this->staticParts($url);
        $host = $parts['host'];
        $literal = $this->literalIp($host);
        if ($literal !== null) {
            $ips = [$literal];
        } elseif (in_array($host, $this->allowedHosts(), true)) {
            $ips = $this->resolver->resolve($host);
            if ($ips === []) {
                throw new UnsafeOutboundUrl('The destination host does not resolve.', 'unresolvable');
            }

            return $parts + ['ip' => $ips[0], 'ips' => $ips];
        } else {
            $ips = $this->resolver->resolve($host);
        }
        if ($ips === []) {
            throw new UnsafeOutboundUrl('The destination host does not resolve.', 'unresolvable');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeOutboundUrl('The destination resolves to a private, local or reserved network address.', 'private_address');
            }
        }

        return $parts + ['ip' => $ips[0], 'ips' => array_values($ips)];
    }

    /**
     * Checks that need no DNS (used when a URL is saved): scheme, credentials, port, reserved names and
     * literal private addresses. The full check still runs at send time.
     *
     * @return array{url: string, scheme: string, host: string, port: int}
     */
    public function staticParts(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeOutboundUrl('The destination is not a valid absolute URL.', 'invalid_url');
        }
        $scheme = strtolower($parts['scheme']);
        $allowed = config('peopleos.outbound.allow_http', false) ? ['https', 'http'] : ['https'];
        if (! in_array($scheme, $allowed, true)) {
            throw new UnsafeOutboundUrl('Only '.implode(' or ', $allowed).' destinations are allowed.', 'scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrl('Destinations may not carry credentials in the URL.', 'credentials');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, array_map('intval', (array) config('peopleos.outbound.allowed_ports', [443, 80, 8443, 8080])), true)) {
            throw new UnsafeOutboundUrl('That destination port is not allowed.', 'port');
        }
        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');
        if ($host === '') {
            throw new UnsafeOutboundUrl('The destination is not a valid absolute URL.', 'invalid_url');
        }
        if (! in_array($host, $this->allowedHosts(), true)) {
            // Every label numeric (decimal, octal or hex) but not a canonical address: libc would still read it as an IP.
            if ($this->literalIp($host) === null && preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+)){0,3}$/i', $host)) {
                throw new UnsafeOutboundUrl('Ambiguous numeric host names are not allowed.', 'numeric_host');
            }
            if (in_array($host, self::RESERVED_NAMES, true) || collect(self::RESERVED_SUFFIXES)->contains(fn ($s) => str_ends_with($host, $s)) || ! str_contains($host, '.') && $this->literalIp($host) === null) {
                throw new UnsafeOutboundUrl('The destination is a local or internal host name.', 'internal_host');
            }
            $literal = $this->literalIp($host);
            if ($literal !== null && ! self::isPublicIp($literal)) {
                throw new UnsafeOutboundUrl('The destination is a private, local or reserved network address.', 'private_address');
            }
        }

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            $embedded = self::embeddedIpv4($packed);
            if ($embedded !== null) {
                return self::isPublicIp($embedded);
            }
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }
        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** IPv4 carried inside IPv6: mapped (::ffff:0:0/96), compatible (::/96), NAT64 (64:ff9b::/96), 6to4 (2002::/16), Teredo (2001::/32, obfuscated). */
    private static function embeddedIpv4(string $packed): ?string
    {
        $hex = bin2hex($packed);
        if (str_starts_with($hex, '00000000000000000000ffff') || (str_starts_with($hex, '000000000000000000000000') && substr($hex, 24) !== '00000000' && substr($hex, 24) !== '00000001')
            || str_starts_with($hex, '0064ff9b0000000000000000')) {
            return inet_ntop(substr($packed, 12, 4));
        }
        if (str_starts_with($hex, '2002')) {
            return inet_ntop(substr($packed, 2, 4));
        }
        if (str_starts_with($hex, '20010000')) {
            return inet_ntop(substr($packed, 12, 4) ^ "\xff\xff\xff\xff");
        }

        return null;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $net = inet_pton($network);
        if ($net === false || strlen($net) !== strlen($packed)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return ($packed[$bytes] & $mask) === ($net[$bytes] & $mask);
    }

    private function literalIp(string $host): ?string
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        return array_values(array_filter(array_map(fn ($h) => strtolower(trim((string) $h)), (array) config('peopleos.outbound.allowed_hosts', []))));
    }
}

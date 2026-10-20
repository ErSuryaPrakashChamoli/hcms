<?php

namespace App\Support\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Production readiness closure: the only way to call a tenant-configured destination.
 *
 * The URL is checked by OutboundUrlGuard (after DNS), then the request is bound to that check:
 * - the connection is pinned to the validated address (CURLOPT_RESOLVE), so a later DNS answer
 *   (rebinding) cannot redirect it;
 * - redirects are never followed (a 3xx is a failed delivery);
 * - only http(s) protocols are possible.
 *
 * When an egress proxy is configured, the proxy resolves the name instead and must enforce the same
 * policy (see docs/production/security-boundaries.md).
 */
final class SafeHttp
{
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /** @throws UnsafeOutboundUrl */
    public function to(string $url): PendingRequest
    {
        return Http::withOptions($this->options($this->guard->inspect($url)));
    }

    /**
     * Guzzle options that pin a validated target.
     *
     * @param  array{host: string, port: int, ip: string}  $target
     * @return array<string, mixed>
     */
    public function options(array $target): array
    {
        $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
        $curl = [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curl[CURLOPT_PROTOCOLS_STR] = 'http,https';
        }

        return ['allow_redirects' => false, 'curl' => $curl];
    }

    /** A URL safe to show or store: scheme, host and port only (paths and queries can carry tokens). */
    public static function redact(string $url): string
    {
        $p = parse_url($url);

        return $p === false || ! isset($p['host']) ? '[invalid url]' : ($p['scheme'] ?? 'https').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').'/…';
    }
}

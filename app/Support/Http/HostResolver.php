<?php

namespace App\Support\Http;

/** Resolves a host name to its IPv4 / IPv6 addresses (the boundary the outbound guard checks). */
interface HostResolver
{
    /** @return list<string> every A and AAAA address; empty when the name does not resolve */
    public function resolve(string $host): array;
}

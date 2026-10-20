<?php

namespace Tests\Support;

use App\Support\Http\HostResolver;

/**
 * Test DNS. Names in $map resolve as given (a list of answers is returned in order on repeated
 * lookups, which models DNS rebinding); every other name resolves to one public address, so feature
 * tests never perform real DNS. SSRF tests replace the map.
 */
final class FakeHostResolver implements HostResolver
{
    public const PUBLIC_IP = '93.184.215.14';

    /** @var array<string, int> */
    public array $lookups = [];

    /** @param  array<string, list<string>|list<list<string>>>  $map */
    public function __construct(public array $map = []) {}

    public function resolve(string $host): array
    {
        $this->lookups[$host] = ($this->lookups[$host] ?? 0) + 1;
        if (! array_key_exists($host, $this->map)) {
            return [self::PUBLIC_IP];
        }
        $answers = $this->map[$host];
        if ($answers !== [] && is_array($answers[0])) {
            return $answers[min($this->lookups[$host] - 1, count($answers) - 1)];
        }

        return $answers;
    }
}

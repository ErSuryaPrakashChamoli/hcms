<?php

namespace App\Domain\Payments\Support;

/** SaaS.7: a provider webhook whose signature (and timestamp, where signed) was verified over the raw body. */
final readonly class VerifiedProviderEvent
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(public string $eventId, public string $type, public array $payload) {}
}

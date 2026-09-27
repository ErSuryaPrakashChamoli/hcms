<?php

namespace App\Domain\Leave\Support;

/** Outcome of an eligibility check: eligible or not, with the reason and the resolved entitlement rule. */
final class Eligibility
{
    /** @param  array<string, mixed>|null  $rule */
    public function __construct(public readonly bool $eligible, public readonly string $reason, public readonly ?array $rule = null) {}

    public static function yes(array $rule): self
    {
        return new self(true, 'Eligible', $rule);
    }

    public static function no(string $reason, ?array $rule = null): self
    {
        return new self(false, $reason, $rule);
    }
}

<?php

namespace App\Domain\Compliance\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 6 §32: tenant-level compliance outcomes (establishment verified, return reconciled /
 * approved / exported / filed). Consumed by the webhook bridge; platform rule events are audited
 * only (they have no tenant subscriber).
 */
final class ComplianceEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Model $subject, public readonly array $context = []) {}
}

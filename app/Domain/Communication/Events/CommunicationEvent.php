<?php

namespace App\Domain\Communication\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 13: communication.* lifecycle events (review requested, approved, published, delivered,
 * acknowledged). Context carries references (title, type, version, counts), never the body or the
 * recipient list.
 */
final class CommunicationEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context  @param  list<int>  $recipientUserIds */
    public function __construct(public readonly string $name, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientUserIds = []) {}
}

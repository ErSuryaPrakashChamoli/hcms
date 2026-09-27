<?php

namespace App\Domain\ServiceDesk\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** servicedesk.ticket.* and grievance.* — recipients are user ids (agents) and/or the employee. */
final class ServiceDeskEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientUserIds = []) {}
}

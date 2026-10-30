<?php

namespace App\Domain\Recognition\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HROne setup parity HP-9d / HP-9e: recognition.given / recognition.withdrawn (subject: the Recognition) and
 * recognition.points_given (subject: the receiver's ledger row). $employee is the person recognised. Heard by RewardPoints
 * (badge points) and SocialWall (posts); the context carries references, never the giver's words.
 */
final class RecognitionEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Model $subject, public readonly ?Employee $employee, public readonly array $context = []) {}
}

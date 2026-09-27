<?php

namespace App\Domain\Exit\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** exit.* / letter.* / alumni.* — recipients are user ids. */
final class ExitEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientUserIds = []) {}
}

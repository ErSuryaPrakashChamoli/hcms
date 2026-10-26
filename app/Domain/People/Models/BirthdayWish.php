<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A birthday wish one person sent another on the day (one per sender, recipient and year). Sent through
 * Birthdays::wish(); the message is the sender's own words, so it is kept out of the audit trail.
 */
#[Fillable(['tenant_id', 'employee_id', 'sender_user_id', 'sender_employee_id', 'year', 'message', 'read_at'])]
class BirthdayWish extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    protected function casts(): array
    {
        return ['year' => 'integer', 'read_at' => 'datetime'];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'message', 'read_at'];
    }

    /** The person whose birthday it is. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sender_employee_id');
    }
}

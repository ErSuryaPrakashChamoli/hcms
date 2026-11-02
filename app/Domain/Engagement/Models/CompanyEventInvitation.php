<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne parity GO-15: an employee's invitation to a company event — invited (the audience, or added by HR), nominated (by
 * a colleague, when the event allows it), accepted ("Acknowledge" for a mandatory event) or declined. Answered only by
 * the invitee, through `CompanyEvents::respond()`.
 */
#[Fillable(['tenant_id', 'event_id', 'employee_id', 'status', 'source', 'responded_at', 'nominated_by'])]
class CompanyEventInvitation extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = ['invited' => 'Invited', 'nominated' => 'Nominated', 'accepted' => 'Accepted', 'declined' => 'Declined'];

    /** Waiting for the invitee's answer. */
    public const PENDING = ['invited', 'nominated'];

    public const SOURCES = ['audience' => 'Audience', 'hr' => 'Added by HR', 'nomination' => 'Nominated by a colleague'];

    protected $attributes = ['status' => 'invited', 'source' => 'audience'];

    protected static function booted(): void
    {
        static::saving(function (self $invitation): void {
            if (! array_key_exists((string) $invitation->status, self::STATUSES) || ! array_key_exists((string) $invitation->source, self::SOURCES)) {
                throw new RuntimeException('Unknown invitation status or source.');
            }
        });
    }

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return 'Event invitation #'.$this->getKey();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'event_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function nominator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'nominated_by');
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }
}

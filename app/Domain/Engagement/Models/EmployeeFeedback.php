<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Engagement\Concerns\HasRandomUuid;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 13: employee feedback — identified (employee set), confidential (author only in
 * engagement_identities) or anonymous (no author anywhere). It has a random UUID key, a date only
 * (no time) and an encrypted body.
 *
 * It is not a grievance or a case: identified feedback can be referred to the HR service desk through
 * its own intake. Not Auditable (an automatic audit would stamp the author). Handling is audited
 * explicitly by the feedback service.
 */
#[Fillable(['tenant_id', 'mode', 'category', 'body', 'employee_id', 'status', 'submitted_on', 'handled_by', 'handling_note', 'referred_type', 'referred_id', 'closed_on'])]
class EmployeeFeedback extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $table = 'employee_feedback';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (self $f): void {
            if (array_intersect(array_keys($f->getDirty()), ['mode', 'category', 'body', 'employee_id', 'submitted_on']) !== []) {
                throw new \RuntimeException('Submitted feedback never changes.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Feedback is never deleted.'));
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'handling_note' => 'encrypted', 'submitted_on' => 'date', 'closed_on' => 'date'];
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}

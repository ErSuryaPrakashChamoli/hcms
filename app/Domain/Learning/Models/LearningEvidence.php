<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 8: evidence an employee uploads for an enrolment (private disk, hashed, reviewed by L&D). */
#[Fillable(['tenant_id', 'employee_id', 'learning_enrolment_id', 'disk', 'path', 'original_name', 'mime', 'size', 'sha256', 'status', 'uploaded_by', 'reviewed_by', 'reviewed_at', 'review_note'])]
class LearningEvidence extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $table = 'learning_evidence';

    protected $hidden = ['path', 'disk'];

    protected $attributes = ['status' => 'submitted', 'disk' => 'local'];

    protected static function booted(): void
    {
        static::updating(function (self $e) {
            if ($e->getRawOriginal('status') !== 'submitted' || array_diff(array_keys($e->getDirty()), ['status', 'reviewed_by', 'reviewed_at', 'review_note', 'updated_at']) !== []) {
                throw new \RuntimeException('Reviewed evidence is read-only, and the file itself never changes.');
            }
        });
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return 'Learning evidence '.$this->original_name;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(LearningEnrolment::class, 'learning_enrolment_id');
    }
}

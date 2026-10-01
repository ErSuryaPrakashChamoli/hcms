<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 8: an employee's participation in a pinned program version. */
#[Fillable(['tenant_id', 'employee_id', 'learning_program_version_id', 'status', 'enrolled_by', 'enrolled_at', 'completed_at', 'lock_version'])]
class LearningProgramParticipant extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TRANSITIONS = ['enrolled' => ['completed', 'withdrawn'], 'completed' => [], 'withdrawn' => []];

    protected $attributes = ['status' => 'enrolled'];

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            $from = $p->getRawOriginal('status');
            if ($p->isDirty('status') && ! in_array($p->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A program participation cannot move from {$from} to {$p->status}.");
            }
            if (in_array($from, ['completed', 'withdrawn'], true)) {
                throw new \RuntimeException('A closed program participation is read-only.');
            }
            $p->lock_version = (int) $p->getRawOriginal('lock_version') + 1;
        });
    }

    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime', 'completed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LearningProgramVersion::class, 'learning_program_version_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(LearningEnrolment::class);
    }
}

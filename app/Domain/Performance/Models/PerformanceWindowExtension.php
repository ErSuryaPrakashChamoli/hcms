<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity GO-2 (HROne "Extend goal" / "Extend review" / "Extend 360"): more time for one stage of a running
 * performance cycle — or for a 360 feedback round (stage `feedback_360`) — for everyone (employee null) or for one
 * employee, until `ends_on`, with a reason. Written only by PerformanceWindows; append-only (a later extension is a new
 * row and the latest end date wins); never changed or deleted. Access scope: an extension for one employee follows that
 * employee's scope; one for everyone (no employee) names nobody and is visible wherever its cycle is. PerformanceWindows
 * reads them unscoped to work out due dates, which must not depend on who looks.
 */
#[Fillable(['tenant_id', 'performance_cycle_id', 'feedback_round_id', 'stage', 'employee_id', 'previous_ends_on', 'ends_on', 'reason', 'created_by'])]
class PerformanceWindowExtension extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('A window extension is kept as it was given; extend again instead.'));
        static::deleting(fn () => throw new \RuntimeException('Window extensions are never deleted.'));
    }

    /** AccessScope: rows for everyone stay visible; a row for an employee only within the viewer's employee scope. */
    public function applyAccessScope(Builder $query, User $user, AccessScopes $scopes): void
    {
        $query->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('employee_id'))
            ->orWhere(fn (Builder $w) => $scopes->constrainByEmployee($w, $user, 'employee_id')));
    }

    protected function casts(): array
    {
        return ['previous_ends_on' => 'date', 'ends_on' => 'date'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Window extension · '.(config("peopleos.performance.stages.{$this->stage}") ?? ($this->stage === 'feedback_360' ? '360 feedback' : $this->stage));
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(FeedbackRound::class, 'feedback_round_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

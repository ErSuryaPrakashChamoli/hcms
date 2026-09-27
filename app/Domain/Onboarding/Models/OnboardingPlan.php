<?php

namespace App\Domain\Onboarding\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'employee_id', 'onboarding_template_id', 'status', 'anchor_date', 'progress', 'started_by', 'started_at', 'completed_at'])]
class OnboardingPlan extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return [
            'anchor_date' => 'date',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'onboarding';
    }

    public function auditLabel(): string
    {
        return "Onboarding plan #{$this->id}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'onboarding_template_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'in_progress';
    }
}

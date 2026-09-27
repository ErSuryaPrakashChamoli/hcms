<?php

namespace App\Domain\Onboarding\Models;

use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'onboarding_plan_id', 'onboarding_template_item_id', 'phase', 'type', 'title', 'description', 'owner_user_id', 'owner_role_id', 'document_type_id', 'form_id', 'employee_document_id', 'form_submission_id', 'due_on', 'is_mandatory', 'status', 'note', 'completed_by', 'completed_at', 'sort_order'])]
class OnboardingTask extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending' => 'Pending', 'completed' => 'Completed', 'skipped' => 'Skipped'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'is_mandatory' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OnboardingPlan::class, 'onboarding_plan_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    #[Scope]
    protected function actionableBy(Builder $query, User $user): Builder
    {
        $roleIds = $user->roles()->pluck('roles.id');

        return $query->where(fn (Builder $q) => $q->where('owner_user_id', $user->id)->orWhereIn('owner_role_id', $roleIds));
    }

    public function isActionableBy(User $user): bool
    {
        return $this->status === 'pending' && (
            $this->owner_user_id === $user->id
            || ($this->owner_role_id !== null && $user->roles()->where('roles.id', $this->owner_role_id)->exists())
        );
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_on !== null && $this->due_on->isPast();
    }

    public function ownerLabel(): string
    {
        $this->loadMissing(['owner', 'ownerRole']);

        return $this->owner?->name ?? ($this->ownerRole ? 'Role: '.$this->ownerRole->name : 'Unassigned');
    }

    public function phaseLabel(): string
    {
        return config("peopleos.onboarding.phases.{$this->phase}.label", $this->phase);
    }
}

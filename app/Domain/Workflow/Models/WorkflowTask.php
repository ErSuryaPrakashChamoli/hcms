<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An approval or to-do created by a workflow node, assigned to a user or to a role. */
#[Fillable(['tenant_id', 'workflow_instance_id', 'node_id', 'type', 'title', 'instructions', 'assignee_id', 'assignee_role_id', 'sequence', 'status', 'decision', 'note', 'due_at', 'escalation_level', 'last_reminded_at', 'completed_by', 'completed_at'])]
class WorkflowTask extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'completed_at' => 'datetime',
            'sequence' => 'integer',
            'escalation_level' => 'integer',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assigneeRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assignee_role_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Tasks this user may act on: assigned directly, or via one of their roles. */
    #[Scope]
    protected function actionableBy(Builder $query, User $user): Builder
    {
        $roleIds = $user->roles()->pluck('roles.id');

        return $query->where(fn (Builder $q) => $q
            ->where('assignee_id', $user->id)
            ->orWhereIn('assignee_role_id', $roleIds));
    }

    public function isActionableBy(User $user): bool
    {
        return $this->status === TaskStatus::Pending && (
            $this->assignee_id === $user->id
            || ($this->assignee_role_id !== null && $user->roles()->where('roles.id', $this->assignee_role_id)->exists())
        );
    }

    public function isOverdue(): bool
    {
        return $this->status === TaskStatus::Pending && $this->due_at !== null && $this->due_at->isPast();
    }

    public function assigneeLabel(): string
    {
        $this->loadMissing(['assignee', 'assigneeRole']);

        return $this->assignee?->name ?? ($this->assigneeRole ? 'Role: '.$this->assigneeRole->name : 'Unassigned');
    }
}

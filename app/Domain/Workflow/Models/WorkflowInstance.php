<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['tenant_id', 'workflow_id', 'workflow_version_id', 'subject_type', 'subject_id', 'subject_label', 'status', 'current_node_id', 'context', 'outcome', 'started_by', 'started_at', 'wake_at', 'completed_at', 'error'])]
class WorkflowInstance extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'status' => InstanceStatus::class,
            'started_at' => 'datetime',
            'wake_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        $workflow = $this->relationLoaded('workflow') ? $this->workflow : $this->workflow()->first();

        return ($workflow?->name ?? 'Workflow')." run #{$this->id}".($this->subject_label ? " for {$this->subject_label}" : '');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class)->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('id');
    }

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->context, $key, $default);
    }
}

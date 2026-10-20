<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'name', 'key', 'description', 'trigger_event', 'subject_type', 'start_conditions', 'status'])]
#[Hidden(['webhook_signing_secret'])]
class Workflow extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'start_conditions' => 'array',
            'status' => ActiveStatus::class,
            // SaaS.2: the HMAC secret its webhook nodes sign with (WorkflowWebhookSigning); never mass-assigned.
            'webhook_signing_secret' => 'encrypted',
        ];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'webhook_signing_secret'];
    }

    public function auditModule(): string
    {
        return 'workflow';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->key})";
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', VersionStatus::Draft);
    }

    public function published(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', VersionStatus::Published);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class)->latest('started_at');
    }
}

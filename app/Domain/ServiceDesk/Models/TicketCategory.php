<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request type with its SLA, default assignee / role, escalation role and optional workflow (§48). */
#[Fillable(['tenant_id', 'name', 'code', 'description', 'sla_hours', 'first_response_hours', 'default_assignee_id', 'assignee_role_id', 'escalation_role_id', 'workflow_key', 'sort_order', 'status'])]
class TicketCategory extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim((string) $c->code)));
    }

    protected function casts(): array
    {
        return ['sla_hours' => 'integer', 'first_response_hours' => 'integer', 'sort_order' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assignee_id');
    }

    public function assigneeRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assignee_role_id');
    }

    public function escalationRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'escalation_role_id');
    }
}

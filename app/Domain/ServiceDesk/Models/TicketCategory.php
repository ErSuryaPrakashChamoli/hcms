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
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A request type with its SLA, default assignee / role, escalation role and optional workflow (§48). HROne setup parity
 * HD-A: its purpose (peopleos.servicedesk.category_purposes), whether resolving needs a root cause (RCA) and whether the
 * requester rates the help when closing.
 */
#[Fillable(['tenant_id', 'name', 'code', 'description', 'purpose', 'rca_required', 'feedback_enabled', 'sla_hours', 'first_response_hours', 'default_assignee_id', 'assignee_role_id', 'escalation_role_id', 'workflow_key', 'sort_order', 'status'])]
class TicketCategory extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'purpose' => 'general', 'rca_required' => false, 'feedback_enabled' => true];

    protected static function booted(): void
    {
        static::saving(function (self $c): void {
            $c->code = strtoupper(trim((string) $c->code));
            $c->purpose = $c->purpose ?: 'general';
            if (! array_key_exists($c->purpose, (array) config('peopleos.servicedesk.category_purposes', []))) {
                throw new RuntimeException("Unknown category purpose [{$c->purpose}].");
            }
        });
    }

    protected function casts(): array
    {
        return ['sla_hours' => 'integer', 'first_response_hours' => 'integer', 'sort_order' => 'integer', 'rca_required' => 'boolean', 'feedback_enabled' => 'boolean', 'status' => ActiveStatus::class];
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

    /** The catalogue services (HROne sub-categories) filed under this category. */
    public function services(): HasMany
    {
        return $this->hasMany(ServiceDefinition::class, 'ticket_category_id');
    }
}

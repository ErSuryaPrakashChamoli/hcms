<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Role;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 12: an effective-dated SLA policy — per-priority first-response and resolution targets
 * (hours), business or calendar hours, the approaching-SLA warning threshold, escalation role /
 * repeat / maximum level, and optional overrides of the pause statuses and service hours.
 *
 * A request snapshots its due times when it is raised; a policy in use by an approved service version
 * is not edited (a change is a new policy row from a later date), so no open case is rewritten.
 */
#[Fillable(['tenant_id', 'code', 'name', 'calendar', 'targets', 'warn_percent', 'escalation_role_id', 'escalation_repeat_hours', 'max_escalation_level', 'pause_statuses', 'business_hours', 'effective_from', 'effective_to', 'status'])]
class ServiceSlaPolicy extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const CONTENT = ['code', 'calendar', 'targets', 'warn_percent', 'escalation_role_id', 'escalation_repeat_hours', 'max_escalation_level', 'pause_statuses', 'business_hours', 'effective_from'];

    protected $attributes = ['calendar' => 'business', 'warn_percent' => 75, 'escalation_repeat_hours' => 24, 'max_escalation_level' => 3, 'status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $policy): void {
            $policy->code = strtoupper(trim((string) $policy->code));
            if (! in_array($policy->calendar, ['business', 'calendar'], true)) {
                throw new RuntimeException('An SLA policy counts business or calendar hours.');
            }
            foreach ((array) $policy->targets as $priority => $target) {
                if (! array_key_exists($priority, config('peopleos.servicedesk.priorities')) || (float) ($target['resolution_hours'] ?? 0) <= 0) {
                    throw new RuntimeException("SLA target for [{$priority}] needs a known priority and positive resolution hours.");
                }
            }
        });
        static::updating(function (self $policy): void {
            $content = array_intersect(array_keys($policy->getDirty()), self::CONTENT);
            if ($content !== [] && ServiceDefinitionVersion::query()->where('sla_policy_id', $policy->id)->whereIn('status', [...ServiceDefinitionVersion::APPROVED, 'pending_approval'])->exists()) {
                throw new RuntimeException('This SLA policy is used by an approved service version; create a new policy from a later date ('.implode(', ', $content).').');
            }
        });
    }

    protected function casts(): array
    {
        return ['targets' => 'array', 'pause_statuses' => 'array', 'business_hours' => 'array', 'warn_percent' => 'integer', 'escalation_repeat_hours' => 'integer', 'max_escalation_level' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        return "SLA {$this->name} ({$this->code})";
    }

    public function escalationRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'escalation_role_id');
    }

    /** @return array{first_response_hours: float, resolution_hours: float} */
    public function targetFor(string $priority): array
    {
        $targets = (array) $this->targets;
        $target = $targets[$priority] ?? $targets['normal'] ?? reset($targets) ?: ['first_response_hours' => 8, 'resolution_hours' => 24];

        return ['first_response_hours' => (float) ($target['first_response_hours'] ?? 0), 'resolution_hours' => (float) $target['resolution_hours']];
    }

    /** @return list<string> */
    public function pauseStatuses(): array
    {
        return $this->pause_statuses ?: config('peopleos.servicedesk.sla_pause_statuses', []);
    }
}

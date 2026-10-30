<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity HD-B (Helpdesk → Resolver rules): a named routing rule. It applies to requests in its categories
 * and services (HROne sub-categories), optionally only for employees of its companies and locations (an empty list
 * means any), between its dates. A matching rule (ResolverRules::match: lowest priority number, then the most specific)
 * names the resolving team (role) and optionally a default resolver, a role to intimate when the request is assigned,
 * and the SLA policy whose targets, service days and working hours apply.
 */
#[Fillable(['tenant_id', 'name', 'priority', 'category_ids', 'service_ids', 'company_ids', 'location_ids', 'assignee_role_id', 'assignee_user_id', 'notify_role_id', 'sla_policy_id', 'effective_from', 'effective_to', 'status'])]
class ServiceResolverRule extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const SCOPES = ['category_ids', 'service_ids', 'company_ids', 'location_ids'];

    protected $attributes = ['priority' => 100, 'status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            foreach (self::SCOPES as $key) {
                $ids = array_values(array_unique(array_map('intval', array_filter((array) ($rule->{$key} ?? []), fn ($v) => $v !== null && $v !== ''))));
                sort($ids);
                $rule->{$key} = $ids === [] ? null : $ids;
            }
            if (blank($rule->name)) {
                throw new RuntimeException('A resolver rule needs a name.');
            }
            if (blank($rule->assignee_role_id)) {
                throw new RuntimeException('Choose the role the requests are assigned to.');
            }
            if (! in_array($rule->status, ['active', 'inactive'], true)) {
                throw new RuntimeException('A resolver rule is active or inactive.');
            }
            if ($rule->effective_from !== null && $rule->effective_to !== null && $rule->effective_to->lt($rule->effective_from)) {
                throw new RuntimeException('A resolver rule cannot end before it starts.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'priority' => 'integer', 'category_ids' => 'array', 'service_ids' => 'array', 'company_ids' => 'array', 'location_ids' => 'array',
            'effective_from' => 'date', 'effective_to' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        return "Resolver rule {$this->name}";
    }

    public function assigneeRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assignee_role_id');
    }

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function notifyRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'notify_role_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(ServiceSlaPolicy::class, 'sla_policy_id');
    }

    /** The rule's SLA policy while it is active (an inactive one leaves the service's or category's SLA in place). */
    public function activeSlaPolicy(): ?ServiceSlaPolicy
    {
        $policy = $this->slaPolicy;

        return $policy !== null && $policy->status === 'active' ? $policy : null;
    }

    /** How specific the rule is: a service beats a category beats any; then location, then company. */
    public function specificity(): int
    {
        return ($this->service_ids ? 8 : 0) + ($this->category_ids ? 4 : 0) + ($this->location_ids ? 2 : 0) + ($this->company_ids ? 1 : 0);
    }

    /** @param  array{category_id: ?int, service_id: ?int, company_id: ?int, location_id: ?int}  $request */
    public function matches(array $request): bool
    {
        foreach (['category_ids' => 'category_id', 'service_ids' => 'service_id', 'company_ids' => 'company_id', 'location_ids' => 'location_id'] as $list => $key) {
            $ids = array_map('intval', (array) ($this->{$list} ?? []));
            if ($ids !== [] && ! in_array((int) ($request[$key] ?? 0), $ids, true)) {
                return false;
            }
        }

        return true;
    }
}

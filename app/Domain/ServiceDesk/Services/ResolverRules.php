<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Models\ServiceResolverRule;
use App\Domain\ServiceDesk\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HD-B: which resolver rule routes a new request, and who is intimated when it is assigned.
 *
 * Matching: active rules in force on the day, whose categories, services, companies and locations (each empty = any)
 * contain the request's category, service and the employee's current company and location. The lowest priority number
 * wins, then the most specific rule (service > category > any, then location, then company), then the oldest.
 * With no rule, routing stays with the service version and the category (CaseAssignment::initial, as before).
 */
final class ResolverRules
{
    /** At most this many members of an intimation role are notified. */
    public const INTIMATION_CAP = 25;

    public function __construct(private readonly CaseAccess $access) {}

    public function match(Ticket $ticket, CarbonInterface|string|null $on = null): ?ServiceResolverRule
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $rules = ServiceResolverRule::query()->with('slaPolicy')->where('status', 'active')->effectiveOn($day)->orderBy('priority')->orderBy('id')->get();
        if ($rules->isEmpty()) {
            return null;
        }
        $position = $ticket->employee_id
            ? Employee::query()->withoutGlobalScope(AccessScope::class)->find($ticket->employee_id)?->positions()->withoutGlobalScope(AccessScope::class)->effectiveOn($day)->first()
            : null;
        $request = [
            'category_id' => $ticket->getAttributes()['ticket_category_id'] ?? null,
            'service_id' => $ticket->getAttributes()['service_definition_id'] ?? null,
            'company_id' => $position?->company_id,
            'location_id' => $position?->location_id,
        ];

        return $rules->filter(fn (ServiceResolverRule $rule) => $rule->matches($request))
            ->sortBy(fn (ServiceResolverRule $rule) => [$rule->priority, -$rule->specificity(), $rule->id])
            ->first();
    }

    /**
     * Members of the routing rule's intimation role who may see the request (active, never its subject), capped.
     *
     * @param  list<int>  $except  already notified (the assignee, the person acting)
     * @return list<int>
     */
    public function intimationRecipients(Ticket $ticket, array $except = []): array
    {
        $ruleId = $ticket->getAttributes()['resolver_rule_id'] ?? null;
        $roleId = $ruleId ? ServiceResolverRule::query()->whereKey($ruleId)->value('notify_role_id') : null;
        $role = $roleId ? Role::query()->find($roleId) : null;
        if ($role === null) {
            return [];
        }

        return $role->users()->get()
            ->reject(fn (User $u) => in_array((int) $u->id, array_map('intval', $except), true))
            ->filter(fn (User $u) => $u->isActive() && ! $this->access->isOwn($u, $ticket) && $this->access->canView($u, $ticket))
            ->take(self::INTIMATION_CAP)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}

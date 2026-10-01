<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\WorkforceCostReader;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 10 workforce budgets: an amount for a scope and period, in one currency, on one declared cost
 * basis. Planned cost comes from plan lines on the same basis; actual cost comes from the Payroll read
 * contract (finalized / paid employer cost) and is compared only with an employer-cost budget in the
 * same currency. No accounting, no compensation, no payroll write. Everything here needs workforce.costs.
 */
final class WorkforceBudgets
{
    public function __construct(private readonly OrganisationDimensions $dimensions, private readonly WorkforceCostReader $costs, private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): WorkforceBudget
    {
        $this->authorise($actor, ['workforce.plan', 'workforce.costs']);
        foreach (['name', 'period_start', 'period_end', 'cost_basis', 'amount'] as $required) {
            if (blank($data[$required] ?? null) && ($data[$required] ?? null) !== 0) {
                throw new RuntimeException("A budget needs {$required}.");
            }
        }
        $node = $this->dimensions->forNode(filled($data['organisation_node_id'] ?? null) ? (int) $data['organisation_node_id'] : null);
        $companyId = (int) ($data['company_id'] ?? $node['company_id'] ?? 0);
        if (! $companyId || ($node['company_id'] && (int) $node['company_id'] !== $companyId)) {
            throw new RuntimeException('A budget belongs to one company and its organisation units.');
        }

        return WorkforceBudget::query()->create([
            ...array_intersect_key($data, array_flip(['workforce_plan_version_id', 'organisation_node_id', 'cost_centre_id', 'location_id', 'name', 'period_start', 'period_end', 'cost_basis', 'amount', 'notes'])),
            ...array_intersect_key($node, array_flip(OrganisationDimensions::UNIT_COLUMNS)),
            'company_id' => $companyId, 'currency' => strtoupper((string) ($data['currency'] ?? Company::query()->whereKey($companyId)->value('currency') ?? 'INR')), 'created_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(WorkforceBudget $budget, array $data, User $actor): WorkforceBudget
    {
        $this->authorise($actor, ['workforce.plan', 'workforce.costs']);
        $budget->update(array_intersect_key($data, array_flip(['name', 'period_start', 'period_end', 'cost_basis', 'amount', 'currency', 'notes'])));

        return $budget;
    }

    public function approve(WorkforceBudget $budget, User $actor): WorkforceBudget
    {
        $this->authorise($actor, ['workforce.approve', 'workforce.costs']);
        if ((int) $budget->created_by === (int) $actor->id) {
            throw new RuntimeException('The person who prepared a budget cannot approve it.');
        }

        return DB::transaction(function () use ($budget, $actor) {
            $current = WorkforceBudget::query()->withoutGlobalScope(AccessScope::class)->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw new RuntimeException('Only a draft budget is approved.');
            }
            $budget->setRawAttributes($current->getAttributes(), true);
            $budget->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record(AuditAction::Approved, 'workforce', $budget, [['field' => 'status', 'before' => 'draft', 'after' => 'approved']], null, actor: $actor, metadata: ['event' => 'budget_approved']);
            WorkforceEvent::dispatch('workforce.budget.approved', null, $budget, ['name' => $budget->name], array_filter([(int) $budget->created_by]));

            return $budget;
        });
    }

    public function supersede(WorkforceBudget $budget, string $reason, User $actor): WorkforceBudget
    {
        $this->authorise($actor, ['workforce.approve', 'workforce.costs']);
        if (trim($reason) === '') {
            throw new RuntimeException('Superseding a budget needs a reason.');
        }
        $budget->withAuditReason($reason)->update(['status' => 'superseded']);

        return $budget;
    }

    /**
     * Planned, budget and actual cost with variances — each labelled with its basis; amounts on
     * different bases or currencies are never compared.
     *
     * @return array<string, mixed>
     */
    public function comparison(WorkforceBudget $budget, User $viewer): array
    {
        $this->authorise($viewer, ['workforce.costs']);
        $planned = $budget->workforce_plan_version_id ? WorkforcePlanLine::query()->where('workforce_plan_version_id', $budget->workforce_plan_version_id)
            ->where('cost_basis', $budget->cost_basis)->get()->sum(fn (WorkforcePlanLine $l) => $l->sign() * (float) $l->planned_cost) : null;
        $actual = null;
        $note = null;
        if ($budget->cost_basis === 'employer_cost') {
            $employees = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->select('employee_id')->where('company_id', $budget->company_id)
                ->where('effective_from', '<=', $budget->period_end->toDateString().' 23:59:59')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $budget->period_start->toDateString()))
                ->when($budget->cost_centre_id, fn ($q, $id) => $q->where('cost_centre_id', $id))
                ->when($budget->location_id, fn ($q, $id) => $q->where('location_id', $id));
            foreach (OrganisationDimensions::UNIT_COLUMNS as $column) {
                $employees->when($budget->getAttribute($column), fn ($q, $id) => $q->where($column, $id));
            }
            $cost = $this->costs->employerCost((int) $budget->company_id, $budget->period_start, $budget->period_end, $employees);
            $minGroup = max(1, (int) config('peopleos.workforce.analytics_min_group', 5));
            if ($cost['employees'] > 0 && $cost['employees'] < $minGroup) {
                $note = "Actual cost covers fewer than {$minGroup} employees and is suppressed (it would disclose individual pay).";
            } elseif ($cost['currency'] !== null && strtoupper($cost['currency']) !== $budget->currency) {
                $note = "Actual payroll cost is in {$cost['currency']}; the budget is in {$budget->currency} and is not compared.";
            } else {
                $actual = $cost['amount'];
            }
        } else {
            $note = 'Actual cost is employer cost from finalized payroll; this budget is on another basis ('.config("peopleos.workforce.cost_bases.{$budget->cost_basis}").') and is not compared.';
        }

        return [
            'currency' => $budget->currency, 'cost_basis' => $budget->cost_basis, 'budget' => (float) $budget->amount,
            'planned' => $planned === null ? null : round($planned, 2), 'actual' => $actual,
            'budget_vs_planned' => $planned === null ? null : round((float) $budget->amount - $planned, 2),
            'budget_vs_actual' => $actual === null ? null : round((float) $budget->amount - $actual, 2),
            'note' => $note,
        ];
    }

    /** @param  list<string>  $permissions */
    private function authorise(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $actor->hasPermission($permission)) {
                throw new RuntimeException("This needs {$permission}.");
            }
        }
    }
}

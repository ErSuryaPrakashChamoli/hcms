<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Services\ChecksOrganisationScope;
use App\Domain\Workforce\Services\OrganisationDimensions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 compensation budgets (§16). One declared basis only: the annualised CTC increase of the
 * compensation changes charged to the budget (new annual CTC − annual CTC in force before the change).
 * Gross pay, employer cost and payroll cost are different measures and are never mixed in.
 *
 *  - Budget:    the approved amount.
 *  - Planned:   increases still being prepared or decided (draft, submitted, under review).
 *  - Approved:  increases approved but not yet executed.
 *  - Committed: increases executed into the compensation timeline (scheduled or effective).
 *  - Actual:    committed increases already in force on the as-of date.
 *  - Variance:  budget − (approved + committed); negative never happens (approval refuses it).
 *
 * Approving a change charged to a budget locks the budget row and refuses an increase larger than
 * what is left, so two approvals cannot both spend the last of it.
 */
final class CompensationBudgets
{
    use ChecksOrganisationScope;

    public function __construct(private readonly OrganisationDimensions $dimensions, private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): CompensationBudget
    {
        $this->authorise($actor, 'compensation.budget');
        foreach (['code', 'name', 'company_id', 'period_start', 'period_end', 'amount'] as $field) {
            if (blank($data[$field] ?? null) && ($data[$field] ?? null) !== 0) {
                throw new CompensationRuleViolation("A budget needs {$field}.");
            }
        }
        $node = $this->dimensions->forNode(filled($data['organisation_node_id'] ?? null) ? (int) $data['organisation_node_id'] : null);
        $companyId = (int) $data['company_id'];
        if ($node['company_id'] && (int) $node['company_id'] !== $companyId) {
            throw new CompensationRuleViolation('A budget belongs to one company and its organisation units.');
        }
        $this->assertDimensionsInScope($actor, ['company_id' => $companyId, 'location_id' => $data['location_id'] ?? null, ...array_intersect_key($node, array_flip(OrganisationDimensions::UNIT_COLUMNS))]);
        $currency = strtoupper((string) ($data['currency'] ?? Company::query()->whereKey($companyId)->value('currency') ?? 'INR'));
        if (! in_array($currency, config('peopleos.compensation.currencies', []), true)) {
            throw new CompensationRuleViolation("[{$currency}] is not a supported ISO 4217 currency.");
        }
        if (! is_numeric($data['amount']) || (float) $data['amount'] < 0) {
            throw new CompensationRuleViolation('A budget amount cannot be negative.');
        }
        if (Carbon::parse($data['period_end'])->lt(Carbon::parse($data['period_start']))) {
            throw new CompensationRuleViolation('The budget period ends before it starts.');
        }
        if (filled($data['workforce_budget_id'] ?? null) && ! WorkforceBudget::query()->whereKey($data['workforce_budget_id'])->exists()) {
            throw new CompensationRuleViolation('That workforce budget does not exist.');
        }

        return CompensationBudget::query()->create([
            'code' => strtoupper(trim((string) $data['code'])), 'name' => $data['name'], 'company_id' => $companyId,
            ...array_intersect_key($node, array_flip(OrganisationDimensions::UNIT_COLUMNS)),
            'organisation_node_id' => $data['organisation_node_id'] ?? null, 'location_id' => $data['location_id'] ?? null,
            'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'currency' => $currency,
            'amount' => round((float) $data['amount'], 2), 'workforce_budget_id' => $data['workforce_budget_id'] ?? null,
            'notes' => $data['notes'] ?? null, 'prepared_by' => $actor->id,
        ]);
    }

    public function approve(CompensationBudget $budget, User $actor): CompensationBudget
    {
        $this->authorise($actor, 'compensation.budget');
        $this->authorise($actor, 'compensation.approve');
        if ((int) $budget->prepared_by === (int) $actor->id) {
            throw new CompensationRuleViolation('The person who prepared a budget cannot approve it.');
        }

        return DB::transaction(function () use ($budget, $actor) {
            $current = CompensationBudget::query()->withoutGlobalScope(AccessScope::class)->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $this->assertRecordInScope($actor, $current);
            if ($current->status !== 'draft') {
                throw new CompensationRuleViolation('Only a draft budget is approved.');
            }
            $budget->setRawAttributes($current->getAttributes(), true);
            $budget->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'lock_version' => $current->lock_version + 1]);
            $this->audit->record(AuditAction::Approved, 'compensation', $budget, [['field' => 'status', 'before' => 'draft', 'after' => 'approved']], null, actor: $actor);

            return $budget;
        });
    }

    public function close(CompensationBudget $budget, User $actor, string $reason): CompensationBudget
    {
        $this->authorise($actor, 'compensation.budget');
        if (trim($reason) === '') {
            throw new CompensationRuleViolation('Closing a budget needs a reason.');
        }
        $budget->withAuditReason($reason)->update(['status' => 'closed']);

        return $budget;
    }

    /**
     * Charge an approval to the budget. Called by CompensationChanges inside the approval transaction:
     * the budget row is locked (FOR UPDATE, a current read) and its running charged_amount is checked
     * and moved, so two approvals cannot both spend the last of it. No other change row is read with a
     * lock (that would deadlock two approvers, each holding their own change row).
     */
    public function charge(CompensationChange $change): void
    {
        $budget = $this->lockBudget($change);
        if ($budget->status !== 'approved') {
            throw new CompensationRuleViolation("Budget {$budget->code} is not approved (it is {$budget->status}).");
        }
        if ($change->currency !== $budget->currency || ($change->previous_currency !== null && $change->previous_currency !== $budget->currency)) {
            throw new CompensationRuleViolation("Budget {$budget->code} is in {$budget->currency}; this change is in {$change->currency} and cannot be charged to it.");
        }
        if (! $change->effective_from->betweenIncluded($budget->period_start, $budget->period_end)) {
            throw new CompensationRuleViolation("The change takes effect outside budget {$budget->code}'s period.");
        }
        $increase = $change->annualIncrease();
        $charged = (float) $budget->charged_amount;
        if ($increase > 0 && round($charged + $increase, 2) > (float) $budget->amount) {
            throw new CompensationRuleViolation('This increase of '.number_format($increase, 2).' would exceed budget '.$budget->code.' ('.number_format(max(0, (float) $budget->amount - $charged), 2).' left).');
        }
        $budget->update(['charged_amount' => round($charged + $increase, 2)]);
    }

    /** An approved change charged to the budget was cancelled: give its increase back (same lock). */
    public function release(CompensationChange $change): void
    {
        $budget = $this->lockBudget($change);
        $budget->update(['charged_amount' => round((float) $budget->charged_amount - $change->annualIncrease(), 2)]);
    }

    private function lockBudget(CompensationChange $change): CompensationBudget
    {
        return CompensationBudget::query()->withoutGlobalScope(AccessScope::class)->whereKey($change->compensation_budget_id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, mixed> every measure on the one basis, labelled */
    public function measures(CompensationBudget $budget, ?string $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? now())->toDateString();
        $rows = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_budget_id', $budget->id)
            ->selectRaw('status, SUM(ctc_annual - COALESCE(previous_ctc_annual, ctc_annual)) AS increase, COUNT(*) AS changes')
            ->groupBy('status')->toBase()->get()->keyBy('status');
        $sum = fn (array $statuses) => round((float) collect($statuses)->sum(fn ($s) => (float) ($rows[$s]->increase ?? 0)), 2);
        $actual = (float) CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_budget_id', $budget->id)
            ->whereIn('status', ['scheduled', 'effective'])->where('effective_from', '<=', $asOf.' 23:59:59')
            ->selectRaw('SUM(ctc_annual - COALESCE(previous_ctc_annual, ctc_annual)) AS increase')->toBase()->value('increase');
        $approved = $sum(['approved']);
        $committed = $sum(['scheduled', 'effective']);

        return [
            'basis' => 'annual_ctc_increase', 'basis_label' => 'Annualised CTC increase (new annual CTC − annual CTC before the change)',
            'currency' => $budget->currency, 'budget' => (float) $budget->amount,
            'planned' => $sum(['draft', 'submitted', 'under_review']), 'approved' => $approved, 'committed' => $committed,
            'actual' => round($actual, 2), 'variance' => round((float) $budget->amount - $approved - $committed, 2), 'as_of' => $asOf,
            'charged' => (float) $budget->charged_amount,
            'changes' => (int) $rows->sum('changes'),
        ];
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new CompensationRuleViolation("This needs {$permission}.");
        }
    }
}

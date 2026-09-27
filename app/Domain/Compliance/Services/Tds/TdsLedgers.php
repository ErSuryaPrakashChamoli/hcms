<?php

namespace App\Domain\Compliance\Services\Tds;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\TdsAnnualLedger;
use App\Domain\Compliance\Models\TdsFinancialYear;
use App\Domain\Compliance\Models\TdsQuarterlyReturnEntry;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\Returns\StatutoryPayrollSource;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\LegalEntity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Part L annual TDS ledger per legal entity and financial year, built from FINALIZED payroll only.
 * The monthly deduction itself stays in the payroll engine (frozen); this ledger records what
 * payroll deducted, by employee and month, and is the only source for Form No. 138 statements
 * and Form No. 130 certificates.
 */
final class TdsLedgers
{
    public function __construct(private readonly StatutoryPayrollSource $source, private readonly FinancialYear $years, private readonly AuditRecorder $audit) {}

    public function year(LegalEntity $entity, string $financialYear): TdsFinancialYear
    {
        $start = Carbon::create((int) substr($financialYear, 0, 4), (int) config('peopleos.compliance.financial_year_start_month', 4), 1);

        return TdsFinancialYear::query()->firstOrCreate(
            ['legal_entity_id' => $entity->getKey(), 'financial_year' => $financialYear],
            ['start_date' => $start->toDateString(), 'end_date' => $this->years->end($start)->toDateString(), 'status' => 'open'],
        );
    }

    /** Bring the ledger up to date with finalized payroll. Idempotent. @return array{added: int, superseded: int, withdrawn: int} */
    public function sync(LegalEntity $entity, string $financialYear): array
    {
        $year = $this->year($entity, $financialYear);
        $counts = ['added' => 0, 'superseded' => 0, 'withdrawn' => 0];

        return DB::transaction(function () use ($entity, $year, $financialYear, &$counts) {
            $runs = $this->source->runs($entity->company_id, $year->start_date, $year->end_date);
            $rows = $this->source->entries($runs, null, $entity->getKey());
            $seen = [];

            foreach ($rows as $row) {
                $payroll = $row['entry'];
                $month = $payroll->run->period->start_date;
                $key = $payroll->payroll_run_id.':'.$payroll->employee_id;
                $seen[$key] = true;
                $tds = $payroll->lines->firstWhere('code', 'TDS');
                $basis = (array) ($tds->basis ?? []);
                $rule = isset($basis['rule_id']) ? ComplianceRule::query()->find($basis['rule_id']) : null;
                $values = [
                    'gross' => round((float) $payroll->gross, 2),
                    'taxable_earnings' => round((float) $payroll->taxable_earnings, 2),
                    'tds_deducted' => round((float) ($tds?->amount ?? 0), 2),
                    'rule_checksum' => $basis['rule_checksum'] ?? $rule?->checksum,
                ];

                $active = TdsAnnualLedger::query()->withoutGlobalScopes()->where('active_source_key', $key)->first();
                if ($active !== null && $this->same($active, $values)) {
                    if ((int) $active->payroll_entry_id !== (int) $payroll->getKey()) {
                        $active->update(['payroll_entry_id' => $payroll->getKey()]);
                    }

                    continue;
                }

                $new = TdsAnnualLedger::query()->create([
                    'legal_entity_id' => $entity->getKey(),
                    'employee_id' => $payroll->employee_id,
                    'financial_year' => $financialYear,
                    'month' => $month->toDateString(),
                    'quarter' => (int) ceil($this->years->monthIndex($month) / 3),
                    'payroll_run_id' => $payroll->payroll_run_id,
                    'payroll_entry_id' => $payroll->getKey(),
                    'source_key' => $key,
                    'active_source_key' => $active === null ? $key : null,
                    'tax_regime' => $payroll->inputs['tax']['regime'] ?? $payroll->employee?->statutoryDetail?->tax_regime,
                    'pan_available' => filled($payroll->employee?->statutoryDetail?->pan),
                    'compliance_rule_id' => $rule?->getKey(),
                    'rule_version' => $rule?->version,
                    'basis' => $payroll->inputs['tax'] ?? null,
                    'status' => 'active',
                    'created_at' => now(),
                ] + $values);

                if ($active !== null) {
                    $active->update(['status' => 'superseded', 'superseded_by_id' => $new->getKey(), 'active_source_key' => null]);
                    $new->update(['active_source_key' => $key]);
                    $counts['superseded']++;
                }
                $counts['added']++;
            }

            // Rows whose payroll run is no longer finalized (reopened) are withdrawn, never deleted.
            TdsAnnualLedger::query()->withoutGlobalScopes()->where('legal_entity_id', $entity->getKey())->where('financial_year', $financialYear)
                ->where('status', 'active')->get()
                ->reject(fn (TdsAnnualLedger $row) => isset($seen[$row->source_key]))
                ->each(function (TdsAnnualLedger $row) use (&$counts) {
                    $row->update(['status' => 'withdrawn', 'active_source_key' => null]);
                    $counts['withdrawn']++;
                });

            if ($year->ledger_verified_at !== null && ($counts['added'] + $counts['withdrawn']) > 0) {
                $year->update(['ledger_verified_at' => null, 'ledger_verified_by' => null, 'ledger_checksum' => null]);
            }

            return $counts;
        });
    }

    /** @return Collection<int, TdsAnnualLedger> */
    public function active(LegalEntity $entity, string $financialYear, ?int $quarter = null): Collection
    {
        return TdsAnnualLedger::query()->withoutGlobalScopes()->with('employee.person', 'employee.statutoryDetail')
            ->where('legal_entity_id', $entity->getKey())->where('financial_year', $financialYear)->where('status', 'active')
            ->when($quarter, fn ($q) => $q->where('quarter', $quarter))
            ->orderBy('month')->orderBy('employee_id')->get();
    }

    public function checksum(LegalEntity $entity, string $financialYear): string
    {
        return hash('sha256', (string) json_encode($this->active($entity, $financialYear)->map(fn ($r) => [$r->id, $r->employee_id, $r->month?->toDateString(), (string) $r->taxable_earnings, (string) $r->tds_deducted, $r->rule_checksum])->all()));
    }

    /**
     * Verify the annual ledger: every quarter's Form No. 138 statement is acknowledged (or
     * reconciled) and carries exactly the active ledger rows. Certificates need this.
     */
    public function verify(LegalEntity $entity, string $financialYear, User $actor): TdsFinancialYear
    {
        if (! $actor->hasPermission('compliance.tds.manage')) {
            throw new RuntimeException('You do not have the compliance.tds.manage permission.');
        }

        $this->sync($entity, $financialYear);
        $year = $this->year($entity, $financialYear);
        $problems = [];

        foreach ([1, 2, 3, 4] as $quarter) {
            $return = StatutoryReturn::query()->where('return_type', 'TDS')->where('legal_entity_id', $entity->getKey())
                ->where('period_key', "{$financialYear}-Q{$quarter}")->whereIn('status', [StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILED])
                ->orderByDesc('id')->first();

            if ($return === null) {
                $problems[] = "Q{$quarter} statement is not acknowledged";

                continue;
            }

            $filed = TdsQuarterlyReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->pluck('tds_annual_ledger_id')->sort()->values()->all();
            $ledger = $this->active($entity, $financialYear, $quarter)->where('tds_deducted', '>', 0)->pluck('id')->sort()->values()->all();
            if ($filed !== $ledger) {
                $problems[] = "Q{$quarter} statement does not match the current ledger";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('The annual TDS ledger cannot be verified: '.implode('; ', $problems).'.');
        }

        $year->update(['ledger_verified_at' => now(), 'ledger_verified_by' => $actor->getKey(), 'ledger_checksum' => $this->checksum($entity, $financialYear)]);
        $this->audit->record(AuditAction::StatutoryOutputReconciled, 'compliance', $year, [['field' => 'ledger_verified_at', 'before' => null, 'after' => (string) $year->ledger_verified_at, 'sensitive' => false]], 'Annual TDS ledger verified against acknowledged Form No. 138 statements', metadata: ['financial_year' => $financialYear, 'checksum' => $year->ledger_checksum], actor: $actor);

        return $year;
    }

    /** @param  array<string, mixed>  $values */
    private function same(TdsAnnualLedger $row, array $values): bool
    {
        return round((float) $row->gross, 2) === $values['gross'] && round((float) $row->taxable_earnings, 2) === $values['taxable_earnings']
            && round((float) $row->tds_deducted, 2) === $values['tds_deducted'] && $row->rule_checksum === $values['rule_checksum'];
    }
}

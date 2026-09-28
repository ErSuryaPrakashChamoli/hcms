<?php

namespace App\Domain\Compliance\Services\Tds;

use App\Domain\Compliance\Contracts\StatutoryReturnGenerator;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\TdsAnnualLedger;
use App\Domain\Compliance\Models\TdsProfile;
use App\Domain\Compliance\Models\TdsQuarterlyReturn;
use App\Domain\Compliance\Models\TdsQuarterlyReturnEntry;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\ExportLayouts;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Compliance\Services\Returns\StatutorySnapshots;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Quarterly TDS statement on salary (Phase 5 Part L): Form No. 138 (earlier Form 24Q), filed under
 * Rule 219 of the Income-tax Rules, 2026 (Income Tax Department). Annexure-I every quarter,
 * Annexure-II in Q4. Built only from the annual ledger (finalized payroll). The official file
 * (utility / FVU) schema has not been verified, so the export is a working schedule and the
 * return always carries that warning: it is not a filing-ready file.
 */
final class TdsQuarterlyReturns implements StatutoryReturnGenerator
{
    public const TYPE = 'TDS';

    public function __construct(
        private readonly StatutoryReturns $returns,
        private readonly TdsLedgers $ledgers,
        private readonly ComplianceRules $rules,
        private readonly StatutorySnapshots $snapshots,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @param  list<array{bsr_code: string, deposit_date: string, challan_serial: string, amount: float|int|string}>  $challans
     */
    public function generate(LegalEntity $entity, string $financialYear, int $quarter, User $actor, array $challans = [], ?string $reason = null, string $source = 'ui'): StatutoryReturn
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new RuntimeException('A financial year has quarters 1 to 4.');
        }

        $fyStart = Carbon::create((int) substr($financialYear, 0, 4), (int) config('peopleos.compliance.financial_year_start_month', 4), 1);
        $start = $fyStart->copy()->addMonthsNoOverflow(($quarter - 1) * 3);

        $return = $this->returns->generate([
            'company_id' => $entity->company_id,
            'legal_entity_id' => $entity->getKey(),
            'establishment_id' => null,
            'return_type' => self::TYPE,
            'form_code' => 'FORM_138',
            'legacy_form_code' => 'FORM_24Q',
            'return_kind' => 'regular',
            'period_key' => "{$financialYear}-Q{$quarter}",
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->addMonthsNoOverflow(2)->endOfMonth()->toDateString(),
            'sequence' => 1,
            ...app(ExportLayouts::class)->headerFor('TDS_FORM_138'),
            'attestations' => ['challans' => array_values($challans)],
        ], $actor, $source, $reason);

        return $return;
    }

    public function build(StatutoryReturn $return): void
    {
        $entity = LegalEntity::query()->withoutGlobalScopes()->findOrFail($return->legal_entity_id);
        [$financialYear, $quarterLabel] = explode('-Q', $return->period_key);
        $quarter = (int) $quarterLabel;
        $this->ledgers->sync($entity, $financialYear);

        if ($existing = TdsQuarterlyReturn::query()->where('statutory_return_id', $return->getKey())->first()) {
            TdsQuarterlyReturnEntry::query()->withoutGlobalScopes()->where('tds_quarterly_return_id', $existing->getKey())->get()->each->delete();
            $existing->delete();
        }

        $profile = TdsProfile::query()->withoutGlobalScopes()->where('legal_entity_id', $entity->getKey())->first();
        $run = TdsQuarterlyReturn::query()->create([
            'statutory_return_id' => $return->getKey(),
            'legal_entity_id' => $entity->getKey(),
            'tan_registration_id' => $profile?->tan_registration_id,
            'financial_year' => $financialYear,
            'quarter' => $quarter,
            'challans' => $return->attestations['challans'] ?? [],
        ]);

        $section = config('peopleos.compliance.formats.TDS_FORM_138.section_code');
        $ruleVersions = [];
        $ledger = $this->ledgers->active($entity, $financialYear, $quarter)->filter(fn (TdsAnnualLedger $r) => (float) $r->tds_deducted > 0);

        foreach ($ledger as $row) {
            $pan = $row->employee?->statutoryDetail?->pan ? strtoupper(preg_replace('/\s+/', '', (string) $row->employee->statutoryDetail->pan)) : null;
            TdsQuarterlyReturnEntry::query()->create([
                'tds_quarterly_return_id' => $run->getKey(),
                'statutory_return_id' => $return->getKey(),
                'employee_id' => $row->employee_id,
                'tds_annual_ledger_id' => $row->getKey(),
                'pan' => $pan,
                'pan_hash' => $pan ? hash_hmac('sha256', $pan, (string) config('app.key')) : null,
                'pan_last4' => $pan ? substr($pan, -4) : null,
                'member_name' => mb_strtoupper(trim((string) ($row->employee?->person?->display_name ?? $row->employee?->employee_code))),
                'section_code' => $section,
                'payment_date' => $row->month->copy()->endOfMonth()->toDateString(),
                'calc_amount_paid' => (float) $row->taxable_earnings,
                'calc_tax_deducted' => (float) $row->tds_deducted,
                'export_amount_paid' => round((float) $row->taxable_earnings, 2),
                'export_tax_deducted' => round((float) $row->tds_deducted, 2),
                'reason_code' => $pan ? null : 'NO_PAN',
                'compliance_rule_id' => $row->compliance_rule_id,
                'rule_version' => $row->rule_version,
                'rule_checksum' => $row->rule_checksum,
            ]);

            if ($row->compliance_rule_id && ($rule = ComplianceRule::query()->find($row->compliance_rule_id))) {
                $ruleVersions[$rule->getKey()] = ['rule_code' => $rule->code, 'rule_version' => $rule->version, 'verification_status' => $rule->verification_status, 'rule_checksum' => $rule->checksum];
            }
        }

        $annexureII = null;
        if ($quarter === 4) {
            $annexureII = $this->ledgers->active($entity, $financialYear)->groupBy('employee_id')->map(function ($rows) {
                $last = $rows->sortBy('month')->last();

                return [
                    'employee_id' => $last->employee_id,
                    'gross_salary' => round((float) $rows->sum('gross'), 2),
                    'taxable_salary_paid' => round((float) $rows->sum('taxable_earnings'), 2),
                    'tds_deducted' => round((float) $rows->sum('tds_deducted'), 2),
                    'regime' => $last->tax_regime,
                    'computation' => collect($last->basis ?? [])->only(['projected_gross', 'hra_exempt', 'standard_deduction', 'chapter_via', 'taxable_income', 'rebate', 'surcharge', 'cess', 'annual_tax'])->all(),
                ];
            })->values()->all();
        }

        $entries = TdsQuarterlyReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get();
        $totals = [
            'entries' => $entries->count(),
            'deductees' => $entries->pluck('employee_id')->unique()->count(),
            'amount_paid' => round((float) $entries->sum('calc_amount_paid'), 2),
            'tax_deducted' => round((float) $entries->sum('calc_tax_deducted'), 2),
            'tax_deposited' => round((float) collect($run->challans)->sum(fn ($c) => (float) ($c['amount'] ?? 0)), 2),
        ];

        $run->update(['deductee_count' => $totals['deductees'], 'totals' => $totals, 'annexure_ii' => $annexureII]);
        $return->update(['totals' => $totals, 'rule_versions' => $ruleVersions, 'payroll_run_ids' => $ledger->pluck('payroll_run_id')->unique()->values()->all()]);
    }

    public function validate(StatutoryReturn $return): array
    {
        $issues = [];
        $add = function (string $code, string $severity, string $message, ?int $employeeId = null) use (&$issues) {
            $issues[] = ['code' => $code, 'severity' => $severity, 'message' => $message, 'employee_id' => $employeeId];
        };
        $run = TdsQuarterlyReturn::query()->where('statutory_return_id', $return->getKey())->firstOrFail();
        $profile = TdsProfile::query()->withoutGlobalScopes()->where('legal_entity_id', $return->legal_entity_id)->first();
        $entries = TdsQuarterlyReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get();
        $enforced = ComplianceRules::enforced();

        if ($profile === null) {
            $add('missing_tds_profile', 'blocking', 'The legal entity has no TDS deductor profile.');
        } else {
            if ($run->tan_registration_id === null) {
                $add('missing_registration', 'blocking', 'The TDS profile has no TAN registration.');
            }
            if (blank($profile->responsible_person_name) || blank($profile->responsible_person_designation)) {
                $add('missing_responsible_person', 'blocking', 'The person responsible for deduction (name and designation) is missing.');
            }
        }
        if ($return->period_end->gt(now())) {
            $add('invalid_period', 'blocking', "{$return->period_key} has not ended yet.");
        }

        foreach ($entries as $entry) {
            $entryIssues = [];
            $flag = function (string $code, string $severity, string $message) use (&$entryIssues, $add, $entry) {
                $entryIssues[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
                $add($code, $severity, "{$entry->member_name}: {$message}", $entry->employee_id);
            };

            if ($entry->pan === null) {
                $flag('pan_missing', 'warning', 'no PAN; reported as PAN NOT AVAILABLE with the higher-deduction reason.');
            } elseif (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $entry->pan)) {
                $flag('invalid_pan', 'blocking', 'PAN is not in the 10-character format.');
            }
            if ($entry->compliance_rule_id === null) {
                $flag('invalid_basis', 'blocking', 'the TDS line has no rule reference.');
            } elseif (($rule = ComplianceRule::query()->find($entry->compliance_rule_id)) && ! $rule->isVerified()) {
                $flag('rule_unverified', $enforced ? 'blocking' : 'warning', "{$rule->label()} is {$rule->verification_status}, not verified against an official source.");
            }

            $entry->update(['status' => collect($entryIssues)->contains('severity', 'blocking') ? 'error' : ($entryIssues ? 'warning' : 'ok'), 'issues' => ['list' => $entryIssues]]);
        }

        // Deposits: amounts are entered from the challans; nothing here pays tax.
        $challans = collect($run->challans ?? []);
        if ($entries->isNotEmpty() && $challans->isEmpty()) {
            $add('deposit_details_missing', 'blocking', 'Enter the challan / BIN details of the tax deposited for this quarter.');
        }
        foreach ($challans as $i => $challan) {
            foreach (['bsr_code', 'deposit_date', 'challan_serial', 'amount'] as $field) {
                if (blank($challan[$field] ?? null)) {
                    $add('deposit_details_incomplete', 'blocking', 'Challan #'.($i + 1)." is missing {$field}.");
                }
            }
        }
        if ($challans->isNotEmpty() && round((float) ($return->totals['tax_deposited'] ?? 0), 2) < round((float) ($return->totals['tax_deducted'] ?? 0), 2)) {
            $add('deposit_short', 'blocking', 'Tax deposited is less than tax deducted for the quarter.');
        }

        $add('official_schema_unverified', 'warning', 'The Form No. 138 file schema (utility / validation) has not been verified; the export is a working schedule, not a file ready for filing.');

        return $issues;
    }

    public function reconcile(StatutoryReturn $return): array
    {
        $entries = TdsQuarterlyReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get();
        $ledgerIds = $entries->pluck('tds_annual_ledger_id');
        $ledger = TdsAnnualLedger::query()->withoutGlobalScopes()->whereIn('id', $ledgerIds)->get();
        [$financialYear, $quarter] = explode('-Q', $return->period_key);
        $entity = LegalEntity::query()->withoutGlobalScopes()->findOrFail($return->legal_entity_id);
        $currentLedger = $this->ledgers->active($entity, $financialYear, (int) $quarter)->filter(fn ($r) => (float) $r->tds_deducted > 0);
        $payroll = PayrollEntry::query()->with('lines')->whereIn('id', $ledger->pluck('payroll_entry_id')->filter())->get();
        $check = fn (string $name, float|int $expected, float|int $actual) => ['check' => $name, 'expected' => $expected, 'actual' => $actual, 'difference' => round($actual - $expected, 2), 'blocking' => abs(round($actual - $expected, 2)) > 0.009];

        return [
            $check('tax_deducted_vs_ledger', round((float) $currentLedger->sum('tds_deducted'), 2), round((float) $entries->sum('calc_tax_deducted'), 2)),
            $check('deductee_rows_vs_ledger', $currentLedger->count(), $entries->count()),
            $check('tax_deducted_vs_payroll', round($payroll->sum(fn ($p) => (float) $p->lines->where('code', 'TDS')->sum('amount')), 2), round((float) $entries->sum('calc_tax_deducted'), 2)),
            $check('return_total_vs_entries', round((float) ($return->totals['tax_deducted'] ?? 0), 2), round((float) $entries->sum('calc_tax_deducted'), 2)),
        ];
    }

    public function export(StatutoryReturn $return): array
    {
        $layout = app(ExportLayouts::class)->forReturn($return) ?? throw new RuntimeException('No export layout is registered for TDS_FORM_138.');
        $entries = TdsQuarterlyReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->orderBy('id')->get();
        $lines = [ExportLayouts::csvRow($layout->fieldNames())];

        foreach ($entries as $e) {
            $lines[] = ExportLayouts::csvRow([$e->pan ?? 'PANNOTAVBL', $e->member_name, $e->section_code, $e->payment_date->toDateString(), number_format((float) $e->export_amount_paid, 2, '.', ''), number_format((float) $e->export_tax_deducted, 2, '.', ''), $e->reason_code]);
        }

        return ['filename' => 'WORKING-SCHEDULE_FORM138_'.str_replace('-', '', $return->period_key).'_LE'.$return->legal_entity_id.'.csv', 'content' => implode("\n", $lines)."\n"];
    }

    public function entries(StatutoryReturn $return): Builder
    {
        return TdsQuarterlyReturnEntry::query()->where('statutory_return_id', $return->getKey())->orderBy('id');
    }

    public function present(Model $entry, bool $unmasked): array
    {
        /** @var TdsQuarterlyReturnEntry $entry */
        return [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'member_name' => $entry->member_name,
            'pan' => $unmasked ? $entry->pan : $entry->maskedPan(),
            'section_code' => $entry->section_code,
            'payment_date' => $entry->payment_date?->toDateString(),
            'calculated' => ['amount_paid' => (float) $entry->calc_amount_paid, 'tax_deducted' => (float) $entry->calc_tax_deducted],
            'export' => ['amount_paid' => (float) $entry->export_amount_paid, 'tax_deducted' => (float) $entry->export_tax_deducted, 'reason_code' => $entry->reason_code],
            'rule' => ['id' => $entry->compliance_rule_id, 'version' => $entry->rule_version, 'checksum' => $entry->rule_checksum],
            'status' => $entry->status,
            'issues' => $entry->issues['list'] ?? [],
        ];
    }

    public function captureSnapshots(StatutoryReturn $return): int
    {
        $count = 0;
        foreach (TdsQuarterlyReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get() as $entry) {
            $ledger = TdsAnnualLedger::query()->withoutGlobalScopes()->find($entry->tds_annual_ledger_id);
            $payroll = $ledger?->payroll_entry_id ? PayrollEntry::query()->find($ledger->payroll_entry_id) : null;
            $this->snapshots->capture($return, $entry, $payroll, $entry->employee_id,
                ['rule_id' => $entry->compliance_rule_id, 'rule_version' => $entry->rule_version, 'rule_checksum' => $entry->rule_checksum],
                ['ledger_id' => $ledger?->id, 'tax_basis' => $ledger?->basis, 'month' => $ledger?->month?->toDateString()],
                ['amount_paid' => (float) $entry->calc_amount_paid, 'tax_deducted' => (float) $entry->calc_tax_deducted],
                ['amount_paid' => (float) $entry->export_amount_paid, 'tax_deducted' => (float) $entry->export_tax_deducted, 'section_code' => $entry->section_code, 'reason_code' => $entry->reason_code, 'pan_last4' => $entry->pan_last4],
                $ledger?->tax_regime,
            );
            $count++;
        }

        return $count;
    }
}

<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Compliance\Contracts\StatutoryReturnGenerator;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EpfReturnEntry;
use App\Domain\Compliance\Models\EpfReturnRevision;
use App\Domain\Compliance\Models\EpfReturnRun;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EPF Electronic Challan-cum-Return (Phase 5 Part H). Builds one return per establishment, wage
 * month and kind from FINALIZED payroll: Regular, Supplementary (members not in an earlier return
 * of the month) and Revised (selected members; replaces earlier values once approved).
 *
 * EPFO's revamped ECR (wage month Sept 2025 onwards) separates return filing from payment,
 * validates on upload and allows revision under conditions (see
 * docs/compliance/epf-ecr.md). This system prepares and exports the return; it never files it and
 * never records payment. Amounts come from payroll lines; the only rule parameters read here are
 * the EPS/EDLI wage ceilings of the exact rule version the payroll line used.
 */
final class EpfReturns implements StatutoryReturnGenerator
{
    public const TYPE = 'EPF';

    /** Statuses in which a return counts as filed-or-approved for EPFO sequencing rules. */
    private const SETTLED = [StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED, StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILED, StatutoryReturn::REVISED];

    public function __construct(
        private readonly StatutoryReturns $returns,
        private readonly StatutoryPayrollSource $source,
        private readonly StatutoryRegistrations $registrations,
        private readonly ComplianceRules $rules,
        private readonly StatutorySnapshots $snapshots,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @param  list<int>|null  $employeeIds  members of a revised return
     */
    public function generate(Establishment $establishment, int $year, int $month, User $actor, string $kind = 'regular', ?array $employeeIds = null, ?string $reason = null, bool $paymentNotInitiated = false, string $source = 'ui'): StatutoryReturn
    {
        if (! in_array($kind, EpfReturnRun::KINDS, true)) {
            throw new RuntimeException("Unknown EPF return kind [{$kind}].");
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $periodKey = $start->format('Y-m');
        $regular = $this->settledRegular($establishment, $periodKey);
        $parent = null;
        $sequence = 1;

        if ($kind !== 'regular') {
            if ($regular === null) {
                throw new RuntimeException("A {$kind} return needs an approved regular return for {$periodKey} (EPFO).");
            }
            $this->assertNothingInProcess($establishment, $periodKey);
            $sequence = (int) StatutoryReturn::query()->where('return_type', self::TYPE)->where('establishment_id', $establishment->getKey())->where('period_key', $periodKey)->where('return_kind', $kind)->max('sequence') + 1;
            $parent = $regular;
        }
        if ($kind === 'revised') {
            if (blank($reason)) {
                throw new RuntimeException('A revised return needs a reason.');
            }
            if ($employeeIds === null || $employeeIds === []) {
                throw new RuntimeException('A revised return lists only the members being revised; select at least one.');
            }
        }

        $format = config('peopleos.compliance.formats.EPF_ECR');

        return $this->returns->generate([
            'company_id' => $establishment->company_id,
            'legal_entity_id' => $establishment->legal_entity_id,
            'establishment_id' => $establishment->getKey(),
            'return_type' => self::TYPE,
            'form_code' => 'ECR',
            'return_kind' => $kind,
            'period_key' => $periodKey,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'sequence' => $sequence,
            'parent_return_id' => $parent?->getKey(),
            'format_code' => 'EPF_ECR',
            'format_version' => $format['version'],
            'format_verification_status' => $format['verification_status'],
            'attestations' => array_filter([
                'employee_ids' => $employeeIds,
                'payment_not_initiated' => $kind === 'revised' ? $paymentNotInitiated : null,
                'attested_by' => $kind === 'revised' && $paymentNotInitiated ? $actor->getKey() : null,
            ], fn ($v) => $v !== null),
        ], $actor, $source, $reason);
    }

    public function build(StatutoryReturn $return): void
    {
        $establishment = Establishment::query()->withoutGlobalScopes()->findOrFail($return->establishment_id);
        $start = $return->period_start;
        $runs = $this->source->runs($return->company_id, $start, $start);

        if ($runs->isEmpty()) {
            throw new RuntimeException("No finalized payroll for {$return->period_key}; statutory returns are built from finalized payroll only.");
        }

        // Replace earlier content (only possible while the return is editable).
        $existing = EpfReturnRun::query()->where('statutory_return_id', $return->getKey())->first();
        if ($existing !== null) {
            EpfReturnEntry::query()->where('epf_return_run_id', $existing->getKey())->get()->each->delete();
            $existing->delete();
        }

        $registration = $this->registrations->forEstablishment($establishment, 'epf_establishment_code', $return->period_end);
        $run = EpfReturnRun::query()->create([
            'statutory_return_id' => $return->getKey(),
            'establishment_id' => $establishment->getKey(),
            'statutory_registration_id' => $registration?->getKey(),
            'payroll_period_id' => PayrollPeriod::query()->where('company_id', $return->company_id)->whereDate('start_date', $start->toDateString())->value('id'),
            'wage_month' => $start->toDateString(),
            'return_kind' => $return->return_kind,
        ]);

        $rows = $this->source->entries($runs, $establishment->getKey())
            ->filter(fn ($row) => $row['entry']->lines->contains('code', 'PF_EE'));

        if ($return->return_kind === 'supplementary') {
            $already = $this->membersInReturns($establishment, $return->period_key, ['regular', 'supplementary'], $return->getKey());
            $rows = $rows->reject(fn ($row) => in_array($row['entry']->employee_id, $already, true));
        } elseif ($return->return_kind === 'revised') {
            $selected = array_map('intval', $return->attestations['employee_ids'] ?? []);
            $rows = $rows->filter(fn ($row) => in_array((int) $row['entry']->employee_id, $selected, true));
        }

        $ruleVersions = [];
        $seenUans = [];
        $totals = ['entries' => 0, 'gross_wages' => 0.0, 'epf_wages' => 0.0, 'eps_wages' => 0.0, 'edli_wages' => 0.0, 'ee_share' => 0.0, 'eps_share' => 0.0, 'er_share' => 0.0, 'ncp_days' => 0.0, 'export_ee_share' => 0, 'export_eps_share' => 0, 'export_er_share' => 0];

        foreach ($rows as $row) {
            /** @var PayrollEntry $payroll */
            $payroll = $row['entry'];
            $ee = $payroll->lines->firstWhere('code', 'PF_EE');
            $er = $payroll->lines->firstWhere('code', 'PF_ER');
            $basis = (array) ($ee->basis ?? []);
            $erBasis = (array) ($er?->basis ?? []);
            $rule = isset($basis['rule_id']) ? ComplianceRule::query()->find($basis['rule_id']) : null;

            $wages = (float) ($basis['wages'] ?? 0);
            $epfWages = (float) ($basis['base'] ?? 0);
            $epsWages = $rule ? min($epfWages, (float) $rule->param('eps_wage_ceiling', $epfWages)) : 0.0;
            $edliWages = $rule ? min($wages, (float) $rule->param('edli_wage_ceiling', $wages)) : 0.0;
            $eeShare = (float) $ee->amount;
            $epsShare = (float) ($erBasis['eps'] ?? 0);
            $erShare = (float) ($erBasis['epf'] ?? 0);
            $uan = preg_replace('/\D+/', '', (string) $payroll->employee?->statutoryDetail?->uan) ?: null;
            $uanHash = $uan ? hash_hmac('sha256', $uan, (string) config('app.key')) : null;
            // Keep the per-return unique key; a repeated UAN is stored unhashed and flagged for validation.
            $duplicateUan = $uanHash !== null && isset($seenUans[$uanHash]);
            $seenUans[$uanHash ?? ''] = true;

            $entry = EpfReturnEntry::query()->create([
                'epf_return_run_id' => $run->getKey(),
                'statutory_return_id' => $return->getKey(),
                'employee_id' => $payroll->employee_id,
                'payroll_run_id' => $payroll->payroll_run_id,
                'payroll_entry_id' => $payroll->getKey(),
                'uan' => $uan,
                'uan_hash' => $duplicateUan ? null : $uanHash,
                'uan_last4' => $uan ? substr($uan, -4) : null,
                'member_name' => mb_strtoupper(trim((string) ($payroll->employee?->person?->display_name ?? $payroll->employee?->employee_code))),
                'calc_gross_wages' => (float) $payroll->gross,
                'calc_epf_wages' => $epfWages,
                'calc_eps_wages' => $epsWages,
                'calc_edli_wages' => $edliWages,
                'calc_ee_share' => $eeShare,
                'calc_eps_share' => $epsShare,
                'calc_er_share' => $erShare,
                'calc_ncp_days' => (float) $payroll->lop_days,
                'calc_refund_of_advances' => 0,
                'export_gross_wages' => (int) round((float) $payroll->gross),
                'export_epf_wages' => (int) round($epfWages),
                'export_eps_wages' => (int) round($epsWages),
                'export_edli_wages' => (int) round($edliWages),
                'export_ee_share' => (int) round($eeShare),
                'export_eps_share' => (int) round($epsShare),
                'export_er_share' => (int) round($erShare),
                'export_ncp_days' => (int) round((float) $payroll->lop_days),
                'export_refund_of_advances' => 0,
                'compliance_rule_id' => $rule?->getKey(),
                'rule_version' => $rule?->version,
                'rule_checksum' => $basis['rule_checksum'] ?? $rule?->checksum,
                'issues' => ['establishment_source' => $row['establishment_source']] + ($duplicateUan ? ['duplicate_identifier' => true] : []),
            ]);

            if ($rule) {
                $ruleVersions[$rule->getKey()] = ['rule_code' => $rule->code, 'rule_version' => $rule->version, 'verification_status' => $rule->verification_status, 'rule_checksum' => $rule->checksum];
            }

            $totals['entries']++;
            foreach (['gross_wages' => $entry->calc_gross_wages, 'epf_wages' => $epfWages, 'eps_wages' => $epsWages, 'edli_wages' => $edliWages, 'ee_share' => $eeShare, 'eps_share' => $epsShare, 'er_share' => $erShare, 'ncp_days' => (float) $payroll->lop_days] as $key => $value) {
                $totals[$key] = round($totals[$key] + (float) $value, 2);
            }
            $totals['export_ee_share'] += $entry->export_ee_share;
            $totals['export_eps_share'] += $entry->export_eps_share;
            $totals['export_er_share'] += $entry->export_er_share;
        }

        $run->update(['member_count' => $totals['entries'], 'totals' => $totals]);
        $return->update(['totals' => $totals, 'rule_versions' => $ruleVersions, 'payroll_run_ids' => $runs->pluck('id')->all()]);

        if ($return->return_kind === 'revised') {
            $this->recordRevision($return);
        }
    }

    public function validate(StatutoryReturn $return): array
    {
        $issues = [];
        $add = function (string $code, string $severity, string $message, ?int $employeeId = null) use (&$issues) {
            $issues[] = ['code' => $code, 'severity' => $severity, 'message' => $message, 'employee_id' => $employeeId];
        };
        $run = EpfReturnRun::query()->where('statutory_return_id', $return->getKey())->firstOrFail();
        $establishment = Establishment::query()->withoutGlobalScopes()->findOrFail($return->establishment_id);
        $entries = EpfReturnEntry::query()->withoutGlobalScopes()->where('epf_return_run_id', $run->getKey())->get();
        $enforced = ComplianceRules::enforced();

        if ($run->statutory_registration_id === null) {
            $add('missing_registration', 'blocking', "{$establishment->name} has no EPF establishment code effective in {$return->period_key}.");
        }
        if ($return->period_start->copy()->startOfMonth()->gt(now())) {
            $add('invalid_wage_period', 'blocking', "Wage month {$return->period_key} is in the future.");
        }
        if ($this->source->unfinalizedRuns($return->company_id, $return->period_start, $return->period_start)->isNotEmpty()) {
            $add('invalid_wage_period', 'blocking', "A payroll run for {$return->period_key} is not finalized; returns are built from finalized payroll only.");
        }
        if ($entries->isEmpty()) {
            $add('no_members', 'blocking', 'The return has no members.');
        }

        $expectedRule = $this->rules->resolve('EPF', $return->period_end);
        $duplicates = $entries->whereNotNull('uan_hash')->groupBy('uan_hash')->filter(fn ($g) => $g->count() > 1);

        foreach ($entries as $entry) {
            $who = $entry->member_name;
            $entryIssues = [];
            $flag = function (string $code, string $severity, string $message) use (&$entryIssues, $add, $entry) {
                $entryIssues[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
                $add($code, $severity, $message, $entry->employee_id);
            };

            if ($entry->uan === null) {
                $flag('missing_uan', 'blocking', "{$who}: no UAN on file.");
            } elseif (! preg_match('/^\d{12}$/', $entry->uan)) {
                $flag('invalid_uan', 'blocking', "{$who}: UAN must be 12 digits.");
            }
            if (($entry->uan_hash && $duplicates->has($entry->uan_hash)) || ($entry->issues['duplicate_identifier'] ?? false)) {
                $flag('duplicate_uan', 'blocking', "{$who}: the same UAN appears more than once in this return.");
            }
            if ((float) $entry->calc_epf_wages <= 0) {
                $flag('missing_wages', 'blocking', "{$who}: EPF wages are zero although PF was deducted.");
            }
            if ($entry->compliance_rule_id === null) {
                $flag('invalid_basis', 'blocking', "{$who}: the PF line has no rule reference.");
            } elseif ((float) $entry->calc_eps_share > (float) $entry->calc_eps_share + (float) $entry->calc_er_share + 0.01 || (float) $entry->calc_epf_wages > (float) $entry->calc_gross_wages + 0.01) {
                $flag('invalid_basis', 'blocking', "{$who}: PF basis is inconsistent (EPF wages above gross or EPS above employer share).");
            }
            if ($entry->compliance_rule_id !== null) {
                $rule = ComplianceRule::query()->find($entry->compliance_rule_id);
                if ($expectedRule !== null && (int) $expectedRule->getKey() !== (int) $entry->compliance_rule_id) {
                    $flag('rule_mismatch', $enforced ? 'blocking' : 'warning', "{$who}: payroll used EPF v{$entry->rule_version}; the rule effective for {$return->period_key} is v{$expectedRule->version}.");
                }
                if ($rule && ! $rule->checksumIntact()) {
                    $flag('rule_mismatch', 'blocking', "{$who}: the EPF rule version no longer matches its checksum.");
                }
                if ($rule && ! $rule->isVerified()) {
                    $flag('rule_unverified', $enforced ? 'blocking' : 'warning', "{$who}: EPF v{$rule->version} is {$rule->verification_status}, not verified against an official source.");
                }
            }
            if (fmod((float) $entry->calc_ncp_days, 1.0) !== 0.0) {
                $flag('fractional_ncp_days', 'warning', "{$who}: {$entry->calc_ncp_days} non-contributory days rounded to {$entry->export_ncp_days}.");
            }
            if (($entry->issues['establishment_source'] ?? null) === 'resolved_at_return') {
                $flag('establishment_resolved_at_return', 'warning', "{$who}: payroll was calculated before establishments existed; attributed through the establishment assignment.");
            }

            $entry->update(['status' => collect($entryIssues)->contains('severity', 'blocking') ? 'error' : ($entryIssues ? 'warning' : 'ok'), 'issues' => [...collect($entry->issues)->except('list')->all(), 'list' => $entryIssues]]);
        }

        // EPFO sequencing rules (Re-engineered ECR user manual v3.0).
        if ($return->return_kind !== 'regular') {
            if ($this->settledRegular($establishment, $return->period_key) === null) {
                $add('unsupported_revision', 'blocking', "A {$return->return_kind} return needs an approved regular return for the wage month.");
            }
            if ($this->otherInProcess($establishment, $return->period_key, $return->getKey())) {
                $add('unsupported_revision', 'blocking', 'Another EPF return for this wage month is still in process.');
            }
        }
        if ($return->return_kind === 'supplementary') {
            $already = $this->membersInReturns($establishment, $return->period_key, ['regular', 'supplementary'], $return->getKey());
            foreach ($entries->whereIn('employee_id', $already) as $entry) {
                $add('duplicate_employee', 'blocking', "{$entry->member_name} is already in a regular or supplementary return for this month.", $entry->employee_id);
            }
        }
        if ($return->return_kind === 'revised') {
            $revision = EpfReturnRevision::query()->where('revised_return_id', $return->getKey())->first();
            if ($revision && in_array($revision->direction, ['downward', 'mixed'], true) && ! ($return->attestations['payment_not_initiated'] ?? false)) {
                $add('unsupported_revision', 'blocking', 'A downward revision is allowed only before payment is initiated; attest that no payment has been initiated for this wage month.');
            }
            if ($revision && $revision->direction === 'none') {
                $add('unsupported_revision', 'warning', 'The revision changes no amounts.');
            }
        }
        if ($return->return_kind === 'regular' && $this->missingPreviousRegular($establishment, $return->period_start)) {
            $add('chronological_gap', 'warning', 'No regular EPF return exists for the previous wage month; EPFO requires returns in chronological order.');
        }

        $add('eps_eligibility_not_modelled', 'warning', 'EPS wages assume every member is EPS-eligible; age 58+ and post-Sept-2014 higher-wage members are not modelled — check before upload.');
        if (($return->format_verification_status ?? 'review') !== 'verified') {
            $add('export_format_unverified', 'warning', 'The ECR file layout has not been verified against the EPFO portal Help File; the export is marked UNVERIFIED-FORMAT.');
        }

        return $issues;
    }

    public function reconcile(StatutoryReturn $return): array
    {
        $entries = EpfReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get();
        $payroll = PayrollEntry::query()->with('lines')->whereIn('id', $entries->pluck('payroll_entry_id')->filter())->get();
        $line = fn (string $code) => round($payroll->sum(fn ($p) => (float) $p->lines->where('code', $code)->sum('amount')), 2);
        $check = fn (string $name, float|int $expected, float|int $actual) => ['check' => $name, 'expected' => $expected, 'actual' => $actual, 'difference' => round($actual - $expected, 2), 'blocking' => abs(round($actual - $expected, 2)) > 0.009];

        $checks = [
            $check('employee_share_vs_payroll', $line('PF_EE'), round((float) $entries->sum('calc_ee_share'), 2)),
            $check('employer_share_vs_payroll', $line('PF_ER'), round((float) $entries->sum('calc_eps_share') + (float) $entries->sum('calc_er_share'), 2)),
            $check('epf_wages_vs_payroll', round($payroll->sum(fn ($p) => (float) ($p->lines->firstWhere('code', 'PF_EE')?->basis['base'] ?? 0)), 2), round((float) $entries->sum('calc_epf_wages'), 2)),
            $check('gross_vs_payroll', round((float) $payroll->sum('gross'), 2), round((float) $entries->sum('calc_gross_wages'), 2)),
            $check('entry_count_vs_payroll', $payroll->count(), $entries->count()),
            $check('return_totals_vs_entries', round((float) ($return->totals['ee_share'] ?? 0), 2), round((float) $entries->sum('calc_ee_share'), 2)),
        ];

        if ($return->return_kind === 'regular') {
            $establishment = Establishment::query()->withoutGlobalScopes()->findOrFail($return->establishment_id);
            $members = $this->source->entries($this->source->runs($return->company_id, $return->period_start, $return->period_start), $establishment->getKey())
                ->filter(fn ($row) => $row['entry']->lines->contains('code', 'PF_EE'))->count();
            $checks[] = $check('establishment_members_vs_payroll', $members, $entries->count());
        }

        return $checks;
    }

    public function export(StatutoryReturn $return): array
    {
        $format = config('peopleos.compliance.formats.EPF_ECR');
        $separator = $format['separator'];
        $entries = EpfReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->orderBy('id')->get();

        $lines = $entries->map(fn (EpfReturnEntry $e) => implode($separator, [
            $e->uan, $e->member_name, $e->export_gross_wages, $e->export_epf_wages, $e->export_eps_wages, $e->export_edli_wages,
            $e->export_ee_share, $e->export_eps_share, $e->export_er_share, $e->export_ncp_days, $e->export_refund_of_advances,
        ]));

        $code = EpfReturnRun::query()->where('statutory_return_id', $return->getKey())->first()?->registration?->registration_number_last4 ?? 'NOREG';
        $prefix = ($return->format_verification_status === 'verified') ? '' : 'UNVERIFIED-FORMAT_';

        return [
            'filename' => $prefix.'ECR_'.$code.'_'.str_replace('-', '', $return->period_key).'_'.strtoupper($return->return_kind).($return->sequence > 1 ? '_'.$return->sequence : '').'.txt',
            'content' => $lines->implode("\n")."\n",
        ];
    }

    public function entries(StatutoryReturn $return): Builder
    {
        return EpfReturnEntry::query()->where('statutory_return_id', $return->getKey())->orderBy('id');
    }

    public function present(Model $entry, bool $unmasked): array
    {
        /** @var EpfReturnEntry $entry */
        return [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'member_name' => $entry->member_name,
            'uan' => $unmasked ? $entry->uan : $entry->maskedUan(),
            'calculated' => ['gross_wages' => (float) $entry->calc_gross_wages, 'epf_wages' => (float) $entry->calc_epf_wages, 'eps_wages' => (float) $entry->calc_eps_wages, 'edli_wages' => (float) $entry->calc_edli_wages, 'ee_share' => (float) $entry->calc_ee_share, 'eps_share' => (float) $entry->calc_eps_share, 'er_share' => (float) $entry->calc_er_share, 'ncp_days' => (float) $entry->calc_ncp_days],
            'export' => ['gross_wages' => $entry->export_gross_wages, 'epf_wages' => $entry->export_epf_wages, 'eps_wages' => $entry->export_eps_wages, 'edli_wages' => $entry->export_edli_wages, 'ee_share' => $entry->export_ee_share, 'eps_share' => $entry->export_eps_share, 'er_share' => $entry->export_er_share, 'ncp_days' => $entry->export_ncp_days, 'refund_of_advances' => $entry->export_refund_of_advances],
            'rule' => ['id' => $entry->compliance_rule_id, 'version' => $entry->rule_version, 'checksum' => $entry->rule_checksum],
            'status' => $entry->status,
            'issues' => $entry->issues['list'] ?? [],
        ];
    }

    public function captureSnapshots(StatutoryReturn $return): int
    {
        $count = 0;

        foreach (EpfReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get() as $entry) {
            $payroll = $entry->payroll_entry_id ? PayrollEntry::query()->with('lines')->find($entry->payroll_entry_id) : null;
            $this->snapshots->capture($return, $entry, $payroll, $entry->employee_id,
                ['rule_id' => $entry->compliance_rule_id, 'rule_version' => $entry->rule_version, 'rule_checksum' => $entry->rule_checksum],
                ['payroll_lines' => $payroll?->lines->whereIn('code', ['PF_EE', 'PF_ER', 'PF_ADMIN'])->map(fn ($l) => ['code' => $l->code, 'amount' => (float) $l->amount, 'basis' => $l->basis])->values()->all(), 'lop_days' => (float) $payroll?->lop_days, 'gross' => (float) $payroll?->gross],
                collect($entry->getAttributes())->filter(fn ($v, $k) => str_starts_with($k, 'calc_'))->map(fn ($v) => (float) $v)->all(),
                collect($entry->getAttributes())->filter(fn ($v, $k) => str_starts_with($k, 'export_'))->map(fn ($v) => (int) $v)->all(),
            );
            $count++;
        }

        return $count;
    }

    private function recordRevision(StatutoryReturn $return): void
    {
        EpfReturnRevision::query()->where('revised_return_id', $return->getKey())->exists()
            && DB::table('epf_return_revisions')->where('revised_return_id', $return->getKey())->delete(); // regenerated while editable

        $changes = [];
        $up = false;
        $down = false;
        $establishment = Establishment::query()->withoutGlobalScopes()->findOrFail($return->establishment_id);

        foreach (EpfReturnEntry::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get() as $entry) {
            $previous = EpfReturnEntry::query()->withoutGlobalScopes()->where('employee_id', $entry->employee_id)
                ->whereIn('statutory_return_id', $this->settledReturns($establishment, $return->period_key)->pluck('id'))
                ->orderByDesc('id')->first();
            $before = $previous ? ['ee_share' => $previous->export_ee_share, 'eps_share' => $previous->export_eps_share, 'er_share' => $previous->export_er_share, 'epf_wages' => $previous->export_epf_wages] : null;
            $after = ['ee_share' => $entry->export_ee_share, 'eps_share' => $entry->export_eps_share, 'er_share' => $entry->export_er_share, 'epf_wages' => $entry->export_epf_wages];

            foreach ($after as $key => $value) {
                $was = $before[$key] ?? 0;
                $up = $up || $value > $was;
                $down = $down || $value < $was;
            }
            $changes[] = ['employee_id' => $entry->employee_id, 'previous_return_id' => $previous?->statutory_return_id, 'before' => $before, 'after' => $after];
        }

        EpfReturnRevision::query()->create([
            'original_return_id' => $return->parent_return_id,
            'revised_return_id' => $return->getKey(),
            'reason' => (string) $return->reason,
            'direction' => match (true) {
                $up && $down => 'mixed', $down => 'downward', $up => 'upward', default => 'none'
            },
            'payment_not_initiated_attested' => (bool) ($return->attestations['payment_not_initiated'] ?? false),
            'attested_by' => $return->attestations['attested_by'] ?? null,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    private function settledReturns(Establishment $establishment, string $periodKey): Collection
    {
        return StatutoryReturn::query()->where('return_type', self::TYPE)->where('establishment_id', $establishment->getKey())
            ->where('period_key', $periodKey)->whereIn('status', self::SETTLED)->orderBy('id')->get();
    }

    private function settledRegular(Establishment $establishment, string $periodKey): ?StatutoryReturn
    {
        return $this->settledReturns($establishment, $periodKey)->firstWhere('return_kind', 'regular');
    }

    private function otherInProcess(Establishment $establishment, string $periodKey, ?int $except = null): bool
    {
        return StatutoryReturn::query()->where('return_type', self::TYPE)->where('establishment_id', $establishment->getKey())
            ->where('period_key', $periodKey)->whereIn('status', StatutoryReturn::EDITABLE)
            ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
    }

    private function assertNothingInProcess(Establishment $establishment, string $periodKey): void
    {
        if ($this->otherInProcess($establishment, $periodKey)) {
            throw new RuntimeException("Another EPF return for {$periodKey} is still in process; finish or cancel it first (EPFO).");
        }
    }

    /** @param  list<string>  $kinds  @return list<int> */
    private function membersInReturns(Establishment $establishment, string $periodKey, array $kinds, ?int $except): array
    {
        $ids = StatutoryReturn::query()->where('return_type', self::TYPE)->where('establishment_id', $establishment->getKey())
            ->where('period_key', $periodKey)->whereIn('return_kind', $kinds)->where('status', '!=', StatutoryReturn::CANCELLED)
            ->when($except, fn ($q) => $q->whereKeyNot($except))->pluck('id');

        return EpfReturnEntry::query()->withoutGlobalScopes()->whereIn('statutory_return_id', $ids)->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function missingPreviousRegular(Establishment $establishment, Carbon $wageMonth): bool
    {
        $hasHistory = StatutoryReturn::query()->where('return_type', self::TYPE)->where('establishment_id', $establishment->getKey())
            ->where('period_start', '<', $wageMonth->toDateString())->where('status', '!=', StatutoryReturn::CANCELLED)->exists();

        if (! $hasHistory) {
            return false;
        }

        return ! StatutoryReturn::query()->where('return_type', self::TYPE)->where('establishment_id', $establishment->getKey())
            ->where('period_key', $wageMonth->copy()->subMonthNoOverflow()->format('Y-m'))->where('return_kind', 'regular')
            ->where('status', '!=', StatutoryReturn::CANCELLED)->exists();
    }
}

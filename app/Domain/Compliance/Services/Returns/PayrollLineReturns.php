<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Compliance\Contracts\StatutoryReturnGenerator;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Shared engine for monthly, establishment-level returns that are read straight off finalized
 * payroll lines (ESI, professional tax, labour welfare fund). A subclass names its payroll line
 * codes, registration type, content tables, entry mapping, extra validations and export row; this
 * class owns building, common validations, payroll reconciliation, CSV export and snapshots.
 */
abstract class PayrollLineReturns implements StatutoryReturnGenerator
{
    public function __construct(
        protected readonly StatutoryReturns $returns,
        protected readonly StatutoryPayrollSource $source,
        protected readonly StatutoryRegistrations $registrations,
        protected readonly ComplianceRules $rules,
        protected readonly StatutorySnapshots $snapshots,
    ) {}

    abstract protected function formCode(): string;

    abstract protected function formatCode(): string;

    abstract protected function registrationType(): string;

    /** Payroll line codes whose presence makes an employee part of the return (first = primary). @return list<string> */
    abstract protected function lineCodes(): array;

    /** @return class-string<Model> */
    abstract protected function runModel(): string;

    /** @return class-string<Model> */
    abstract protected function entryModel(): string;

    abstract protected function runForeignKey(): string;

    /** @return array<string, mixed> */
    abstract protected function runAttributes(StatutoryReturn $return, Establishment $establishment): array;

    /**
     * Entry columns from one finalized payroll entry.
     *
     * @return array<string, mixed>
     */
    abstract protected function entryAttributes(StatutoryReturn $return, PayrollEntry $payroll, ?ComplianceRule $rule): array;

    /** Payroll line code => entry column holding the same amount, for reconciliation. @return array<string, string> */
    abstract protected function reconciledColumns(): array;

    /** @return list<string|int|float|null> */
    abstract protected function exportRow(Model $entry): array;

    /** Type-specific validations; call $flag(entry, code, severity, message) or $add(code, severity, message). */
    abstract protected function extraValidation(StatutoryReturn $return, Collection $entries, callable $flag, callable $add): void;

    /** The return's state (PT / LWF are state-scoped); null for central returns. */
    protected function stateFor(Establishment $establishment): ?string
    {
        return null;
    }

    public function generate(Establishment $establishment, int $year, int $month, User $actor, ?string $reason = null, string $source = 'ui'): StatutoryReturn
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $format = config('peopleos.compliance.formats.'.$this->formatCode());

        return $this->returns->generate([
            'company_id' => $establishment->company_id,
            'legal_entity_id' => $establishment->legal_entity_id,
            'establishment_id' => $establishment->getKey(),
            'return_type' => $this->type(),
            'form_code' => $this->formCode(),
            'return_kind' => 'regular',
            'state_code' => $this->stateFor($establishment),
            'period_key' => $start->format('Y-m'),
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'sequence' => 1,
            'format_code' => $this->formatCode(),
            'format_version' => $format['version'] ?? null,
            'format_verification_status' => $format['verification_status'] ?? 'review',
        ], $actor, $source, $reason);
    }

    public function build(StatutoryReturn $return): void
    {
        $establishment = Establishment::query()->withoutGlobalScopes()->findOrFail($return->establishment_id);
        $runs = $this->source->runs($return->company_id, $return->period_start, $return->period_start);

        if ($runs->isEmpty()) {
            throw new RuntimeException("No finalized payroll for {$return->period_key}; statutory returns are built from finalized payroll only.");
        }

        $runModel = $this->runModel();
        $entryModel = $this->entryModel();
        if ($existing = $runModel::query()->where('statutory_return_id', $return->getKey())->first()) {
            $entryModel::query()->withoutGlobalScopes()->where($this->runForeignKey(), $existing->getKey())->get()->each->delete();
            $existing->delete();
        }

        $run = $runModel::query()->create(['statutory_return_id' => $return->getKey(), 'establishment_id' => $establishment->getKey()] + $this->runAttributes($return, $establishment));
        $primary = $this->lineCodes()[0];
        $rows = $this->source->entries($runs, $establishment->getKey())->filter(fn ($row) => $row['entry']->lines->contains('code', $primary));
        $ruleVersions = [];
        $count = 0;
        $seenHashes = [];

        foreach ($rows as $row) {
            /** @var PayrollEntry $payroll */
            $payroll = $row['entry'];
            $basis = (array) ($payroll->lines->firstWhere('code', $primary)->basis ?? []);
            $rule = isset($basis['rule_id']) ? ComplianceRule::query()->find($basis['rule_id']) : null;

            $attributes = $this->entryAttributes($return, $payroll, $rule);
            $issues = ['establishment_source' => $row['establishment_source'], 'basis' => collect($basis)->only(['state', 'state_source', 'establishment_id'])->all()];

            // The per-return unique key on an identifier hash stays; a repeat is stored unhashed and
            // flagged so validation reports it as a blocking duplicate instead of failing the build.
            foreach (array_keys($attributes) as $key) {
                if (str_ends_with($key, '_hash') && $attributes[$key] !== null) {
                    if (isset($seenHashes[$key][$attributes[$key]])) {
                        $attributes[$key] = null;
                        $issues['duplicate_identifier'] = true;
                    }
                    $seenHashes[$key][$attributes[$key] ?? ''] = true;
                }
            }

            $entryModel::query()->create([
                $this->runForeignKey() => $run->getKey(),
                'statutory_return_id' => $return->getKey(),
                'employee_id' => $payroll->employee_id,
                'payroll_run_id' => $payroll->payroll_run_id,
                'payroll_entry_id' => $payroll->getKey(),
                'member_name' => mb_strtoupper(trim((string) ($payroll->employee?->person?->display_name ?? $payroll->employee?->employee_code))),
                'compliance_rule_id' => $rule?->getKey(),
                'rule_version' => $rule?->version,
                'rule_checksum' => $basis['rule_checksum'] ?? $rule?->checksum,
                'issues' => $issues,
            ] + $attributes);

            if ($rule) {
                $ruleVersions[$rule->getKey()] = ['rule_code' => $rule->code, 'rule_version' => $rule->version, 'state' => $rule->state, 'verification_status' => $rule->verification_status, 'rule_checksum' => $rule->checksum];
            }
            $count++;
        }

        $entries = $entryModel::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get();
        $totals = ['entries' => $count];
        foreach ($this->reconciledColumns() as $column) {
            $totals[str_replace('calc_', '', $column)] = round((float) $entries->sum($column), 2);
        }

        $run->update(['member_count' => $count, 'totals' => $totals]);
        $return->update(['totals' => $totals, 'rule_versions' => $ruleVersions, 'payroll_run_ids' => $runs->pluck('id')->all()]);
    }

    public function validate(StatutoryReturn $return): array
    {
        $issues = [];
        $add = function (string $code, string $severity, string $message, ?int $employeeId = null) use (&$issues) {
            $issues[] = ['code' => $code, 'severity' => $severity, 'message' => $message, 'employee_id' => $employeeId];
        };
        $perEntry = [];
        $flag = function (Model $entry, string $code, string $severity, string $message) use (&$perEntry, $add) {
            $perEntry[$entry->getKey()][] = ['code' => $code, 'severity' => $severity, 'message' => $message];
            $add($code, $severity, "{$entry->member_name}: {$message}", $entry->employee_id);
        };

        $establishment = Establishment::query()->withoutGlobalScopes()->findOrFail($return->establishment_id);
        $run = ($this->runModel())::query()->where('statutory_return_id', $return->getKey())->firstOrFail();
        $entries = ($this->entryModel())::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get();
        $enforced = ComplianceRules::enforced();

        if ($run->statutory_registration_id === null) {
            $label = config('peopleos.compliance.registration_types.'.$this->registrationType().'.label');
            $add('missing_registration', 'blocking', "{$establishment->name} has no {$label} effective in {$return->period_key}.");
        }
        if ($return->period_start->copy()->startOfMonth()->gt(now())) {
            $add('invalid_wage_period', 'blocking', "{$return->period_key} is in the future.");
        }
        if ($this->source->unfinalizedRuns($return->company_id, $return->period_start, $return->period_start)->isNotEmpty()) {
            $add('invalid_wage_period', 'blocking', "A payroll run for {$return->period_key} is not finalized.");
        }
        if ($entries->isEmpty()) {
            $add('no_members', 'warning', 'No employee has a '.$this->type().' deduction in this period.');
        }

        foreach ($entries as $entry) {
            if ($entry->compliance_rule_id === null) {
                $flag($entry, 'invalid_basis', 'blocking', 'the payroll line has no rule reference.');

                continue;
            }
            $rule = ComplianceRule::query()->find($entry->compliance_rule_id);
            $expected = $this->rules->resolve($rule->code, $return->period_end, $rule->state, $rule->jurisdiction);
            if ($expected !== null && (int) $expected->getKey() !== (int) $rule->getKey()) {
                $flag($entry, 'rule_mismatch', $enforced ? 'blocking' : 'warning', "payroll used {$rule->label()}; the rule effective for {$return->period_key} is {$expected->label()}.");
            }
            if (! $rule->checksumIntact()) {
                $flag($entry, 'rule_mismatch', 'blocking', "{$rule->label()} no longer matches its checksum.");
            }
            if (! $rule->isVerified()) {
                $flag($entry, 'rule_unverified', $enforced ? 'blocking' : 'warning', "{$rule->label()} is {$rule->verification_status}, not verified against an official source.");
            }
            if (($entry->issues['establishment_source'] ?? null) === 'resolved_at_return') {
                $flag($entry, 'establishment_resolved_at_return', 'warning', 'payroll was calculated before establishments existed; attributed through the establishment assignment.');
            }
        }

        $this->extraValidation($return, $entries, $flag, $add);

        if (($return->format_verification_status ?? 'review') !== 'verified') {
            $add('export_format_unverified', 'warning', 'The export layout has not been verified against the authority\'s current upload format; the export is marked UNVERIFIED-FORMAT.');
        }

        foreach ($entries as $entry) {
            $list = $perEntry[$entry->getKey()] ?? [];
            $entry->update(['status' => collect($list)->contains('severity', 'blocking') ? 'error' : ($list ? 'warning' : 'ok'), 'issues' => [...collect($entry->issues)->except('list')->all(), 'list' => $list]]);
        }

        return $issues;
    }

    public function reconcile(StatutoryReturn $return): array
    {
        $entries = ($this->entryModel())::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get();
        $payroll = PayrollEntry::query()->with('lines')->whereIn('id', $entries->pluck('payroll_entry_id')->filter())->get();
        $check = fn (string $name, float|int $expected, float|int $actual) => ['check' => $name, 'expected' => $expected, 'actual' => $actual, 'difference' => round($actual - $expected, 2), 'blocking' => abs(round($actual - $expected, 2)) > 0.009];

        $checks = [];
        foreach ($this->reconciledColumns() as $code => $column) {
            $checks[] = $check(strtolower($code).'_vs_payroll', round($payroll->sum(fn ($p) => (float) $p->lines->where('code', $code)->sum('amount')), 2), round((float) $entries->sum($column), 2));
            $key = str_replace('calc_', '', $column);
            $checks[] = $check("return_total_{$key}_vs_entries", round((float) ($return->totals[$key] ?? 0), 2), round((float) $entries->sum($column), 2));
        }
        $checks[] = $check('entry_count_vs_payroll', $payroll->count(), $entries->count());

        $primary = $this->lineCodes()[0];
        $members = $this->source->entries($this->source->runs($return->company_id, $return->period_start, $return->period_start), $return->establishment_id)
            ->filter(fn ($row) => $row['entry']->lines->contains('code', $primary))->count();
        $checks[] = $check('establishment_members_vs_payroll', $members, $entries->count());

        return $checks;
    }

    public function export(StatutoryReturn $return): array
    {
        $format = config('peopleos.compliance.formats.'.$this->formatCode());
        $entries = ($this->entryModel())::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->orderBy('id')->get();
        $csv = fn (array $row) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row));

        $lines = [$csv($format['fields'])];
        foreach ($entries as $entry) {
            $lines[] = $csv($this->exportRow($entry));
        }

        $prefix = $return->format_verification_status === 'verified' ? '' : 'UNVERIFIED-FORMAT_';
        $scope = ($return->state_code ? $return->state_code.'_' : '').$return->establishment_id;

        return ['filename' => $prefix.$this->formCode().'_'.$scope.'_'.str_replace('-', '', $return->period_key).'.csv', 'content' => implode("\n", $lines)."\n"];
    }

    public function entries(StatutoryReturn $return): Builder
    {
        return ($this->entryModel())::query()->where('statutory_return_id', $return->getKey())->orderBy('id');
    }

    public function captureSnapshots(StatutoryReturn $return): int
    {
        $count = 0;

        foreach (($this->entryModel())::query()->withoutGlobalScopes()->where('statutory_return_id', $return->getKey())->get() as $entry) {
            $payroll = $entry->payroll_entry_id ? PayrollEntry::query()->with('lines')->find($entry->payroll_entry_id) : null;
            $attributes = $entry->getAttributes();
            $this->snapshots->capture($return, $entry, $payroll, $entry->employee_id,
                ['rule_id' => $entry->compliance_rule_id, 'rule_version' => $entry->rule_version, 'rule_checksum' => $entry->rule_checksum],
                ['payroll_lines' => $payroll?->lines->whereIn('code', $this->lineCodes())->map(fn ($l) => ['code' => $l->code, 'amount' => (float) $l->amount, 'basis' => $l->basis])->values()->all(), 'gross' => (float) $payroll?->gross, 'paid_days' => (float) $payroll?->paid_days],
                collect($attributes)->filter(fn ($v, $k) => str_starts_with($k, 'calc_'))->map(fn ($v) => (float) $v)->all(),
                collect($attributes)->filter(fn ($v, $k) => str_starts_with($k, 'export_'))->all() ?: ['row' => $this->exportRow($entry)],
                $payroll?->employee?->statutoryDetail?->tax_regime,
            );
            $count++;
        }

        return $count;
    }

    /** Encrypted identifier columns: value, keyed hash and last four. @return array<string, string|null> */
    protected function identifier(string $column, ?string $value): array
    {
        $value = $value !== null ? (preg_replace('/\s+/', '', $value) ?: null) : null;

        return [
            $column => $value,
            "{$column}_hash" => $value ? hash_hmac('sha256', $value, (string) config('app.key')) : null,
            "{$column}_last4" => $value ? substr($value, -4) : null,
        ];
    }
}

<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\JvCodes;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Region;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Collection;
use League\Csv\Writer;
use RuntimeException;

/**
 * Data import DI-1 (decision D2): the journal voucher (JV) of a finalized payroll run, as payroll suites post it to the
 * books. One balanced journal: each earning, reimbursement and employer contribution is debited to its pay component's
 * ledger, split by the segment the organisation posts by (Setup → Payroll: department by default, or branch, business
 * unit, its own dimension …); each deduction is credited to its component's ledger; each employer contribution is
 * credited to its component's payable ledger; net pay is credited to the net pay account. Ledgers and segments are the
 * JV codes (Integrations → JV codes; an accounting system's own codes win), else the PeopleOS codes. Amounts are summed
 * in paise per account, segment and side, so debits always equal credits. Reads the payroll the user may see; the
 * download is audited like the bank file.
 */
final class JournalVoucher
{
    public const SEGMENT_SETTING = 'payroll.jv.segment';

    public const NET_PAY_SETTING = 'payroll.jv.net_pay_account';

    /** The account net pay is credited to when the organisation has not named one. */
    public const DEFAULT_NET_PAY_ACCOUNT = 'NET_PAY';

    /** What a journal can be split by: setting => label. */
    public const SEGMENTS = [
        'none' => 'No split', 'company' => 'Company', 'location' => 'Branch (or sub branch)', 'business_unit' => 'Business unit',
        'department' => 'Department (or sub department)', 'designation' => 'Designation', 'grade' => 'Grade', 'level' => 'Level',
        'region' => 'Region of the branch', 'dimension' => "The organisation's own dimension", 'employee' => 'Employee',
    ];

    /** segment => [position column, model, JV dimension, JV dimension of a record with a parent, parent column] */
    private const SOURCES = [
        'company' => ['company_id', Company::class, 'company', null, null],
        'location' => ['location_id', Location::class, 'location', 'sub_branch', 'parent_location_id'],
        'business_unit' => ['business_unit_id', BusinessUnit::class, 'business_unit', null, null],
        'department' => ['department_id', Department::class, 'department', 'sub_department', 'parent_department_id'],
        'designation' => ['designation_id', Designation::class, 'designation', null, null],
        'grade' => ['grade_id', Grade::class, 'grade', null, null],
        'level' => ['level_id', Level::class, 'level', null, null],
        'dimension' => ['dimension_value_id', DimensionValue::class, 'dimension', null, null],
    ];

    public function __construct(private readonly AuditRecorder $audit, private readonly JvCodes $codes, private readonly SettingsRepository $settings) {}

    /** The segment the organisation posts by (Setup → Payroll). */
    public function segment(): string
    {
        $segment = (string) $this->settings->get(self::SEGMENT_SETTING, 'department');

        return array_key_exists($segment, self::SEGMENTS) ? $segment : 'department';
    }

    /**
     * The journal lines of a finalized (or paid) run, by account and segment.
     *
     * @return list<array{account: string, segment: string, debit: string, credit: string, narration: string}>
     */
    public function lines(PayrollRun $run, ?IntegrationSystem $system = null): array
    {
        if (! $run->isLocked()) {
            throw new RuntimeException('A journal voucher is made from finalized payroll only: finalize the run first.');
        }
        $run->loadMissing('period');
        $entries = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->with('lines')->get();
        $segmentBy = $this->segment();
        $segments = $this->segments($entries->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values(), $segmentBy, $run->period->end_date->toDateString(), $system);
        $components = SalaryComponent::query()->get(['id', 'code', 'name'])->keyBy(fn (SalaryComponent $c) => strtoupper($c->code));
        $ledgers = $this->codes->codes('pay_component', $system);
        $payables = $this->codes->codes('pay_component_payable', $system);
        $netAccount = trim((string) $this->settings->get(self::NET_PAY_SETTING)) ?: self::DEFAULT_NET_PAY_ACCOUNT;

        $sums = [];
        $add = function (string $side, string $account, string $segment, int $paise, string $name) use (&$sums): void {
            $key = "{$account}\x1F{$segment}\x1F{$side}";
            $sums[$key] ??= ['account' => $account, 'segment' => $segment, 'side' => $side, 'paise' => 0, 'names' => []];
            $sums[$key]['paise'] += $paise;
            $sums[$key]['names'][$name] = true;
        };
        foreach ($entries as $entry) {
            $segment = $segments[(int) $entry->employee_id] ?? '';
            $net = 0;
            foreach ($entry->lines as $line) {
                $paise = (int) round((float) $line->amount * 100);
                if ($paise === 0) {
                    continue;
                }
                $component = $components[strtoupper((string) $line->code)] ?? null;
                $account = ($component !== null ? ($ledgers[(int) $component->getKey()] ?? null) : null) ?? strtoupper((string) $line->code);
                $name = (string) ($line->name ?: $line->code);
                if (in_array($line->type, ['earning', 'reimbursement'], true)) {
                    $add('debit', $account, $segment, $paise, $name);
                    $net += $paise;
                } elseif ($line->type === 'deduction') {
                    $add('credit', $account, '', $paise, $name);
                    $net -= $paise;
                } elseif ($line->type === 'employer_contribution') {
                    $add('debit', $account, $segment, $paise, $name);
                    $payable = $component !== null ? ($payables[(int) $component->getKey()] ?? null) : null;
                    $add('credit', $payable ?? $account, '', $paise, $name.' payable');
                }
            }
            if ($net !== 0) {
                $add($net > 0 ? 'credit' : 'debit', $netAccount, '', abs($net), 'Net pay');
            }
        }

        $label = 'Payroll '.$run->period->label();
        $lines = array_map(fn (array $s) => [
            'account' => $s['account'], 'segment' => $s['segment'],
            'debit' => $s['side'] === 'debit' ? $this->money($s['paise']) : '', 'credit' => $s['side'] === 'credit' ? $this->money($s['paise']) : '',
            'narration' => $label.' · '.implode(', ', array_keys($s['names'])),
        ], array_values($sums));
        usort($lines, fn (array $a, array $b) => [$a['debit'] === '' ? 1 : 0, $a['account'], $a['segment']] <=> [$b['debit'] === '' ? 1 : 0, $b['account'], $b['segment']]);

        return $lines;
    }

    /** The journal as a CSV (Account, Segment, Debit, Credit, Narration); the download is audited. */
    public function csv(PayrollRun $run, ?IntegrationSystem $system = null): string
    {
        $lines = $this->lines($run, $system);
        $csv = Writer::fromString();
        $csv->insertOne(['Account', 'Segment', 'Debit', 'Credit', 'Narration']);
        foreach ($lines as $line) {
            $csv->insertOne(array_values($line));
        }
        $debit = array_sum(array_map(fn (array $l) => (float) ($l['debit'] ?: 0), $lines));
        $this->audit->record(AuditAction::Export, 'payroll', $run, [], null, metadata: ['export' => 'journal_voucher', 'lines' => count($lines), 'amount' => round($debit, 2), 'segment' => $this->segment()]
            + ($system !== null ? ['accounting_system' => $system->code] : []));

        return $csv->toString();
    }

    public function fileName(PayrollRun $run, ?IntegrationSystem $system = null): string
    {
        $run->loadMissing('period');

        return 'journal-voucher-'.$run->period->year.'-'.str_pad((string) $run->period->month, 2, '0', STR_PAD_LEFT).($system !== null ? '-'.$system->code : '').'.csv';
    }

    /**
     * The segment code of each employee on the day the period ends: the JV code of their position's record in the
     * chosen dimension (a sub branch / sub department under its own JV dimension), else its PeopleOS code.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, string> employee id => segment
     */
    private function segments(Collection $employeeIds, string $segmentBy, string $on, ?IntegrationSystem $system): array
    {
        if ($segmentBy === 'none' || $employeeIds->isEmpty()) {
            return [];
        }
        if ($segmentBy === 'employee') {
            $codes = $this->codes->codes('employee', $system);

            return Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $employeeIds)->pluck('employee_code', 'id')
                ->mapWithKeys(fn ($code, $id) => [(int) $id => $codes[(int) $id] ?? (string) $code])->all();
        }
        $positions = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $employeeIds)->effectiveOn($on)->get();
        if ($segmentBy === 'region') {
            $locations = Location::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $positions->pluck('location_id')->filter())->pluck('region_id', 'id');
            $codes = $this->codes->codes('region', $system);
            $regions = Region::query()->whereIn('id', $locations->filter())->pluck('code', 'id');

            return $positions->mapWithKeys(function (EmployeePosition $p) use ($locations, $codes, $regions) {
                $region = $locations[$p->location_id] ?? null;

                return [(int) $p->employee_id => $region === null ? '' : ($codes[(int) $region] ?? (string) ($regions[$region] ?? ''))];
            })->all();
        }

        [$column, $class, $dimension, $subDimension, $parentColumn] = self::SOURCES[$segmentBy];
        $records = $class::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $positions->pluck($column)->filter()->unique())->get()->keyBy('id');
        $codes = $this->codes->codes($dimension, $system);
        $subCodes = $subDimension !== null ? $this->codes->codes($subDimension, $system) : [];

        return $positions->mapWithKeys(function (EmployeePosition $p) use ($column, $records, $codes, $subCodes, $parentColumn) {
            $record = $records[$p->getAttribute($column)] ?? null;
            if ($record === null) {
                return [(int) $p->employee_id => ''];
            }
            $isSub = $parentColumn !== null && ($record->getAttributes()[$parentColumn] ?? null) !== null;

            return [(int) $p->employee_id => ($isSub ? ($subCodes[(int) $record->getKey()] ?? null) : ($codes[(int) $record->getKey()] ?? null)) ?? (string) $record->getAttributes()['code']];
        })->all();
    }

    private function money(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }
}

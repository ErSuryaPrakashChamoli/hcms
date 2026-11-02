<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\StatutoryChallan;
use App\Domain\Compliance\Models\StatutoryChallanAllocation;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\TdsAnnualLedger;
use App\Domain\Compliance\Models\TdsFinancialYear;
use App\Domain\Compliance\Services\Tds\TdsLedgers;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Support\Storage\FileSafety;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne parity PO-13 (matrix 25 §25.6.12, "Generate challan"): the statutory challan register and its financial-year grid.
 *
 * - A challan is the payment of a month's PF / ESI / PT / LWF contribution (per establishment) or of the TDS deducted from
 *   salaries (per legal entity). It is recorded by someone with `compliance.returns.file` who did not approve the return it
 *   pays (separation of duties), carries the payment facts as made (challan no. / TRRN / CRN, BSR code, date, mode, bank,
 *   amount, interest, penalty, receipt) and is never edited: a wrong one is cancelled with a reason and recorded again.
 *   A second person may verify it against the bank statement.
 * - A TDS challan deposits what finalized payroll deducted (D-PO7): its amount is the sum of an allocation per employee read
 *   from the TDS ledger (deducted − already deposited by other challans of the month). Interest and penalty are recorded on
 *   the challan; finalized TDS is never edited. The quarterly statement takes its challans from here.
 * - The grid reads the returns (generated / approved / filed) and the challans (paid) of every month of a financial year.
 *
 * Nothing here calculates a contribution or reads a statutory rate: it is a payment register.
 */
final class StatutoryChallans
{
    public const STATES = [
        'not_due' => 'Not due yet',
        'not_generated' => 'Not generated',
        'generated' => 'Generated',
        'approved' => 'Approved',
        'paid' => 'Paid',
        'filed' => 'Filed',
    ];

    public const STATE_COLORS = ['not_due' => 'gray', 'not_generated' => 'gray', 'generated' => 'warning', 'approved' => 'info', 'paid' => 'success', 'filed' => 'success'];

    public function __construct(private readonly AuditRecorder $audit, private readonly TdsLedgers $ledgers, private readonly AccessScopes $scopes) {}

    // ---------------------------------------------------------------------------------------------------------------------
    // Periods
    // ---------------------------------------------------------------------------------------------------------------------

    public function financialYearOf(CarbonInterface|string $date): string
    {
        return FinancialYear::make()->label($date);
    }

    /** @return list<CarbonImmutable> the twelve months of the financial year ("2026-27"), April first by default */
    public function months(string $financialYear): array
    {
        $start = CarbonImmutable::create((int) substr($financialYear, 0, 4), (int) config('peopleos.compliance.financial_year_start_month', 4), 1);

        return array_map(fn (int $i) => $start->addMonthsNoOverflow($i), range(0, 11));
    }

    /** @return array<string, string> the financial years offered: the current one and the three before it */
    public function yearOptions(): array
    {
        $current = FinancialYear::make()->startYear(now());

        return collect(range($current, $current - 3))->mapWithKeys(fn (int $y) => [$label = $y.'-'.substr((string) ($y + 1), -2) => $label])->all();
    }

    public function quarterOf(CarbonInterface $month): int
    {
        return (int) ceil(FinancialYear::make()->monthIndex($month) / 3);
    }

    /** "2026-09" → the first day of that month (refused when malformed). */
    public function month(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new RuntimeException('Choose the month the challan pays.');
        }

        return CarbonImmutable::parse($value.'-01')->startOfDay();
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Units and the grid
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Who pays a statute's challans, in the viewer's access scope: establishments for PF / ESI / PT / LWF, legal entities
     * (the TAN) for income tax.
     *
     * @return Collection<int, Establishment|LegalEntity>
     */
    public function units(string $statute): Collection
    {
        $this->definition($statute);

        return $this->isEntityLevel($statute)
            ? LegalEntity::query()->with('company')->orderBy('legal_name')->get()
            : Establishment::query()->with(['legalEntity', 'company'])->where('status', 'active')->orderBy('name')->get();
    }

    public function unit(string $statute, mixed $id): Establishment|LegalEntity|null
    {
        if (! is_numeric($id)) {
            return null;
        }

        return $this->isEntityLevel($statute) ? LegalEntity::query()->find((int) $id) : Establishment::query()->find((int) $id);
    }

    public function unitLabel(Establishment|LegalEntity $unit): string
    {
        return $unit instanceof LegalEntity ? (string) $unit->legal_name : (string) $unit->name;
    }

    public function isEntityLevel(string $statute): bool
    {
        return $this->definition($statute)['scope'] === 'legal_entity';
    }

    /**
     * The financial year × month grid of a statute: one row per unit, one cell per month with its state, the return and
     * the challans recorded. Reads every return and challan of the year once.
     *
     * @return list<array{unit: Establishment|LegalEntity, label: string, registration: ?string, cells: array<string, array<string, mixed>>}>
     */
    public function grid(string $statute, string $financialYear): array
    {
        $units = $this->units($statute);
        if ($units->isEmpty()) {
            return [];
        }
        $months = $this->months($financialYear);
        $returns = $this->returnsOfYear($statute, $financialYear, $units->modelKeys());
        $challans = $this->challansOfYear($statute, $financialYear, $units->modelKeys());
        $registrations = $this->registrations($statute, $units);
        $tds = $this->isEntityLevel($statute) ? $units->mapWithKeys(fn (LegalEntity $e) => [$e->id => $this->tdsMonths($e, $financialYear)])->all() : [];

        return $units->map(fn (Model $unit) => [
            'unit' => $unit,
            'label' => $this->unitLabel($unit),
            'registration' => $registrations[$unit->getKey()] ?? null,
            'cells' => collect($months)->mapWithKeys(function (CarbonImmutable $month) use ($statute, $unit, $returns, $challans, $tds) {
                $key = $month->format('Y-m');
                $periodKey = $this->isEntityLevel($statute) ? $this->financialYearOf($month).'-Q'.$this->quarterOf($month) : $key;

                return [$key => $this->cellFrom($month, $this->currentOf($returns->get($unit->getKey().'|'.$periodKey, collect())), $challans->get($unit->getKey().'|'.$key, collect()), $tds[$unit->getKey()][$key] ?? null)];
            })->all(),
        ])->values()->all();
    }

    /**
     * One cell in full (the panel beside the grid): the return of the period (and any others: supplementary, revised), the
     * challans, and for income tax the month's deducted and deposited amounts.
     *
     * @return array<string, mixed>
     */
    public function cell(string $statute, Establishment|LegalEntity $unit, CarbonImmutable $month): array
    {
        $returns = $this->returnsFor($statute, $unit, $month);
        $current = $this->currentOf($returns);
        $challans = $this->challansFor($statute, $unit, $month)->get();
        $tds = $unit instanceof LegalEntity ? ($this->tdsMonths($unit, $this->financialYearOf($month))[$month->format('Y-m')] ?? null) : null;

        return $this->cellFrom($month, $current, $challans->reject->isCancelled(), $tds) + [
            'returns' => $returns->values(),
            'all_challans' => $challans,
        ];
    }

    /** The return a challan of this unit and month pays (the regular one first; never a cancelled or replaced one). */
    public function currentReturn(string $statute, Establishment|LegalEntity $unit, CarbonImmutable $month): ?StatutoryReturn
    {
        return $this->currentOf($this->returnsFor($statute, $unit, $month));
    }

    /** generated / approved / filed (or not_generated) for a return. */
    public static function returnState(?StatutoryReturn $return): string
    {
        return match (true) {
            $return === null => 'not_generated',
            $return->submitted_at !== null || in_array($return->status, [StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILED], true) => 'filed',
            in_array($return->status, [StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED], true) => 'approved',
            default => 'generated',
        };
    }

    /**
     * §25.6.12 #4 (display only): locations not mapped to an establishment (their people fall back to the company's principal
     * establishment, or to none) and the finalized salaries of the year that had no professional-tax state
     * (`statutory_state_missing`).
     *
     * @return array{locations: list<array{name: string, state: ?string, company: ?string}>, state_missing: int}
     */
    public function coverage(string $financialYear): array
    {
        $locations = Location::query()->with('company')->where('status', 'active')->whereNull('establishment_id')->orderBy('name')->get()
            ->map(fn (Location $l) => ['name' => (string) $l->name, 'state' => $l->state_code, 'company' => $l->company?->name])->values()->all();
        $months = $this->months($financialYear);
        $stateMissing = PayrollEntry::query()
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid'])->whereHas('period', fn ($p) => $p->monthsBetween($months[0], $months[11]->endOfMonth())))
            ->where('exceptions', 'like', '%statutory_state_missing%')
            ->count();

        return ['locations' => $locations, 'state_missing' => $stateMissing];
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Recording, verifying, cancelling
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Record a challan payment.
     *
     * @param  array<string, mixed>  $data  statute, unit_id, month ("YYYY-MM"), challan_number, paid_on, payment_mode, amount
     *                                      (not for income tax), interest, penalty, alias, acknowledgement_number, bsr_code and
     *                                      nature_of_payment (income tax), bank_name, branch, notes, employee_ids (income tax:
     *                                      whom it deposits for; all with TDS left when absent), receipt (an upload path in the
     *                                      documents disk's statutory-challans/ area) and receipt_name
     */
    public function record(array $data, User $actor): StatutoryChallan
    {
        $this->requirePermission($actor, 'compliance.returns.file');
        $statute = (string) ($data['statute'] ?? '');
        $definition = $this->definition($statute);
        $month = $this->month($data['month'] ?? null);
        $unit = $this->unit($statute, $data['unit_id'] ?? null) ?? throw new RuntimeException($this->isEntityLevel($statute) ? 'Choose the legal entity (deductor) the challan was paid for.' : 'Choose the establishment the challan was paid for.');
        if ($month->gt(now()->startOfMonth())) {
            throw new RuntimeException('A challan pays a month that has begun; '.$month->format('M Y').' is in the future.');
        }

        $paidOn = $this->date($data['paid_on'] ?? null) ?? throw new RuntimeException('Enter the date the challan was paid.');
        if ($paidOn->gt(now()->endOfDay())) {
            throw new RuntimeException('The payment date cannot be in the future.');
        }
        if ($paidOn->lt($month)) {
            throw new RuntimeException('The payment date is before '.$month->format('M Y').', the month it pays.');
        }
        $number = self::text($data['challan_number'] ?? null, 64) ?? throw new RuntimeException('Enter the challan number ('.self::numberLabel($statute).').');
        $mode = (string) ($data['payment_mode'] ?? '');
        if (! array_key_exists($mode, (array) config('peopleos.compliance.challan_payment_modes', []))) {
            throw new RuntimeException('Choose how the challan was paid.');
        }
        $interest = $this->money($data['interest'] ?? 0, 'Interest');
        $penalty = $this->money($data['penalty'] ?? 0, 'Penalty');
        $bsr = null;
        $nature = null;
        if ($statute === 'tds') {
            $bsr = preg_replace('/\s+/', '', (string) ($data['bsr_code'] ?? ''));
            if (! preg_match('/^\d{7}$/', (string) $bsr)) {
                throw new RuntimeException('Enter the 7-digit BSR code of the bank branch where the tax was deposited.');
            }
            $nature = self::text($data['nature_of_payment'] ?? null, 16) ?? (string) config('peopleos.compliance.formats.TDS_FORM_138.section_code');
        }

        $return = $this->currentReturn($statute, $unit, $month);
        if ($return?->approved_by !== null && (int) $return->approved_by === (int) $actor->getKey()) {
            throw new RuntimeException('Separation of duties: the person who approved '.$return->label().' cannot record its challan payment.');
        }

        $entity = $unit instanceof LegalEntity ? $unit : LegalEntity::query()->withoutGlobalScope(AccessScope::class)->findOrFail($unit->legal_entity_id);
        $activeKey = $statute.'|'.$entity->getKey().'|'.mb_strtoupper(preg_replace('/\s+/', '', $number));
        $financialYear = $this->financialYearOf($month);
        $year = $statute === 'tds' ? $this->ledgers->year($entity, $financialYear) : null;
        $receipt = $this->placeReceipt($data['receipt'] ?? null, $data['receipt_name'] ?? null, (int) $actor->tenant_id);

        try {
            return DB::transaction(function () use ($data, $actor, $statute, $definition, $month, $unit, $entity, $paidOn, $number, $mode, $interest, $penalty, $bsr, $nature, $return, $activeKey, $financialYear, $year, $receipt) {
                if (StatutoryChallan::query()->withoutGlobalScope(AccessScope::class)->where('active_key', $activeKey)->exists()) {
                    throw new RuntimeException("Challan {$number} is already recorded for ".$this->unitLabel($entity).'.');
                }

                $allocations = collect();
                if ($statute === 'tds') {
                    // One deductor-year at a time: two people allocating the same month's TDS cannot both deposit it.
                    TdsFinancialYear::query()->withoutGlobalScope(AccessScope::class)->whereKey($year->getKey())->lockForUpdate()->first();
                    $allocations = $this->chosenAllocation($entity, $month, $actor, $data['employee_ids'] ?? null);
                    $amount = round((float) $allocations->sum('to_allocate'), 2);
                } else {
                    $amount = $this->money($data['amount'] ?? null, 'Amount', positive: true);
                }

                $challan = (new StatutoryChallan([
                    'company_id' => $entity->company_id,
                    'legal_entity_id' => $entity->getKey(),
                    'establishment_id' => $unit instanceof Establishment ? $unit->getKey() : null,
                    'statutory_registration_id' => $this->registrationFor($statute, $unit, $month)?->getKey(),
                    'statutory_return_id' => $return?->getKey(),
                    'statute' => $statute,
                    'state_code' => in_array($statute, ['pt', 'lwf'], true) && $unit instanceof Establishment ? $unit->state : null,
                    'financial_year' => $financialYear,
                    'period_month' => $month->toDateString(),
                    'alias' => self::text($data['alias'] ?? null, 100),
                    'challan_number' => $number,
                    'acknowledgement_number' => self::text($data['acknowledgement_number'] ?? null, 64),
                    'bsr_code' => $bsr,
                    'nature_of_payment' => $nature,
                    'paid_on' => $paidOn->toDateString(),
                    'payment_mode' => $mode,
                    'bank_name' => self::text($data['bank_name'] ?? null, 120),
                    'branch' => self::text($data['branch'] ?? null, 120),
                    'amount' => $amount,
                    'interest' => $interest,
                    'penalty' => $penalty,
                    'receipt_path' => $receipt['path'] ?? null,
                    'receipt_name' => $receipt['name'] ?? null,
                    'notes' => self::text($data['notes'] ?? null, 2000),
                    'status' => StatutoryChallan::RECORDED,
                    'active_key' => $activeKey,
                    'recorded_by' => $actor->getKey(),
                    'recorded_at' => now(),
                ]))->withAuditReason($definition['label'].' challan payment recorded'.($statute === 'tds' ? ' (TDS of '.$allocations->count().' employee'.($allocations->count() === 1 ? '' : 's').')' : ''));
                $challan->save();

                // Recorded only where the person may see it (company, establishment and the module's access groups).
                if (! $this->scopes->allows($actor, $challan)) {
                    throw new RuntimeException('That establishment or legal entity is outside your access.');
                }

                foreach ($allocations as $row) {
                    StatutoryChallanAllocation::query()->create([
                        'statutory_challan_id' => $challan->getKey(),
                        'legal_entity_id' => $entity->getKey(),
                        'employee_id' => $row['employee_id'],
                        'tds_annual_ledger_id' => $row['tds_annual_ledger_id'],
                        'period_month' => $month->toDateString(),
                        'tds_deducted' => $row['deducted'],
                        'amount' => $row['to_allocate'],
                        'created_at' => now(),
                    ]);
                }
                if ($statute === 'tds') {
                    $this->audit->record(AuditAction::Assign, 'compliance', $challan, [], 'TDS deducted by finalized payroll allocated to the challan',
                        metadata: ['employees' => $allocations->count(), 'amount' => $amount, 'employee_ids' => $allocations->pluck('employee_id')->values()->all(), 'month' => $month->format('Y-m')],
                        entityLabel: $challan->label(), actor: $actor);
                }

                return $challan->refresh();
            });
        } catch (Throwable $e) {
            if (isset($receipt['path'])) {
                Storage::disk($this->disk())->delete($receipt['path']);
            }
            throw $e;
        }
    }

    /** A second person confirms the challan against the bank statement or the portal receipt. */
    public function verify(StatutoryChallan $challan, User $actor, ?string $note = null): StatutoryChallan
    {
        $this->requirePermission($actor, 'compliance.returns.file');
        $this->requireReach($challan, $actor);
        if ($challan->status !== StatutoryChallan::RECORDED) {
            throw new RuntimeException('Only a recorded challan can be verified; this one is '.strtolower(StatutoryChallan::STATUSES[$challan->status] ?? $challan->status).'.');
        }
        if ((int) $challan->recorded_by === (int) $actor->getKey()) {
            throw new RuntimeException('Separation of duties: the person who recorded a challan cannot verify it.');
        }

        $challan->withAuditReason(self::text($note, 500) ?? 'Challan verified against the payment')->withAuditAction(AuditAction::Reviewed)
            ->update(['status' => StatutoryChallan::VERIFIED, 'verified_by' => $actor->getKey(), 'verified_at' => now()]);

        return $challan;
    }

    /** A challan recorded by mistake is cancelled with a reason and stays in the register; its TDS allocation is freed. */
    public function cancel(StatutoryChallan $challan, User $actor, string $reason): StatutoryChallan
    {
        $this->requirePermission($actor, 'compliance.returns.file');
        $this->requireReach($challan, $actor);
        $reason = self::text($reason, 500) ?? throw new RuntimeException('Give the reason for cancelling the challan.');
        if ($challan->status !== StatutoryChallan::RECORDED) {
            throw new RuntimeException('Only a challan not yet verified can be cancelled.');
        }
        if ($statement = $this->statementCarrying($challan)) {
            throw new RuntimeException('The challan is part of '.$statement->label().', which is '.$statement->status.'; revise the statement instead.');
        }

        $challan->withAuditReason($reason)->withAuditAction(AuditAction::Cancelled)
            ->update(['status' => StatutoryChallan::CANCELLED, 'active_key' => null, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now(), 'cancel_reason' => $reason]);

        return $challan;
    }

    /** Add or replace the receipt of a challan not yet verified (documents disk; the earlier file is removed). */
    public function attachReceipt(StatutoryChallan $challan, string $upload, ?string $originalName, User $actor): StatutoryChallan
    {
        $this->requirePermission($actor, 'compliance.returns.file');
        $this->requireReach($challan, $actor);
        if ($challan->status !== StatutoryChallan::RECORDED) {
            throw new RuntimeException('The receipt of a verified or cancelled challan stays as it is.');
        }
        $receipt = $this->placeReceipt($upload, $originalName, (int) $challan->tenant_id) ?? throw new RuntimeException('The receipt upload was not found.');
        $earlier = $challan->getAttributes()['receipt_path'] ?? null;
        $challan->withAuditReason('Challan receipt attached')->update(['receipt_path' => $receipt['path'], 'receipt_name' => $receipt['name']]);
        if (is_string($earlier) && $earlier !== $receipt['path']) {
            Storage::disk($this->disk())->delete($earlier);
        }

        return $challan;
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Income tax: allocation from finalized payroll, monthly view, the quarterly statement
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * §25.6.12 #13: who a TDS challan of the month deposits for — every employee whose finalized payroll of that month (by
     * payment date, the TDS ledger) deducted TDS, with what was deducted, what other challans already deposited and what is
     * left. Only employees the viewer may reach are listed; the ledger is brought up to date with finalized payroll first.
     *
     * @return Collection<int, array{employee_id: int, code: string, name: string, pan: string, tds_annual_ledger_id: ?int, gross: float, deducted: float, allocated: float, to_allocate: float}>
     */
    public function tdsAllocation(LegalEntity $entity, CarbonImmutable $month, User $viewer, bool $sync = true): Collection
    {
        if ($sync) {
            $this->ledgers->sync($entity, $this->financialYearOf($month));
        }
        $rows = $this->ledgerRows($entity, $month)->toBase()->groupBy('employee_id');
        if ($rows->isEmpty()) {
            return collect();
        }
        $reachable = $this->reachableEmployees($viewer, $rows->keys()->all());
        $allocated = $this->allocatedIn($entity, $month);

        return $rows->only($reachable)->map(function (Collection $ledger, int $employeeId) use ($allocated) {
            $last = $ledger->sortBy('id')->last();
            $deducted = round((float) $ledger->sum('tds_deducted'), 2);
            $done = round((float) ($allocated[$employeeId] ?? 0), 2);
            $employee = $last->employee;

            return [
                'employee_id' => $employeeId,
                'code' => (string) $employee?->employee_code,
                'name' => (string) ($employee?->person?->display_name ?? $employee?->employee_code),
                'pan' => ($pan = $employee?->statutoryDetail?->pan) ? (string) EmployeeStatutoryDetail::mask(strtoupper((string) $pan)) : 'PAN not available',
                'tds_annual_ledger_id' => $last->getKey(),
                'gross' => round((float) $ledger->sum('gross'), 2),
                'deducted' => $deducted,
                'allocated' => $done,
                'to_allocate' => round(max(0, $deducted - $done), 2),
            ];
        })->sortBy('code')->values();
    }

    /**
     * §25.6.12 #17: income tax by month for a legal entity — employees with TDS, deducted by finalized payroll (the ledger as
     * it stands), deposited through challans of the register and still to deposit.
     *
     * @return array<string, array{employees: int, deducted: float, deposited: float, to_deposit: float, employees_deposited: int}>
     */
    public function tdsMonths(LegalEntity $entity, string $financialYear): array
    {
        $ledger = TdsAnnualLedger::query()->withoutGlobalScope(AccessScope::class)
            ->where('legal_entity_id', $entity->getKey())->where('financial_year', $financialYear)->where('status', 'active')->where('tds_deducted', '>', 0)
            ->get(['employee_id', 'month', 'tds_deducted'])->groupBy(fn (TdsAnnualLedger $r) => $r->month->format('Y-m'));
        $months = $this->months($financialYear);
        $deposited = StatutoryChallanAllocation::query()->withoutGlobalScope(AccessScope::class)
            ->where('legal_entity_id', $entity->getKey())->whereIn('statutory_challan_id', $this->liveChallanIds())
            ->where('period_month', '>=', $months[0]->toDateString())->where('period_month', '<=', $months[11]->endOfMonth()->toDateString().' 23:59:59')
            ->get(['employee_id', 'period_month', 'amount'])->groupBy(fn (StatutoryChallanAllocation $a) => $a->period_month->format('Y-m'));

        $out = [];
        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $rows = $ledger->get($key, collect());
            $paid = $deposited->get($key, collect());
            $deducted = round((float) $rows->sum('tds_deducted'), 2);
            $in = round((float) $paid->sum('amount'), 2);
            $out[$key] = ['employees' => $rows->pluck('employee_id')->unique()->count(), 'deducted' => $deducted, 'deposited' => $in, 'to_deposit' => round(max(0, $deducted - $in), 2), 'employees_deposited' => $paid->pluck('employee_id')->unique()->count()];
        }

        return $out;
    }

    /**
     * The challans of the register for a quarterly TDS statement (Form No. 138, earlier 24Q): every challan of the legal
     * entity for a month of the quarter that is not cancelled, in the statement's challan shape.
     *
     * @return list<array{bsr_code: string, deposit_date: string, challan_serial: string, amount: float, interest: float, penalty: float, statutory_challan_id: int, month: string}>
     */
    public function forTdsStatement(LegalEntity $entity, string $financialYear, int $quarter): array
    {
        $months = array_slice($this->months($financialYear), ($quarter - 1) * 3, 3);

        return StatutoryChallan::query()->withoutGlobalScope(AccessScope::class)
            ->where('statute', 'tds')->where('legal_entity_id', $entity->getKey())->where('financial_year', $financialYear)
            ->where('status', '!=', StatutoryChallan::CANCELLED)
            ->where('period_month', '>=', $months[0]->toDateString())->where('period_month', '<=', $months[2]->endOfMonth()->toDateString().' 23:59:59')
            ->orderBy('paid_on')->orderBy('id')->get()
            ->map(fn (StatutoryChallan $c) => [
                'bsr_code' => (string) $c->bsr_code,
                'deposit_date' => $c->paid_on->toDateString(),
                'challan_serial' => (string) $c->challan_number,
                'amount' => (float) $c->amount,
                'interest' => (float) $c->interest,
                'penalty' => (float) $c->penalty,
                'statutory_challan_id' => (int) $c->getKey(),
                'month' => $c->period_month->format('Y-m'),
            ])->values()->all();
    }

    /** Every field of a challan for its view (§25.6.12 #16), the allocation masked. */
    public function present(StatutoryChallan $challan): array
    {
        $challan->loadMissing(['recorder', 'verifier', 'canceller', 'registration', 'statutoryReturn', 'establishment', 'legalEntity']);
        $allocations = $challan->statute === 'tds'
            ? StatutoryChallanAllocation::query()->with(['employee.person', 'employee.statutoryDetail'])->where('statutory_challan_id', $challan->getKey())->orderBy('id')->get()
            : collect();

        return [
            'statute' => $challan->statuteLabel(),
            'unit' => $challan->establishment?->name ?? $challan->legalEntity?->legal_name,
            'registration' => $challan->registration?->maskedNumber(),
            'month' => $challan->monthLabel(),
            'return' => $challan->statutoryReturn?->label(),
            'fields' => array_filter([
                'Challan alias' => $challan->alias,
                self::numberLabel($challan->statute) => $challan->challan_number,
                'Acknowledgement no.' => $challan->acknowledgement_number,
                'Nature of payment' => $challan->nature_of_payment,
                'BSR code' => $challan->bsr_code,
                'Challan date' => $challan->paid_on?->format('d M Y'),
                'Payment mode' => config("peopleos.compliance.challan_payment_modes.{$challan->payment_mode}", $challan->payment_mode),
                'Bank' => $challan->bank_name,
                'Branch' => $challan->branch,
            ], fn ($v) => filled($v)),
            'amount' => (float) $challan->amount,
            'interest' => (float) $challan->interest,
            'penalty' => (float) $challan->penalty,
            'total' => $challan->total(),
            'status' => StatutoryChallan::STATUSES[$challan->status] ?? $challan->status,
            'recorded' => trim(($challan->recorder?->name ?? '—').' · '.$challan->recorded_at?->format('d M Y H:i')),
            'verified' => $challan->verified_at ? trim(($challan->verifier?->name ?? '—').' · '.$challan->verified_at->format('d M Y H:i')) : null,
            'cancelled' => $challan->cancelled_at ? trim(($challan->canceller?->name ?? '—').' · '.$challan->cancelled_at->format('d M Y H:i').' · '.$challan->cancel_reason) : null,
            'notes' => $challan->notes,
            'receipt' => $challan->receipt_name,
            'allocations' => $allocations->map(fn (StatutoryChallanAllocation $a) => [
                'code' => (string) $a->employee?->employee_code,
                'name' => (string) ($a->employee?->person?->display_name ?? ''),
                'pan' => ($pan = $a->employee?->statutoryDetail?->pan) ? (string) EmployeeStatutoryDetail::mask(strtoupper((string) $pan)) : 'PAN not available',
                'deducted' => (float) $a->tds_deducted,
                'amount' => (float) $a->amount,
            ])->values()->all(),
        ];
    }

    public static function numberLabel(string $statute): string
    {
        return match ($statute) {
            'pf' => 'TRRN / challan no.',
            'esi' => 'Challan no.',
            'tds' => 'Challan serial no.',
            default => 'Challan / receipt no.',
        };
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------------------------------------------

    /** @return array{label: string, return_type: string, registration: string, scope: string} */
    private function definition(string $statute): array
    {
        return StatutoryChallan::STATUTES[$statute] ?? throw new RuntimeException('Choose provident fund, ESI, professional tax, labour welfare fund or income tax.');
    }

    /** @return Collection<string, Collection<int, StatutoryReturn>> keyed "unit id|period key" */
    private function returnsOfYear(string $statute, string $financialYear, array $unitIds): Collection
    {
        $type = $this->definition($statute)['return_type'];
        $query = StatutoryReturn::query()->where('return_type', $type)->where('status', '!=', StatutoryReturn::CANCELLED);

        if ($this->isEntityLevel($statute)) {
            $query->whereIn('legal_entity_id', $unitIds)->whereIn('period_key', array_map(fn (int $q) => "{$financialYear}-Q{$q}", [1, 2, 3, 4]));

            return $query->orderBy('id')->get()->groupBy(fn (StatutoryReturn $r) => $r->legal_entity_id.'|'.$r->period_key);
        }

        $query->whereIn('establishment_id', $unitIds)->whereIn('period_key', array_map(fn (CarbonImmutable $m) => $m->format('Y-m'), $this->months($financialYear)));

        return $query->orderBy('id')->get()->groupBy(fn (StatutoryReturn $r) => $r->establishment_id.'|'.$r->period_key);
    }

    /** @return Collection<string, Collection<int, StatutoryChallan>> keyed "unit id|YYYY-MM", cancelled ones left out */
    private function challansOfYear(string $statute, string $financialYear, array $unitIds): Collection
    {
        $column = $this->isEntityLevel($statute) ? 'legal_entity_id' : 'establishment_id';

        return StatutoryChallan::query()->where('statute', $statute)->where('financial_year', $financialYear)->whereIn($column, $unitIds)
            ->where('status', '!=', StatutoryChallan::CANCELLED)->orderBy('paid_on')->orderBy('id')->get()
            ->groupBy(fn (StatutoryChallan $c) => $c->{$column}.'|'.$c->period_month->format('Y-m'));
    }

    /** @return Collection<int, StatutoryReturn> */
    private function returnsFor(string $statute, Establishment|LegalEntity $unit, CarbonImmutable $month): Collection
    {
        $query = StatutoryReturn::query()->where('return_type', $this->definition($statute)['return_type'])->where('status', '!=', StatutoryReturn::CANCELLED);

        return ($unit instanceof LegalEntity
            ? $query->where('legal_entity_id', $unit->getKey())->whereNull('establishment_id')->where('period_key', $this->financialYearOf($month).'-Q'.$this->quarterOf($month))
            : $query->where('establishment_id', $unit->getKey())->where('period_key', $month->format('Y-m'))
        )->orderBy('id')->get();
    }

    /** The return that stands for the period: not replaced by a revision, the regular one before supplementary ones, the latest. */
    private function currentOf(Collection $returns): ?StatutoryReturn
    {
        $live = $returns->reject(fn (StatutoryReturn $r) => in_array($r->status, [StatutoryReturn::CANCELLED, StatutoryReturn::REVISED], true));

        return $live->sortBy([fn ($a, $b) => ($a->return_kind === 'regular' ? 0 : 1) <=> ($b->return_kind === 'regular' ? 0 : 1), fn ($a, $b) => $b->id <=> $a->id])->first();
    }

    private function challansFor(string $statute, Establishment|LegalEntity $unit, CarbonImmutable $month)
    {
        return StatutoryChallan::query()->with(['recorder:id,name', 'verifier:id,name'])->where('statute', $statute)
            ->where($unit instanceof LegalEntity ? 'legal_entity_id' : 'establishment_id', $unit->getKey())
            ->where('period_month', '>=', $month->toDateString())->where('period_month', '<=', $month->endOfMonth()->toDateString().' 23:59:59')
            ->orderBy('paid_on')->orderBy('id');
    }

    /** @param  Collection<int, StatutoryChallan>  $challans  (not cancelled) */
    private function cellFrom(CarbonImmutable $month, ?StatutoryReturn $return, Collection $challans, ?array $tds): array
    {
        $returnState = self::returnState($return);
        $paid = $challans->isNotEmpty();
        $state = match (true) {
            $returnState === 'filed' => 'filed',
            $paid => 'paid',
            $returnState === 'not_generated' && $month->gt(now()->startOfMonth()) => 'not_due',
            default => $returnState,
        };

        return [
            'month' => $month,
            'state' => $state,
            'return' => $return,
            'return_state' => $returnState,
            'challans' => $challans->values(),
            'paid' => round((float) $challans->sum(fn (StatutoryChallan $c) => $c->total()), 2),
            'payment_missing' => $returnState === 'filed' && ! $paid,
            'tds' => $tds,
        ];
    }

    /** @return array<int, string> unit id => masked registration number in force today */
    private function registrations(string $statute, Collection $units): array
    {
        $type = $this->definition($statute)['registration'];
        $rows = StatutoryRegistration::query()->where('registration_type', $type)->where('status', 'active')->currentlyEffective()->get();
        $out = [];
        foreach ($units as $unit) {
            $match = $unit instanceof LegalEntity
                ? $rows->first(fn ($r) => (int) $r->legal_entity_id === (int) $unit->getKey())
                : ($rows->first(fn ($r) => (int) $r->establishment_id === (int) $unit->getKey()) ?? $rows->first(fn ($r) => $r->establishment_id === null && (int) $r->legal_entity_id === (int) $unit->legal_entity_id));
            if ($match !== null) {
                $out[$unit->getKey()] = $match->maskedNumber();
            }
        }

        return $out;
    }

    private function registrationFor(string $statute, Establishment|LegalEntity $unit, CarbonImmutable $month): ?StatutoryRegistration
    {
        $query = StatutoryRegistration::query()->withoutGlobalScope(AccessScope::class)->where('registration_type', $this->definition($statute)['registration'])
            ->where('status', 'active')->effectiveOn($month->endOfMonth()->toDateString());

        return $unit instanceof LegalEntity
            ? $query->where('legal_entity_id', $unit->getKey())->orderBy('id')->first()
            : $query->where('legal_entity_id', $unit->legal_entity_id)->where(fn ($q) => $q->where('establishment_id', $unit->getKey())->orWhereNull('establishment_id'))
                ->orderByRaw('case when establishment_id is null then 1 else 0 end')->orderBy('id')->first();
    }

    /** @return Collection<int, TdsAnnualLedger> active ledger rows of the month with TDS deducted */
    private function ledgerRows(LegalEntity $entity, CarbonImmutable $month): Collection
    {
        return TdsAnnualLedger::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'employee.statutoryDetail'])
            ->where('legal_entity_id', $entity->getKey())->where('financial_year', $this->financialYearOf($month))->where('status', 'active')
            ->where('month', '>=', $month->toDateString())->where('month', '<=', $month->endOfMonth()->toDateString().' 23:59:59')
            ->where('tds_deducted', '>', 0)->orderBy('employee_id')->orderBy('id')->get();
    }

    /** @return array<int, float> employee id => TDS of the month already deposited by challans that are not cancelled */
    private function allocatedIn(LegalEntity $entity, CarbonImmutable $month): array
    {
        return StatutoryChallanAllocation::query()->withoutGlobalScope(AccessScope::class)
            ->where('legal_entity_id', $entity->getKey())->whereIn('statutory_challan_id', $this->liveChallanIds())
            ->where('period_month', '>=', $month->toDateString())->where('period_month', '<=', $month->endOfMonth()->toDateString().' 23:59:59')
            ->selectRaw('employee_id, sum(amount) as total')->groupBy('employee_id')->toBase()->get()
            ->mapWithKeys(fn ($r) => [(int) $r->employee_id => (float) $r->total])->all();
    }

    private function liveChallanIds()
    {
        return StatutoryChallan::query()->withoutGlobalScope(AccessScope::class)->where('statute', 'tds')->where('status', '!=', StatutoryChallan::CANCELLED)->select('id');
    }

    /** @return list<int> */
    private function reachableEmployees(User $viewer, array $employeeIds): array
    {
        $module = $this->scopes->moduleFor(StatutoryChallanAllocation::class);
        if (! $this->scopes->isScoped($viewer, $module)) {
            return array_map('intval', $employeeIds);
        }

        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $employeeIds)->whereIn('id', $this->scopes->employeeKeys($viewer, $module))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The allocation rows a new TDS challan takes: the chosen employees (all with TDS left when none were chosen). */
    private function chosenAllocation(LegalEntity $entity, CarbonImmutable $month, User $actor, mixed $employeeIds): Collection
    {
        $rows = $this->tdsAllocation($entity, $month, $actor);
        $open = $rows->filter(fn (array $r) => $r['to_allocate'] > 0)->keyBy('employee_id');

        if (is_array($employeeIds) && $employeeIds !== []) {
            $chosen = collect($employeeIds)->map(fn ($id) => (int) $id)->unique();
            foreach ($chosen as $id) {
                if (! $open->has($id)) {
                    $row = $rows->firstWhere('employee_id', $id);
                    throw new RuntimeException(($row ? "{$row['code']} has no TDS of " : 'An employee chosen has no TDS of ').$month->format('M Y').' left to deposit.');
                }
            }
            $open = $open->only($chosen->all());
        }
        if ($open->isEmpty()) {
            throw new RuntimeException('No TDS deducted by finalized payroll for '.$month->format('M Y').' is left to deposit.');
        }

        return $open->values();
    }

    /** A quarterly statement that copied this challan and is approved or later (then the challan stays as filed). */
    private function statementCarrying(StatutoryChallan $challan): ?StatutoryReturn
    {
        if ($challan->statute !== 'tds') {
            return null;
        }
        $month = CarbonImmutable::parse($challan->getAttributes()['period_month']);

        return StatutoryReturn::query()->withoutGlobalScope(AccessScope::class)->where('return_type', 'TDS')->where('legal_entity_id', $challan->legal_entity_id)
            ->where('period_key', $challan->financial_year.'-Q'.$this->quarterOf($month))
            ->whereNotIn('status', [...StatutoryReturn::EDITABLE, StatutoryReturn::CANCELLED, StatutoryReturn::REVISED])->get()
            ->first(fn (StatutoryReturn $r) => collect($r->attestations['challans'] ?? [])->contains(fn ($c) => (int) ($c['statutory_challan_id'] ?? 0) === (int) $challan->getKey()));
    }

    /** @return array{path: string, name: string}|null the receipt moved from the upload area to the challan folder */
    private function placeReceipt(mixed $upload, mixed $originalName, int $tenantId): ?array
    {
        if (! is_string($upload) || $upload === '') {
            return null;
        }
        $disk = Storage::disk($this->disk());
        $source = ltrim(str_replace(['\\', '..'], ['/', ''], $upload), '/');
        if (! str_starts_with($source, 'statutory-challans/') || ! $disk->exists($source)) {
            throw new RuntimeException('The receipt upload was not found.');
        }
        $original = is_string($originalName) && $originalName !== '' ? $originalName : basename($source);
        try {
            $extension = FileSafety::assertAllowed($original, (int) $disk->size($source), FileSafety::sniff((string) $disk->get($source)));
        } catch (RuntimeException $e) {
            $disk->delete($source);
            throw $e;
        }
        $path = "tenants/{$tenantId}/statutory-challans/".Str::ulid().'.'.$extension;
        $disk->move($source, $path);

        return ['path' => $path, 'name' => Str::limit($original, 250, '')];
    }

    private function disk(): string
    {
        return (string) config('peopleos.documents.disk', 'local');
    }

    private function requireReach(StatutoryChallan $challan, User $actor): void
    {
        if (! $this->scopes->allows($actor, $challan)) {
            throw new RuntimeException('That challan is outside your access.');
        }
    }

    private function requirePermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException("You do not have the {$permission} permission.");
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new RuntimeException('The payment date is not a date.');
        }
    }

    private function money(mixed $value, string $label, bool $positive = false): float
    {
        if ($value === null || $value === '') {
            if ($positive) {
                throw new RuntimeException("{$label} is required.");
            }

            return 0.0;
        }
        if (! is_numeric($value) || (float) $value < 0 || ($positive && (float) $value <= 0)) {
            throw new RuntimeException("{$label} must be ".($positive ? 'more than zero.' : 'zero or more.'));
        }

        return round((float) $value, 2);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}

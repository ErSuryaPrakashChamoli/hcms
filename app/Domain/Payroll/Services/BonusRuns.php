<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\VerifiedThresholds;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Inputs\BonusInput;
use App\Domain\Payroll\Models\BonusRun;
use App\Domain\Payroll\Models\BonusRunLine;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Support\BonusBasis;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity PO-22 (matrix 25 §25.9.3, "Process bonus"): the statutory bonus run of a company for an accounting year.
 *
 * - Rule gate: every month of the year needs a BONUS rule version in force at its end that is VERIFIED, checksum-intact
 *   and free of an open regulatory notice (VerifiedThresholds, the payroll readiness test). Otherwise the year is
 *   "awaiting verification of the BONUS rule" and nothing is prepared, changed, processed or approved — a proposed version
 *   is never used, whatever the enforcement switch says. Unprocessing (taking money back out of a payroll month) is the
 *   one move that stays open.
 * - Basis: BonusBasis over the company's finalized or paid payroll (PaidPayReader::finalEntries), the calculation the
 *   compliance bonus register uses — eligibility ceiling, calculation ceiling, minimum and maximum rate, minimum amount,
 *   minimum days and the payment deadline only from the rule.
 * - The employer's rate: the setting `payroll.bonus.percent` (no default: blank = the rule's minimum rate), refused outside
 *   the rule's range. Per employee: a pay-rate override (only where the rule raises the calculation ceiling to the minimum
 *   wage, never below the ceiling) and an ex gratia amount (non-statutory, classified separately), each with a reason.
 * - Lines: pending → submitted (with a payroll month, by a payroll.calculate holder) → processed (approved by a different
 *   payroll.approve holder, never for their own bonus) → paid (BonusInput settles them when that payroll is finalized).
 *   A processed line not yet paid can be unprocessed (refused while an approved payroll carries it).
 */
final class BonusRuns
{
    public const RULE = 'BONUS';

    public function __construct(private readonly ComplianceRules $rules, private readonly PaidPayReader $paid, private readonly AuditRecorder $audit) {}

    /** @return array{0: Carbon, 1: Carbon, 2: array<string, string>} the year's first day, last day and months ('Y-m' => 'Apr 2026') */
    public static function year(string $financialYear): array
    {
        [$start, $months] = PaidPay::months($financialYear);

        return [$start, $start->copy()->addYear()->subDay()->endOfDay(), $months];
    }

    /** @return array<string, string> accounting years to choose from: this one and the three before, newest first */
    public static function yearOptions(): array
    {
        $fy = FinancialYear::make();
        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $label = $fy->label(today()->subYearsNoOverflow($i));
            $out[$label] = 'FY '.$label;
        }

        return $out;
    }

    /** The year whose bonus is normally being paid: the accounting year before the current one. */
    public static function defaultYear(): string
    {
        $fy = FinancialYear::make();

        return $fy->label($fy->start(today())->subDay());
    }

    /**
     * Whether the year can be calculated, month by month.
     *
     * @return array{ready: bool, rules: array<string, ComplianceRule>, rule: ?ComplianceRule, problems: list<string>}
     */
    public function ruleState(string $financialYear): array
    {
        [, , $months] = self::year($financialYear);
        $verified = new VerifiedThresholds($this->rules);
        $rules = [];
        $waiting = [];
        foreach ($months as $key => $label) {
            $monthEnd = Carbon::parse($key.'-01')->endOfMonth();
            $rule = $verified->rule(self::RULE, null, $monthEnd);
            if ($rule !== null) {
                $rules[$key] = $rule;

                continue;
            }
            $waiting[$this->describe($monthEnd)][] = $label;
        }
        $problems = [];
        foreach ($waiting as $why => $labels) {
            $problems[] = $why.' ('.(count($labels) === count($months) ? 'the whole year' : implode(', ', $labels)).').';
        }

        return ['ready' => $problems === [], 'rules' => $rules, 'rule' => $problems === [] ? $rules[array_key_last($months)] : null, 'problems' => $problems];
    }

    /** Refuses unless a verified BONUS version applies to every month of the year. */
    public function assertReady(string $financialYear): array
    {
        $state = $this->ruleState($financialYear);
        if (! $state['ready']) {
            throw new RuntimeException('Awaiting verification of the BONUS rule: '.implode(' ', $state['problems']).' Bonus is not calculated, processed or approved until a verified version applies to every month of the year.');
        }

        return $state;
    }

    /**
     * The employer's bonus rate for the year: the setting `payroll.bonus.percent`, or the rule's minimum rate when blank,
     * within the range every month's version allows.
     *
     * @param  array<string, ComplianceRule>  $rules
     * @return array{0: float, 1: string} the rate (a fraction) and where it came from (setting | rule_minimum)
     */
    public function rate(array $rules): array
    {
        $min = max(array_map(fn (ComplianceRule $r) => (float) $r->param('minimum_rate'), $rules));
        $max = min(array_map(fn (ComplianceRule $r) => (float) ($r->param('maximum_rate') ?? $r->param('minimum_rate')), $rules));
        $setting = app(SettingsRepository::class)->get('payroll.bonus.percent');
        if ($setting === null || $setting === '') {
            return [$min, 'rule_minimum'];
        }
        if (! is_numeric($setting)) {
            throw new RuntimeException('The bonus percentage (Setup centre → Payroll) is not a number.');
        }
        $percent = round((float) $setting, 2);
        // A percentage typed to two decimals stands for the rule's own rate it rounds to (8.33% = 8⅓%).
        $rate = match (true) {
            $percent === round($min * 100, 2) => $min,
            $percent === round($max * 100, 2) => $max,
            default => $percent / 100,
        };
        if ($rate < $min || $rate > $max) {
            throw new RuntimeException("The bonus percentage {$percent}% (Setup centre → Payroll) is outside the BONUS rule's range of ".self::percent($min).' to '.self::percent($max).'.');
        }

        return [$rate, 'setting'];
    }

    /**
     * The rate a stored (six-decimal) rate stands for: the rule's own minimum or maximum rate when it rounds to it (8⅓% is
     * stored as 0.083333), so the bonus is never short by the rounding of the stored figure.
     */
    public static function exactRate(float $stored, ComplianceRule $rule): float
    {
        foreach (['minimum_rate', 'maximum_rate'] as $parameter) {
            $value = $rule->param($parameter);
            if (is_numeric($value) && abs((float) $value - $stored) < 0.000001) {
                return (float) $value;
            }
        }

        return $stored;
    }

    public static function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2), '0'), '.').'%';
    }

    public function find(Company|int $company, string $financialYear): ?BonusRun
    {
        return BonusRun::query()->where('company_id', $company instanceof Company ? $company->getKey() : $company)->where('financial_year', $financialYear)->first();
    }

    /**
     * Prepare the run (or recalculate it): one line per employee with finalized payroll in the year, in the preparer's
     * access scope. Lines already sent for approval, processed or paid — and lines a payroll being prepared carries — are
     * left as they are; HR's decisions on the others (pay-rate override, ex gratia, include / exclude) are kept.
     */
    public function prepare(Company $company, string $financialYear, User $user): BonusRun
    {
        $this->authorize($user, 'payroll.calculate');
        $state = $this->assertReady($financialYear);
        [$rate, $source] = $this->rate($state['rules']);
        [$start, $end, $months] = self::year($financialYear);
        $rule = $state['rule'];
        $deadline = is_numeric($rule->param('payment_within_months')) ? $end->copy()->startOfDay()->addMonthsNoOverflow((int) $rule->param('payment_within_months')) : null;

        $rows = [];
        foreach ($months as $key => $label) {
            foreach ($this->paid->finalEntries((int) $company->getKey(), $key) as $entry) {
                $id = (int) $entry->employee_id;
                $rows[$id] ??= BonusBasis::row($entry->employee) + ['first' => $key, 'last' => $key];
                BonusBasis::addMonth($rows[$id], $entry, $state['rules'][$key], $key);
                $rows[$id]['last'] = $key;
                $rows[$id]['paid'] += BonusBasis::paid($entry);
            }
        }
        // Bonus already paid for the year also counts when paid in the months allowed after it, up to this month.
        $until = Carbon::now()->startOfMonth();
        if ($deadline !== null && $deadline->copy()->startOfMonth()->lt($until)) {
            $until = $deadline->copy()->startOfMonth();
        }
        for ($month = $end->copy()->addDay()->startOfMonth(); $month->lte($until); $month->addMonth()) {
            foreach ($this->paid->finalEntries((int) $company->getKey(), $month->format('Y-m')) as $entry) {
                if (isset($rows[(int) $entry->employee_id])) {
                    $rows[(int) $entry->employee_id]['paid'] += BonusBasis::paid($entry);
                }
            }
        }
        $payGroups = $this->payGroups(array_keys($rows), $end->lt(today()) ? $end : today());
        $refs = collect($state['rules'])->map(fn (ComplianceRule $r) => ['id' => $r->getKey(), 'version' => $r->version, 'label' => $r->label(), 'checksum' => $r->checksum])->all();

        return DB::transaction(function () use ($company, $financialYear, $user, $rate, $source, $start, $end, $deadline, $rule, $rows, $payGroups, $refs) {
            $run = BonusRun::query()->where('company_id', $company->getKey())->where('financial_year', $financialYear)->lockForUpdate()->first();
            $attributes = ['period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'bonus_rate' => $rate, 'rate_source' => $source, 'rules' => $refs,
                'payment_deadline' => $deadline?->toDateString()];
            if ($run === null) {
                $run = new BonusRun(['company_id' => $company->getKey(), 'financial_year' => $financialYear, 'prepared_by' => $user->getKey(), 'prepared_at' => now()] + $attributes);
                $run->withAuditReason("Bonus prepared for FY {$financialYear} under {$rule->label()}")->save();
            } else {
                $run->withAuditReason("Bonus recalculated for FY {$financialYear} under {$rule->label()}")->update($attributes + ['recalculated_at' => now()]);
            }

            $existing = BonusRunLine::query()->where('bonus_run_id', $run->getKey())->lockForUpdate()->get()->keyBy('employee_id');
            $carried = $this->carried(array_keys($rows));
            foreach ($rows as $employeeId => $row) {
                $line = $existing->get($employeeId);
                if ($line !== null && ($line->status !== 'pending' || isset($carried[$line->getKey()]))) {
                    continue;
                }
                $line ??= new BonusRunLine(['bonus_run_id' => $run->getKey(), 'employee_id' => $employeeId]);
                $this->fill($line, $row, $rule, $rate, $payGroups[$employeeId] ?? null);
                $line->withAuditReason($line->exists ? 'Bonus recalculated' : 'Bonus prepared')->save();
            }

            return $run->refresh();
        });
    }

    /**
     * HROne's "Pay rate" edit: the monthly wage the bonus is calculated as if — only where the BONUS rule raises the
     * calculation ceiling to the applicable minimum wage (which PeopleOS does not hold), never below the ceiling. Null
     * clears the override. Audited with the reason.
     */
    public function overridePayRate(BonusRunLine $line, ?float $payRate, string $reason, User $user): BonusRunLine
    {
        $this->authorize($user, 'payroll.calculate');
        $this->assertReason($reason);

        return $this->change($line, $user, function (BonusRunLine $locked, ComplianceRule $rule) use ($payRate, $reason) {
            if (! in_array($locked->eligibility, ['eligible', 'review'], true)) {
                throw new RuntimeException('Only an employee eligible for bonus has a pay rate to change.');
            }
            if ($rule->param('calculation_ceiling_rule') !== 'max_with_minimum_wage') {
                throw new RuntimeException("{$rule->label()} fixes the calculation ceiling: pay anything above the statutory bonus as ex gratia.");
            }
            $ceiling = max(array_map(fn (array $m) => (float) ($m['ceiling'] ?? 0), (array) ($locked->basis['months'] ?? [])) ?: [0]);
            if ($payRate !== null && round($payRate, 2) < round($ceiling, 2)) {
                throw new RuntimeException('The pay rate cannot be below the calculation ceiling of ₹'.number_format($ceiling).' a month: that would pay less than the statutory bonus.');
            }
            $locked->fill(['pay_rate_override' => $payRate === null ? null : round($payRate, 2), 'override_reason' => $payRate === null ? null : $reason]);

            return $reason;
        });
    }

    /** Ex gratia: an amount HR decides (not statutory bonus, classified separately in payroll), with a reason. 0 clears it. */
    public function setExGratia(BonusRunLine $line, float $amount, ?string $reason, User $user): BonusRunLine
    {
        $this->authorize($user, 'payroll.calculate');
        if ($amount < 0) {
            throw new RuntimeException('Ex gratia cannot be negative.');
        }
        if ($amount > 0) {
            $this->assertReason((string) $reason);
        }

        return $this->change($line, $user, function (BonusRunLine $locked) use ($amount, $reason) {
            $locked->fill(['ex_gratia' => round($amount, 2), 'ex_gratia_reason' => $amount > 0 ? $reason : null]);

            return $amount > 0 ? (string) $reason : 'Ex gratia cleared';
        });
    }

    /**
     * Include or leave out an employee's statutory bonus: an eligible employee is included unless HR leaves them out
     * (e.g. disqualified), one whose wages crossed the eligibility ceiling during the year waits for HR to decide. Either
     * decision needs a reason. Ineligible employees cannot be included (ex gratia stays possible).
     */
    public function setIncluded(BonusRunLine $line, bool $included, string $reason, User $user): BonusRunLine
    {
        $this->authorize($user, 'payroll.calculate');
        $this->assertReason($reason);

        return $this->change($line, $user, function (BonusRunLine $locked) use ($included, $reason) {
            if ($included && ! in_array($locked->eligibility, ['eligible', 'review'], true)) {
                throw new RuntimeException('This employee is not eligible for statutory bonus ('.$locked->reason.'). Pay ex gratia instead.');
            }
            $locked->fill(['included' => $included, 'include_reason' => $reason]);

            return $reason;
        });
    }

    /**
     * Send lines for approval with the payroll month that pays them (HROne's "Process bonus"): pending lines with something
     * to pay, not one's own, not carried by a payroll being prepared. Null = every such line of the run.
     *
     * @param  list<int>|null  $lineIds
     * @return array{submitted: int, skipped: list<string>, late: bool}
     */
    public function submit(BonusRun $run, ?array $lineIds, string $payMonth, User $user): array
    {
        $this->authorize($user, 'payroll.calculate');
        $this->assertReady($run->financial_year);
        $this->assertPayMonth($run, $payMonth);

        $result = ['submitted' => 0, 'skipped' => [], 'late' => $run->payment_deadline !== null && $payMonth > $run->payment_deadline->format('Y-m')];
        $this->audit->operation('payroll', "Bonus FY {$run->financial_year} sent for approval", function () use ($run, $lineIds, $payMonth, $user, &$result) {
            DB::transaction(function () use ($run, $lineIds, $payMonth, $user, &$result) {
                $lines = $this->lines($run, $lineIds, ['pending']);
                $carried = $this->carried($lines->pluck('employee_id')->all());
                foreach ($lines as $line) {
                    $name = $this->name($line);
                    if ($line->total() <= 0) {
                        $result['skipped'][] = "{$name}: nothing to pay.";
                    } elseif ($this->isOwn($line, $user)) {
                        $result['skipped'][] = "{$name}: someone else processes your own bonus.";
                    } elseif (isset($carried[$line->getKey()])) {
                        $result['skipped'][] = "{$name}: still in the payroll of {$carried[$line->getKey()]['period']} being prepared — recalculate that payroll first.";
                    } else {
                        $line->withAuditReason("Sent for approval, to be paid with payroll {$payMonth}")->update(['status' => 'submitted', 'pay_month' => $payMonth, 'submitted_by' => $user->getKey(), 'submitted_at' => now(), 'return_note' => null]);
                        $result['submitted']++;
                    }
                }
            });

            return $result['submitted'];
        }, entityType: BonusRunLine::class);

        if ($result['submitted'] === 0) {
            throw new RuntimeException('Nothing was sent for approval. '.implode(' ', array_slice($result['skipped'], 0, 5)));
        }

        return $result;
    }

    /**
     * Approve lines waiting for approval (a second person): they are processed — paid by the payroll of their month (or
     * the first one calculated after it) through BonusInput. Never by the person who sent them or changed them, never for
     * one's own bonus.
     *
     * @param  list<int>|null  $lineIds
     * @return array{approved: int, skipped: list<string>}
     */
    public function approve(BonusRun $run, ?array $lineIds, User $approver, ?string $note = null): array
    {
        $this->authorize($approver, 'payroll.approve');
        $this->assertReady($run->financial_year);

        $result = ['approved' => 0, 'skipped' => []];
        $this->audit->operation('payroll', "Bonus FY {$run->financial_year} approved", function () use ($run, $lineIds, $approver, $note, &$result) {
            DB::transaction(function () use ($run, $lineIds, $approver, $note, &$result) {
                foreach ($this->lines($run, $lineIds, ['submitted']) as $line) {
                    $name = $this->name($line);
                    if ((int) $line->submitted_by === (int) $approver->getKey() || ($line->edited_by !== null && (int) $line->edited_by === (int) $approver->getKey())) {
                        $result['skipped'][] = "{$name}: the person who prepared or sent this bonus cannot approve it.";
                    } elseif ($this->isOwn($line, $approver)) {
                        $result['skipped'][] = "{$name}: you cannot approve your own bonus.";
                    } else {
                        $line->withAuditReason($note ?: 'Bonus approved')->update(['status' => 'processed', 'approved_by' => $approver->getKey(), 'approved_at' => now()]);
                        $result['approved']++;
                    }
                }
            });

            return $result['approved'];
        }, entityType: BonusRunLine::class);

        if ($result['approved'] === 0) {
            throw new RuntimeException('Nothing was approved. '.implode(' ', array_slice($result['skipped'], 0, 5)));
        }

        return $result;
    }

    /**
     * Send lines waiting for approval back to "Not processed", with the reason.
     *
     * @param  list<int>|null  $lineIds
     */
    public function sendBack(BonusRun $run, ?array $lineIds, User $approver, string $note): int
    {
        $this->authorize($approver, 'payroll.approve');
        $this->assertReason($note);

        $count = 0;
        $this->audit->operation('payroll', "Bonus FY {$run->financial_year} sent back", function () use ($run, $lineIds, $note, &$count) {
            DB::transaction(function () use ($run, $lineIds, $note, &$count) {
                foreach ($this->lines($run, $lineIds, ['submitted']) as $line) {
                    $line->withAuditReason($note)->update(['status' => 'pending', 'pay_month' => null, 'submitted_by' => null, 'submitted_at' => null, 'return_note' => $note]);
                    $count++;
                }
            });

            return $count;
        }, $note, BonusRunLine::class);

        return $count;
    }

    /**
     * Unprocess processed lines not yet paid: back to "Not processed". Refused while an approved payroll carries the line
     * (it is about to be paid); a payroll still being prepared that carries it must be recalculated, and if it is finalized
     * first it pays the bonus and the line is settled as paid.
     *
     * @param  list<int>  $lineIds
     * @return array{unprocessed: int, skipped: list<string>, recalculate: list<string>}
     */
    public function unprocess(BonusRun $run, array $lineIds, string $reason, User $user): array
    {
        $this->authorize($user, 'payroll.calculate');
        $this->assertReason($reason);

        $result = ['unprocessed' => 0, 'skipped' => [], 'recalculate' => []];
        $this->audit->operation('payroll', "Bonus FY {$run->financial_year} unprocessed", function () use ($run, $lineIds, $reason, &$result) {
            DB::transaction(function () use ($run, $lineIds, $reason, &$result) {
                $lines = $this->lines($run, $lineIds, ['processed', 'paid']);
                $carried = $this->carried($lines->pluck('employee_id')->all());
                foreach ($lines as $line) {
                    $name = $this->name($line);
                    $carry = $carried[$line->getKey()] ?? null;
                    if ($line->status === 'paid' || $line->paid_payroll_run_id !== null) {
                        $result['skipped'][] = "{$name}: already paid.";
                    } elseif ($carry !== null && $carry['status'] === 'approved') {
                        $result['skipped'][] = "{$name}: the approved payroll of {$carry['period']} pays it.";
                    } else {
                        $line->withAuditReason($reason)->update(['status' => 'pending', 'pay_month' => null, 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null, 'return_note' => $reason]);
                        $result['unprocessed']++;
                        if ($carry !== null) {
                            $result['recalculate'][$carry['period']] = 'the payroll of '.$carry['period'];
                        }
                    }
                }
            });

            return $result['unprocessed'];
        }, $reason, BonusRunLine::class);
        $result['recalculate'] = array_values($result['recalculate']);

        if ($result['unprocessed'] === 0) {
            throw new RuntimeException('Nothing was unprocessed. '.implode(' ', array_slice($result['skipped'], 0, 5)));
        }

        return $result;
    }

    /**
     * The payroll runs not yet finalized whose calculated entries carry bonus lines of these employees.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array{period: string, status: string}> line id => the payroll month carrying it ('Oct 2026') and its run status
     */
    public function carried(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $out = [];
        PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $employeeIds)
            ->whereHas('run', fn ($q) => $q->whereNotIn('status', PaidPay::FINAL))->with('run.period')->get(['id', 'payroll_run_id', 'inputs'])
            ->each(function (PayrollEntry $entry) use (&$out) {
                foreach ((array) ($entry->inputs['input_sources'][BonusInput::KEY] ?? []) as $id) {
                    $out[(int) $id] = ['period' => $entry->run?->period?->label() ?? 'a month', 'status' => (string) $entry->run?->status];
                }
            });

        return $out;
    }

    /** Recompute a line's amounts from its stored basis (after an override, ex gratia or include change). */
    public function recompute(BonusRunLine $line, ComplianceRule $rule): void
    {
        $computable = in_array($line->eligibility, ['eligible', 'review'], true);
        $override = $line->pay_rate_override !== null ? (float) $line->pay_rate_override : null;
        $months = (array) ($line->basis['months'] ?? []);
        $bonusWages = $computable ? round(BonusBasis::bonusWagesAt($months, $override), 2) : null;
        $rate = self::exactRate((float) $line->bonus_rate, $rule);
        $statutory = $computable ? max(round((float) $bonusWages * $rate, 2), (float) $rule->param('minimum_amount', 0)) : 0.0;
        $included = $computable && $line->included;

        $line->fill([
            'bonus_wages' => $bonusWages,
            'pay_rate' => $computable ? ($override ?? max(array_map(fn (array $m) => (float) ($m['ceiling'] ?? 0), $months) ?: [0])) : null,
            'statutory_bonus' => $statutory,
            'included' => $included,
            'bonus_amount' => $included ? max(0.0, round($statutory - (float) $line->already_paid, 2)) : 0.0,
        ]);
        $basis = (array) $line->basis;
        $basis['minimum'] = $computable ? BonusBasis::minimum((float) $bonusWages, $rule) : null;
        $basis['maximum'] = $computable ? BonusBasis::maximum((float) $bonusWages, $rule) : null;
        $line->basis = $basis;
    }

    /** @param  array<string, mixed>  $row  a BonusBasis accumulator */
    private function fill(BonusRunLine $line, array $row, ComplianceRule $rule, float $rate, ?string $payGroup): void
    {
        [$determination, $reason, $kind] = BonusBasis::determine($row, $rule);
        $eligibility = match (true) {
            $determination === BonusBasis::ELIGIBLE, $determination === BonusBasis::PENDING && $kind === BonusBasis::MINIMUM_WAGE => 'eligible',
            $determination === BonusBasis::PENDING => 'review',
            $determination === BonusBasis::INELIGIBLE => 'ineligible',
            default => 'insufficient_data',
        };
        if ($kind === BonusBasis::MINIMUM_WAGE) {
            $reason .= ' Change the pay rate when the minimum wage is higher.';
        }
        // HR's include / leave-out decision is kept across recalculations; otherwise eligible employees are included.
        $included = $line->include_reason !== null ? (bool) $line->included : $eligibility === 'eligible';

        $line->fill([
            'eligibility' => $eligibility,
            'reason' => mb_substr($reason, 0, 500),
            'pay_group' => $payGroup,
            'months' => $row['months'],
            'from_month' => $row['first'],
            'to_month' => $row['last'],
            'bonus_days' => round($row['days'], 2),
            'wages' => round($row['wages'], 2),
            'bonus_rate' => $rate,
            'already_paid' => round($row['paid'], 2),
            'included' => $included,
            'ex_gratia' => $line->ex_gratia ?? 0,
            'basis' => ['determination' => $determination, 'pending' => $kind, 'months' => $row['detail'], 'rule_id' => $rule->getKey(), 'rule' => $rule->label(),
                'minimum_days' => $rule->param('minimum_days'), 'eligibility_ceiling' => $rule->param('eligibility_ceiling')],
        ]);
        $this->recompute($line, $rule);
    }

    /**
     * Change a pending line under a row lock, then recompute it. The callback returns the audit reason.
     *
     * @param  callable(BonusRunLine, ComplianceRule): string  $change
     */
    private function change(BonusRunLine $line, User $user, callable $change): BonusRunLine
    {
        $run = $line->run()->firstOrFail();
        $state = $this->assertReady($run->financial_year);

        return DB::transaction(function () use ($line, $user, $change, $state) {
            $locked = BonusRunLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new RuntimeException('Only a bonus not yet processed can be changed: unprocess it, or send it back, first.');
            }
            if ($this->isOwn($locked, $user)) {
                throw new RuntimeException('You cannot change your own bonus.');
            }
            if ($carry = $this->carried([(int) $locked->employee_id])[$locked->getKey()] ?? null) {
                throw new RuntimeException("This bonus is still in the payroll of {$carry['period']} being prepared: recalculate that payroll first.");
            }
            $reason = $change($locked, $state['rule']);
            $locked->edited_by = $user->getKey();
            $this->recompute($locked, $state['rule']);
            $locked->withAuditReason($reason)->save();

            return $locked;
        });
    }

    /**
     * @param  list<int>|null  $ids
     * @param  list<string>  $statuses
     * @return Collection<int, BonusRunLine>
     */
    private function lines(BonusRun $run, ?array $ids, array $statuses): Collection
    {
        return BonusRunLine::query()->with('employee.person')->where('bonus_run_id', $run->getKey())->whereIn('status', $statuses)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', array_map('intval', $ids)))
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function assertPayMonth(BonusRun $run, string $payMonth): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $payMonth) !== 1) {
            throw new RuntimeException('Choose the payroll month that pays the bonus (YYYY-MM).');
        }
        if ($payMonth < now()->subMonthNoOverflow()->format('Y-m')) {
            throw new RuntimeException('Pay the bonus with this month\'s payroll or a later one.');
        }
        [$year, $month] = array_map('intval', explode('-', $payMonth));
        $final = PayrollRun::query()->where('company_id', $run->company_id)->whereIn('status', PaidPay::FINAL)
            ->whereHas('period', fn ($q) => $q->where('year', $year)->where('month', $month))->exists();
        if ($final) {
            throw new RuntimeException('The payroll of '.Carbon::create($year, $month, 1)->format('M Y').' is already finalized: choose a later month.');
        }
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException("Processing bonus needs the {$permission} permission.");
        }
    }

    private function isOwn(BonusRunLine $line, User $user): bool
    {
        return (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($line->employee_id)->value('user_id') === (int) $user->getKey();
    }

    private function name(BonusRunLine $line): string
    {
        return (string) ($line->employee?->person?->full_name ?: $line->employee?->employee_code ?: 'Employee #'.$line->employee_id);
    }

    /**
     * HROne's "Pay group": the code of the salary structure of each employee's compensation in force on the date, through
     * the compensation read contract (the screen shows the structure's name).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, string>
     */
    private function payGroups(array $employeeIds, Carbon $on): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return collect(app(CompensationOutput::class)->inForceOn($employeeIds, $on))->map(fn ($s) => (string) $s->structureCode)->filter()->all();
    }

    /** The reason the BONUS rule cannot be used on a date. */
    private function describe(Carbon $on): string
    {
        ['active' => $active, 'proposed' => $proposed] = $this->rules->resolveWithProposed(self::RULE, $on);

        return match (true) {
            $active !== null && ! $active->isVerified() => "{$active->label()} is {$active->verification_status}, not verified against an official source",
            $active !== null && ! $active->checksumIntact() => "{$active->label()} does not match its recorded checksum",
            $active !== null => "{$active->label()} is affected by an open regulatory notice",
            $proposed !== null => "{$proposed->label()} is proposed and awaits verification",
            default => 'No BONUS rule version applies',
        };
    }
}

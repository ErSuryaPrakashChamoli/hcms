<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Platform\Services\SettingsRepository;

/** Immutable payslip snapshots (§31). */
final class Payslips
{
    public function __construct(private readonly AuditRecorder $audit, private readonly SettingsRepository $settings) {}

    public function generateForRun(PayrollRun $run): int
    {
        $count = 0;

        $run->entries()->with(['lines', 'employee.person', 'employee.statutoryDetail', 'employee.bankAccounts', 'employee.currentPosition.designation', 'employee.currentPosition.department'])->get()
            ->each(function (PayrollEntry $entry) use ($run, &$count) {
                if ($entry->status === 'exception') {
                    return;
                }
                $this->generate($entry, $run);
                $count++;
            });

        return $count;
    }

    public function generate(PayrollEntry $entry, PayrollRun $run): Payslip
    {
        $run->loadMissing(['period', 'company']);
        $employee = $entry->employee;
        $position = $employee->currentPosition;
        $bank = $employee->bankAccounts->firstWhere('is_primary', true);
        $statutory = $employee->statutoryDetail;
        $number = sprintf('%s-%d%02d-%s', $this->settings->get('payroll.payslip.prefix', 'PS'), $run->period->year, $run->period->month, $employee->employee_code);

        $snapshot = [
            'number' => $number,
            'period' => ['label' => $run->period->label(), 'year' => $run->period->year, 'month' => $run->period->month, 'start' => $run->period->start_date->toDateString(), 'end' => $run->period->end_date->toDateString()],
            'company' => ['name' => $run->company->legal_name ?: $run->company->name, 'code' => $run->company->code],
            'employee' => [
                'code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'joining_date' => $employee->joining_date?->toDateString(),
                'designation' => $position?->designation?->name, 'department' => $position?->department?->name,
                'pan' => EmployeeStatutoryDetail::mask($statutory?->pan), 'uan' => EmployeeStatutoryDetail::mask($statutory?->uan), 'esic' => EmployeeStatutoryDetail::mask($statutory?->esic_number),
                'bank' => $bank ? ['name' => $bank->bank_name, 'account' => $bank->maskedAccountNumber()] : null,
            ],
            'days' => ['in_period' => $entry->days_in_period, 'paid' => (float) $entry->paid_days, 'lop' => (float) $entry->lop_days],
            'earnings' => $entry->lines->whereIn('type', ['earning', 'reimbursement'])->values()->map(fn ($l) => ['code' => $l->code, 'name' => $l->name, 'amount' => (float) $l->amount])->all(),
            'deductions' => $entry->lines->where('type', 'deduction')->values()->map(fn ($l) => ['code' => $l->code, 'name' => $l->name, 'amount' => (float) $l->amount])->all(),
            'employer_contributions' => $entry->lines->where('type', 'employer_contribution')->values()->map(fn ($l) => ['code' => $l->code, 'name' => $l->name, 'amount' => (float) $l->amount])->all(),
            'totals' => ['gross' => (float) $entry->gross, 'earnings' => (float) $entry->total_earnings, 'deductions' => (float) $entry->total_deductions, 'net' => (float) $entry->net_pay, 'employer_cost' => (float) $entry->employer_cost],
            'tax' => data_get($entry->inputs, 'tax'),
            'net_in_words' => self::inWords((int) round((float) $entry->net_pay)).' rupees only',
        ];

        $payslip = Payslip::query()->updateOrCreate(['payroll_entry_id' => $entry->id], ['employee_id' => $employee->id, 'number' => $number, 'snapshot' => $snapshot, 'generated_at' => now()]);
        $this->audit->record(AuditAction::PayslipGenerated, 'payroll', $payslip, [], null, metadata: ['employee_id' => $employee->id, 'period' => $run->period->label()]);
        PayrollEvent::dispatch('payroll.payslip_generated', $payslip, ['employee_id' => $employee->id, 'period' => $run->period->label(), 'net' => (float) $entry->net_pay]);

        return $payslip;
    }

    public function withdrawForRun(PayrollRun $run): int
    {
        $ids = $run->entries()->pluck('id');
        $payslips = Payslip::query()->whereIn('payroll_entry_id', $ids)->get();

        foreach ($payslips as $payslip) {
            $this->audit->record(AuditAction::Delete, 'payroll', $payslip, [], 'Payroll run reopened');
            $payslip->delete();
        }

        return $payslips->count();
    }

    /** Indian-style number to words (lakhs / crores). */
    public static function inWords(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $below100 = fn (int $x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)].' '.$ones[$x % 10]);
        $below1000 = fn (int $x) => $x < 100 ? $below100($x) : trim($ones[intdiv($x, 100)].' hundred '.$below100($x % 100));
        $parts = [];
        foreach ([['crore', 10000000], ['lakh', 100000], ['thousand', 1000]] as [$word, $div]) {
            if ($n >= $div) {
                $parts[] = $below1000(intdiv($n, $div)).' '.$word;
                $n %= $div;
            }
        }
        if ($n > 0) {
            $parts[] = $below1000($n);
        }

        return ucfirst(trim(implode(' ', $parts)));
    }
}

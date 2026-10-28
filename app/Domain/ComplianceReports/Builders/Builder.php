<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Contracts\ReportBuilder;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\People\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Shared helpers for report builders: names, particulars, dates and payroll line sums. */
abstract class Builder implements ReportBuilder
{
    protected const STATUTORY_CLASSES = ['pf_employee', 'esi_employee', 'pt', 'lwf_employee', 'tds'];

    protected function sheet(ReportContext $context, array $columns, array $rows, array $options = []): ReportSheet
    {
        return new ReportSheet(
            title: $options['title'] ?? $context->definition->title,
            columns: $columns,
            rows: $rows,
            header: $context->header($options['registration'] ?? $context->definition->registration),
            totals: $options['totals'] ?? null,
            notes: [...($options['notes'] ?? []), ...$context->definition->notes],
            money: $options['money'] ?? [],
            message: $options['message'] ?? null,
            sheetName: $options['sheet'] ?? '',
            warnings: array_values(array_unique(array_filter($options['warnings'] ?? []))),
            rules: $options['rules'] ?? [],
        );
    }

    /**
     * Months of the period whose payroll is missing from the report: not finalized yet, or with employees at the
     * establishment but no finalized payroll at all.
     *
     * @return list<string>
     */
    protected function payrollGaps(ReportContext $context): array
    {
        $gaps = [];
        foreach ($context->period->months() as $month) {
            if ($context->data->payrollFor($context, $month)->isNotEmpty()) {
                continue;
            }
            $open = $context->data->openRuns($context->company, $month)->count();
            if ($open > 0) {
                $gaps[] = "Payroll for {$month->label()} is not finalized ({$open} open run(s)): its figures are not in this report.";
            } elseif ($context->establishment !== null && $context->data->employees($context->company, $month->start, $month->end, $context->establishment)->isNotEmpty()) {
                $gaps[] = "No finalized payroll for {$month->label()} although employees were employed here.";
            }
        }

        return $gaps;
    }

    /**
     * Employees (or payroll entries) attributed to this establishment by fallback — through their work location or the
     * company's principal establishment — rather than an explicit establishment assignment.
     *
     * @param  iterable<Model>  $records  employees or payroll entries
     * @return list<string>
     */
    protected function attributionGaps(iterable $records): array
    {
        $fallback = collect($records)->filter(fn ($r) => in_array($r->getAttribute('report_establishment_source'), ['company_primary', 'resolved_at_return', null], true))
            ->map(fn ($r) => $r instanceof PayrollEntry ? $r->employee_id : $r->getKey())->unique()->count();

        return $fallback === 0 ? [] : ["{$fallback} employee(s) have no establishment assignment and were attributed to this establishment by fallback (the company's principal establishment): assign them under Organisation → Establishment assignments."];
    }

    /** A missing registration number the report is filed under. */
    protected function registrationGaps(ReportContext $context, ?string $type = null): array
    {
        $type ??= $context->definition->registration;
        if ($type === null || $context->data->registrationNumber($context, $type) !== null) {
            return [];
        }

        return ['No '.config("peopleos.compliance.registration_types.{$type}.label", $type).' is recorded for '.($context->establishment?->name ?? $context->legalEntity?->legal_name ?? 'this report').' (Compliance → Statutory registrations).'];
    }

    /**
     * Rows missing an identifier the authority needs (UAN, ESI IP number, PAN).
     *
     * @param  iterable<PayrollEntry>  $entries
     */
    protected function identifierGaps(iterable $entries, string $field, string $label): array
    {
        $missing = collect($entries)->filter(fn (PayrollEntry $e) => trim((string) $e->employee?->statutoryDetail?->{$field}) === '')->pluck('employee_id')->unique()->count();

        return $missing === 0 ? [] : ["{$missing} employee(s) have no {$label} on file."];
    }

    /** @param  iterable<PayrollEntry>  $entries */
    protected function rules(ReportContext $context, iterable $entries, array $codes): array
    {
        return $context->data->ruleRefs($entries, $codes);
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function numbered(array $rows): array
    {
        return array_map(fn (array $row, int $i) => ['sl' => $i + 1] + $row, $rows, array_keys($rows));
    }

    protected function name(?Employee $employee): string
    {
        return (string) ($employee?->person?->full_name ?: $employee?->employee_code);
    }

    /** Father's or spouse's name from the family members on file (the registers ask for either). */
    protected function relativeName(?Person $person): string
    {
        $members = $person?->familyMembers ?? collect();
        $member = $members->firstWhere('relation', 'father') ?? $members->firstWhere('relation', 'spouse');

        return (string) ($member?->name ?? '');
    }

    protected function gender(?Person $person): string
    {
        return match (strtolower((string) $person?->gender)) {
            'male', 'm' => 'M',
            'female', 'f' => 'F',
            '' => '',
            default => 'O',
        };
    }

    protected function date(CarbonInterface|string|null $date): string
    {
        return $date === null || $date === '' ? '' : Carbon::parse($date)->format('d-m-Y');
    }

    protected function designation(ReportContext $context, Employee $employee, CarbonInterface|string|null $on = null): string
    {
        return (string) $context->data->positionOn($employee, $on ?? $context->period->end)?->designation?->name;
    }

    protected function department(ReportContext $context, Employee $employee, CarbonInterface|string|null $on = null): string
    {
        return (string) $context->data->positionOn($employee, $on ?? $context->period->end)?->department?->name;
    }

    protected function amount(PayrollEntry $entry, string $code): float
    {
        return round((float) $entry->lines->where('code', $code)->sum('amount'), 2);
    }

    /** @param  list<string>  $classifications */
    protected function byClass(PayrollEntry $entry, array $classifications, ?string $type = null): float
    {
        return round((float) $entry->lines->filter(fn ($l) => in_array($l->classification, $classifications, true) && ($type === null || $l->type === $type))->sum('amount'), 2);
    }

    /** The full-month (unprorated) amount of the entry's earnings of these classifications. */
    protected function rate(PayrollEntry $entry, ?array $classifications = null): float
    {
        return round((float) $entry->lines->filter(fn ($l) => $l->type === 'earning' && ($classifications === null || in_array($l->classification, $classifications, true)))
            ->sum(fn ($l) => (float) (($l->basis['full_month'] ?? null) ?? $l->amount)), 2);
    }

    protected function primaryAccount(ReportContext $context, ?Employee $employee): string
    {
        $account = $employee?->bankAccounts?->firstWhere('is_primary', true) ?? $employee?->bankAccounts?->first();
        if ($account === null) {
            return '';
        }

        return trim($account->bank_name.' '.($context->unmasked ? $account->account_number : '••••'.$account->account_number_last4));
    }

    protected function noPayrollMessage(ReportContext $context): string
    {
        $open = $context->data->openRuns($context->company, $context->period)->count();

        return $open > 0
            ? "Payroll for {$context->period->label()} is not finalized yet ({$open} open run(s)): reports use finalized payroll only."
            : "No finalized payroll for {$context->period->label()}.";
    }
}

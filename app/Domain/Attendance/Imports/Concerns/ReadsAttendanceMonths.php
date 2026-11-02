<?php

namespace App\Domain\Attendance\Imports\Concerns;

use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Data import DI-35: the month and company columns of the month-level time-office uploads (override arrear days, override
 * paid days). A month is named like the attendance month screens name it (YYYY-MM, also MM-YYYY, MM/YYYY or "Aug 2026");
 * the company is given by code, or is the one the employee works for now.
 */
trait ReadsAttendanceMonths
{
    /** @return array{0: int, 1: int}|null year and month */
    protected function monthOf(?string $value): ?array
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{1,2})[-\/](\d{4})$/', $value, $m)) {
            [$year, $month] = [(int) $m[2], (int) $m[1]];
        } elseif (preg_match('/^([A-Za-z]{3,9})[\s\-]+(\d{4})$/', $value, $m) && ($d = date_create_immutable('1 '.$m[1].' '.$m[2])) !== false) {
            [$year, $month] = [(int) $d->format('Y'), (int) $d->format('n')];
        } else {
            return null;
        }

        return $month >= 1 && $month <= 12 && $year >= 2000 && $year <= 2100 ? [$year, $month] : null;
    }

    protected function monthLabel(int $year, int $month): string
    {
        return CarbonImmutable::create($year, $month, 1)->format('M Y');
    }

    /**
     * The company of the row: the code given, else the employee's company now. @param list<string> $errors
     *
     * @param  array<string, mixed>  $context
     */
    protected function companyOf(?string $code, ?int $employeeId, array &$context, array &$errors): ?int
    {
        if ($code !== null && trim($code) !== '') {
            $context['companies'] ??= Company::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
            $id = $context['companies'][mb_strtolower(trim($code))] ?? null;
            if ($id === null) {
                $errors[] = "Company code '{$code}' does not exist.";
            }

            return $id;
        }
        if ($employeeId === null) {
            return null;
        }
        $id = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->effectiveOn()
            ->orderByDesc('effective_from')->orderByDesc('id')->value('company_id');
        if ($id === null) {
            $errors[] = 'The employee has no company today; give the company code.';
        }

        return $id === null ? null : (int) $id;
    }
}

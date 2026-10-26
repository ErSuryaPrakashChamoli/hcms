<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Employee codes. A hire uses its company's series (EmployeeCodeSeries), else the tenant's default series; a tenant with
 * no series at all keeps the settings pattern (employee.code.prefix + padding, counted in employee_code_sequences,
 * seeded from the highest existing code the first time).
 * Concurrency-safe: the counter row is locked while a number is taken, and the unique index on employees guarantees
 * no duplicate even under contention. Numbers already held (typed by hand, imported) are skipped. Never depends on an
 * external system.
 * Phase 14: taken codes are checked across the whole tenant, not the caller's organisation scope.
 */
final class EmployeeCodeGenerator
{
    public function __construct(private readonly SettingsRepository $settings, private readonly TenantContext $tenants) {}

    public function next(?int $companyId = null): string
    {
        $series = $this->seriesFor($companyId);

        return $series === null ? $this->nextFromSettings() : $this->nextFromSeries($series);
    }

    /** What next() would issue now, without taking it: another hire may take it first. */
    public function preview(?int $companyId = null): string
    {
        $series = $this->seriesFor($companyId);
        if ($series !== null) {
            return $series->format($this->firstFree($series->format(...), max(1, $series->next_number)));
        }

        [$prefix, $format] = $this->settingsPattern();
        $last = DB::table('employee_code_sequences')->where('tenant_id', $this->tenantId())->where('prefix', $prefix)->value('last_number');

        return $format($this->firstFree($format, ($last === null ? $this->highestInPattern($prefix, '') : (int) $last) + 1));
    }

    /** The first code a series being set up would issue: as saving it would, it starts past the highest code already in its pattern. */
    public function firstCodeOf(EmployeeCodeSeries $series): string
    {
        return $series->format($this->firstFree($series->format(...), max(1, (int) $series->next_number, $this->highestInPattern($series->prefix, $series->suffix) + 1)));
    }

    /** The series a hire into this company uses: its own, else the tenant default; null when the tenant has neither. */
    public function seriesFor(?int $companyId): ?EmployeeCodeSeries
    {
        return ($companyId === null ? null : EmployeeCodeSeries::query()->where('company_id', $companyId)->first())
            ?? EmployeeCodeSeries::query()->whereNull('company_id')->first();
    }

    /** Whether a code may be typed by hand when hiring into this company: yes unless its series says otherwise. */
    public function allowsManual(?int $companyId): bool
    {
        return $this->seriesFor($companyId)?->allow_manual ?? true;
    }

    /** The employee holding this code anywhere in the tenant, whatever the caller's scope; compared without case, as MySQL's unique index does. */
    public function holder(string $code): ?Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereRaw('LOWER(employee_code) = ?', [mb_strtolower(trim($code))])->first();
    }

    public function isTaken(string $code): bool
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereRaw('LOWER(employee_code) = ?', [mb_strtolower(trim($code))])->exists();
    }

    /** The highest number already used between this prefix and suffix in the tenant (0 when none). */
    public function highestInPattern(string $prefix, string $suffix): int
    {
        $head = mb_strtolower($prefix);
        $tail = mb_strtolower($suffix);

        return (int) (Employee::query()->withoutGlobalScope(AccessScope::class)
            ->where('employee_code', 'like', $prefix.'%'.$suffix)
            ->pluck('employee_code')
            ->map(function (string $code) use ($head, $tail): int {
                $lower = mb_strtolower($code);
                $number = substr($code, strlen($head), strlen($code) - strlen($head) - strlen($tail));

                return str_starts_with($lower, $head) && str_ends_with($lower, $tail) && ctype_digit($number) && strlen($number) <= 18 ? (int) $number : 0;
            })
            ->max() ?? 0);
    }

    private function nextFromSeries(EmployeeCodeSeries $series): string
    {
        return DB::transaction(function () use ($series) {
            $locked = EmployeeCodeSeries::query()->whereKey($series->getKey())->lockForUpdate()->firstOrFail();
            $number = $this->firstFree($locked->format(...), max(1, $locked->next_number));
            // The counter moving is not a configuration change: no audit row per hire (the hire that takes the code is audited).
            DB::table('employee_code_series')->where('id', $locked->getKey())->update(['next_number' => $number + 1, 'updated_at' => now()]);

            return $locked->format($number);
        });
    }

    private function nextFromSettings(): string
    {
        [$prefix, $format] = $this->settingsPattern();
        $tenantId = $this->tenantId();

        return DB::transaction(function () use ($prefix, $format, $tenantId) {
            $row = DB::table('employee_code_sequences')->where('tenant_id', $tenantId)->where('prefix', $prefix)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('employee_code_sequences')->insert(['tenant_id' => $tenantId, 'prefix' => $prefix, 'last_number' => $this->highestInPattern($prefix, ''), 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('employee_code_sequences')->where('tenant_id', $tenantId)->where('prefix', $prefix)->lockForUpdate()->first();
            }

            $next = $this->firstFree($format, (int) $row->last_number + 1);
            DB::table('employee_code_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

            return $format($next);
        });
    }

    /** @return array{0: string, 1: Closure(int): string} */
    private function settingsPattern(): array
    {
        $prefix = (string) $this->settings->get('employee.code.prefix', 'EMP');
        $padding = (int) $this->settings->get('employee.code.padding', 5);

        return [$prefix, fn (int $number): string => $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT)];
    }

    /** @param  Closure(int): string  $format */
    private function firstFree(Closure $format, int $from): int
    {
        $number = $from;
        // Skip numbers already taken by manually assigned or imported codes.
        while ($this->isTaken($format($number))) {
            $number++;
        }

        return $number;
    }

    private function tenantId(): int
    {
        return $this->tenants->id() ?? throw new \RuntimeException('A tenant must be bound to generate an employee code.');
    }
}

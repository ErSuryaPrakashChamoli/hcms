<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;

/** Tenant-configurable employee codes: prefix + zero-padded sequence (settings employee.code.*). */
final class EmployeeCodeGenerator
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function next(): string
    {
        $prefix = (string) $this->settings->get('employee.code.prefix', 'EMP');
        $padding = (int) $this->settings->get('employee.code.padding', 5);

        return DB::transaction(function () use ($prefix, $padding) {
            $codes = Employee::query()
                ->where('employee_code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('employee_code');

            $max = $codes
                ->map(fn (string $code) => ctype_digit($suffix = substr($code, strlen($prefix))) ? (int) $suffix : 0)
                ->max() ?? 0;

            return $prefix.str_pad((string) ($max + 1), $padding, '0', STR_PAD_LEFT);
        });
    }
}

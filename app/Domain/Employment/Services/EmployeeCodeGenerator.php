<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-configurable employee codes: prefix + zero-padded sequence (settings employee.code.*).
 * Concurrency-safe: one locked counter row per tenant and prefix (employee_code_sequences), seeded
 * from the highest existing code the first time; the unique index on employees guarantees no
 * duplicate even under contention. Never depends on an external system.
 */
final class EmployeeCodeGenerator
{
    public function __construct(private readonly SettingsRepository $settings, private readonly TenantContext $tenants) {}

    public function next(): string
    {
        $prefix = (string) $this->settings->get('employee.code.prefix', 'EMP');
        $padding = (int) $this->settings->get('employee.code.padding', 5);
        $tenantId = $this->tenants->id() ?? throw new \RuntimeException('A tenant must be bound to generate an employee code.');

        return DB::transaction(function () use ($prefix, $padding, $tenantId) {
            $row = DB::table('employee_code_sequences')->where('tenant_id', $tenantId)->where('prefix', $prefix)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('employee_code_sequences')->insert(['tenant_id' => $tenantId, 'prefix' => $prefix, 'last_number' => $this->highestExisting($prefix), 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('employee_code_sequences')->where('tenant_id', $tenantId)->where('prefix', $prefix)->lockForUpdate()->first();
            }

            $next = (int) $row->last_number + 1;
            // Skip numbers already taken by manually assigned codes.
            while (Employee::query()->where('employee_code', $prefix.str_pad((string) $next, $padding, '0', STR_PAD_LEFT))->exists()) {
                $next++;
            }

            DB::table('employee_code_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

            return $prefix.str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
        });
    }

    private function highestExisting(string $prefix): int
    {
        return (int) (Employee::query()
            ->where('employee_code', 'like', $prefix.'%')
            ->pluck('employee_code')
            ->map(fn (string $code) => ctype_digit($suffix = substr($code, strlen($prefix))) ? (int) $suffix : 0)
            ->max() ?? 0);
    }
}

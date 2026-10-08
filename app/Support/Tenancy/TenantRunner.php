<?php

namespace App\Support\Tenancy;

use App\Domain\Platform\Models\Tenant;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 14: the shared per-tenant runner for scheduled commands.
 *
 * - Isolation: one tenant's failure is reported, logged (tenant id and exception class only) and counted;
 *   the run continues with the next tenant, and the command exits non-zero when any tenant failed.
 * - Suspended tenants are skipped. Their data is not processed, delivered or exported. Retention is the
 *   exception: it opts in, because purging is an obligation rather than a service.
 * - claim(): a once-per-period slot per tenant (scheduler_claims). A second run in the same period, or an
 *   overlapping run on another server, does nothing.
 */
final class TenantRunner
{
    private int $failed = 0;

    private int $ran = 0;

    private function __construct(private readonly Command $command) {}

    public static function for(Command $command): self
    {
        return new self($command);
    }

    /** Wrap a per-tenant callback (which receives the Tenant) with suspension skipping and failure isolation. */
    public function isolate(Closure $work, bool $includeSuspended = false): Closure
    {
        return function (Tenant $tenant) use ($work, $includeSuspended) {
            if (! $includeSuspended && ! $tenant->isAccessible()) {
                $this->command->line("{$tenant->slug}: skipped (tenant suspended)");

                return;
            }
            try {
                $work($tenant);
                $this->ran++;
            } catch (Throwable $e) {
                $this->failed++;
                report($e);
                Log::error('Scheduled tenant run failed', ['command' => $this->command->getName(), 'tenant_id' => $tenant->id, 'exception' => $e::class]);
                $this->command->error("{$tenant->slug}: failed (".class_basename($e).'); continuing with the next tenant');
            }
        };
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function exitCode(): int
    {
        return $this->failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** Claim the (key, period) slot for the bound tenant; false when it was already claimed. */
    public static function claim(string $key, string $period): bool
    {
        $tenantId = app(TenantContext::class)->id() ?? throw new \RuntimeException('A scheduler claim needs a bound tenant.');

        return DB::table('scheduler_claims')->insertOrIgnore([
            'tenant_id' => $tenantId, 'key' => $key, 'period' => $period, 'claimed_at' => now(),
        ]) === 1;
    }
}

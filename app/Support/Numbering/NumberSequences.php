<?php

namespace App\Support\Numbering;

use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 12: gap-tolerant, collision-free document numbers (PREFIX-YYYY-NNNNN). The read of "last
 * number + 1" happens under a row lock on the tenant's sequence row, so two concurrent submissions can
 * never draw the same number.
 *
 * A sequence row starts at the highest number already issued (`$seed`), so moving an existing
 * numbering onto a sequence never reissues a number. Call ensure() before opening the transaction
 * that calls next(): the row is created in its own short statement. Two transactions that both find
 * the row missing and both insert it can deadlock on the gap lock.
 */
final class NumberSequences
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @param  Closure(string): int  $seed  highest number already issued for "PREFIX-YYYY" */
    public function ensure(string $prefix, Closure $seed): void
    {
        $sequence = $this->sequence($prefix);
        $tenantId = $this->tenants->id() ?? throw new RuntimeException('Numbers are issued inside a tenant.');
        if (NumberSequence::query()->where('sequence', $sequence)->exists()) {
            return;
        }
        NumberSequence::query()->insertOrIgnore(['tenant_id' => $tenantId, 'sequence' => $sequence, 'last_value' => max(0, $seed($sequence)), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The next number, under a lock on the sequence row (run inside the caller's transaction). */
    public function next(string $prefix, Closure $seed): string
    {
        $sequence = $this->sequence($prefix);

        return DB::transaction(function () use ($prefix, $sequence, $seed) {
            $row = NumberSequence::query()->where('sequence', $sequence)->lockForUpdate()->first();
            if ($row === null) {
                // Not ensured (e.g. the first number of a new year inside a transaction): create it now.
                $this->ensure($prefix, $seed);
                $row = NumberSequence::query()->where('sequence', $sequence)->lockForUpdate()->firstOrFail();
            }
            $next = $row->last_value + 1;
            $row->update(['last_value' => $next]);

            return sprintf('%s-%05d', $sequence, $next);
        });
    }

    /** Highest issued sequence number among existing "PREFIX-YYYY-NNNNN" values of a column. */
    public static function highest(string $modelClass, string $column = 'number'): Closure
    {
        return fn (string $sequence): int => (int) substr((string) $modelClass::query()->withoutGlobalScope(AccessScope::class)->where('tenant_id', app(TenantContext::class)->id())
            ->where($column, 'like', $sequence.'-%')->orderByDesc($column)->value($column), -5);
    }

    private function sequence(string $prefix): string
    {
        return strtoupper($prefix).'-'.now()->format('Y');
    }
}

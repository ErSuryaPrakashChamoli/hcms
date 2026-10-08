<?php

namespace App\Domain\Talent\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Talent\Events\TalentEvent;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 talent pool membership: explicit (a person adds with a reason), effective-dated, audited,
 * permission-controlled (talent.manage within organisation scope), never automatic. One active
 * membership per pool and employee — enforced by a unique key, so concurrent adds cannot duplicate.
 */
final class TalentPools
{
    public function __construct(private readonly TalentAccess $access, private readonly AuditRecorder $audit) {}

    public function add(TalentPool $pool, Employee $employee, string $reason, User $actor, ?string $effectiveFrom = null): TalentPoolMembership
    {
        if (! $this->access->mayManageTalent($actor, $employee->id)) {
            throw new RuntimeException('Pool membership is managed with talent.manage, within your organisation scope, and never for yourself.');
        }
        if ($pool->status !== 'active') {
            throw new RuntimeException('This talent pool is not active.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Adding someone to a talent pool needs a reason.');
        }

        try {
            return DB::transaction(function () use ($pool, $employee, $reason, $actor, $effectiveFrom) {
                TalentPool::query()->whereKey($pool->id)->lockForUpdate()->first();
                $membership = TalentPoolMembership::query()->create([
                    'talent_pool_id' => $pool->id, 'employee_id' => $employee->id, 'effective_from' => $effectiveFrom ?? now()->toDateString(),
                    'reason' => $reason, 'added_by' => $actor->id,
                ]);
                TalentEvent::dispatch('talent.pool.membership_changed', $employee, $membership, ['pool' => $pool->name, 'change' => 'added'], [], array_filter([$pool->owner_user_id]));

                return $membership;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('This employee is already an active member of the pool.');
        }
    }

    public function end(TalentPoolMembership $membership, string $reason, User $actor): TalentPoolMembership
    {
        if (! $this->access->mayManageTalent($actor, $membership->employee_id)) {
            throw new RuntimeException('Pool membership is managed with talent.manage within your organisation scope.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Ending a membership needs a reason.');
        }

        return DB::transaction(function () use ($membership, $reason, $actor) {
            $current = TalentPoolMembership::query()->withoutGlobalScope(AccessScope::class)->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active') {
                throw new RuntimeException('This membership has already ended.');
            }
            $membership->setRawAttributes($current->getAttributes(), true);
            $membership->update(['status' => 'ended', 'active_key' => null, 'effective_to' => now()->toDateString(), 'ended_by' => $actor->id, 'end_reason' => $reason]);
            TalentEvent::dispatch('talent.pool.membership_changed', $membership->employee()->withoutGlobalScope(AccessScope::class)->first(), $membership, ['change' => 'ended']);

            return $membership;
        });
    }

    /**
     * Add several employees as one audited bulk operation (operation id on every audit event).
     *
     * @param  list<int>  $employeeIds
     * @return array{added: int, skipped: list<string>}
     */
    public function addMany(TalentPool $pool, array $employeeIds, string $reason, User $actor): array
    {
        $result = ['added' => 0, 'skipped' => []];
        $this->audit->operation('talent', 'Talent pool membership', function () use ($pool, $employeeIds, $reason, $actor, &$result) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $employeeIds)->orderBy('id')->chunkById(200, function ($employees) use ($pool, $reason, $actor, &$result) {
                foreach ($employees as $employee) {
                    try {
                        $this->add($pool, $employee, $reason, $actor);
                        $result['added']++;
                    } catch (RuntimeException $e) {
                        $result['skipped'][] = $employee->employee_code.': '.$e->getMessage();
                    }
                }
            });

            return ['succeeded' => $result['added'], 'failed' => count($result['skipped'])];
        }, $reason, TalentPoolMembership::class);

        return $result;
    }
}

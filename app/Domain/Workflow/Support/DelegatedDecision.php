<?php

namespace App\Domain\Workflow\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Models\TaskDelegation;
use Illuminate\Support\Facades\Context;

/**
 * HROne setup parity P4 (out of office): marks the work done while a delegate decides in a delegator's stead. The
 * decision runs with the delegator's authority; inside run(), AuditRecorder records the delegate as the actor (in place
 * of the delegator) with `on_behalf_of` in the metadata, and the workflow engine stores the delegate on the task and in
 * its log. Carried in Laravel Context like the platform access id, so it reaches everything the decision causes.
 */
final class DelegatedDecision
{
    public const KEY = 'audit.delegation';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(TaskDelegation $delegation, User $delegate, User $delegator, callable $callback): mixed
    {
        $previous = Context::get(self::KEY);
        Context::add(self::KEY, [
            'delegation_id' => (int) $delegation->getKey(),
            'delegate_id' => (int) $delegate->getKey(), 'delegate_name' => $delegate->name,
            'delegator_id' => (int) $delegator->getKey(), 'delegator_name' => $delegator->name,
        ]);
        try {
            return $callback();
        } finally {
            $previous === null ? Context::forget(self::KEY) : Context::add(self::KEY, $previous);
        }
    }

    /** @return array{delegation_id: int, delegate_id: int, delegate_name: string, delegator_id: int, delegator_name: string}|null */
    public static function current(): ?array
    {
        $current = Context::get(self::KEY);

        return is_array($current) ? $current : null;
    }
}

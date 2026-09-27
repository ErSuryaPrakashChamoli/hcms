<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Leave\Services\LeaveEntitlements;

/** Publish a leave policy for everyone with the given per-type entitlements (defaults applied). */
function leavePolicy(array $entitlements, array $extra = [], string $code = 'STD_LEAVE'): Policy
{
    $rows = [];

    foreach ($entitlements as $typeCode => $settings) {
        $rows[] = ['leave_type_code' => $typeCode] + $settings + LeaveEntitlements::DEFAULTS;
    }

    $policy = Policy::create(['type' => 'leave', 'name' => 'Standard leave', 'code' => $code]);
    app(Policies::class)->draft($policy, ['count_weekly_offs' => false, 'count_holidays' => false, 'entitlements' => $rows] + $extra);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $policy->id, 'name' => 'Everyone', 'priority' => 100, 'conditions' => []]);

    return $policy;
}

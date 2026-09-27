<?php

namespace App\Domain\Leave\Services;

use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use Carbon\CarbonInterface;

/** Reads the resolved leave policy (Phase 4 policy engine, type "leave") for an employee. */
final class LeaveEntitlements
{
    public const DEFAULTS = [
        'days' => 0, 'accrual_frequency' => 'annual', 'prorate_on_join' => true, 'carry_forward_limit' => 0, 'carry_forward_expiry_months' => 0,
        'encashment_allowed' => false, 'max_encash_days' => 0, 'probation_eligible' => true, 'negative_balance_limit' => 0,
        'half_day_allowed' => true, 'min_notice_days' => 0, 'max_consecutive_days' => 0, 'document_required_after_days' => 0,
        'proration' => 'monthly', 'eligible_after' => 'immediate', 'eligible_after_value' => 0,
    ];

    public function __construct(private readonly PolicyResolver $policies) {}

    public function policyFor(Employee $employee, CarbonInterface|string|null $on = null): ?PolicyVersion
    {
        return $this->policies->resolve('leave', $employee, $on);
    }

    /** @return array<string, array<string, mixed>> leave type code => entitlement settings */
    public function for(Employee $employee, CarbonInterface|string|null $on = null): array
    {
        $entitlements = [];

        foreach ($this->policyFor($employee, $on)?->setting('entitlements', []) ?? [] as $row) {
            if (! empty($row['leave_type_code'])) {
                $entitlements[$row['leave_type_code']] = array_replace(self::DEFAULTS, array_filter($row, fn ($v) => $v !== null && $v !== ''));
            }
        }

        return $entitlements;
    }

    /** @return array<string, mixed>|null */
    public function forType(Employee $employee, LeaveType $type, CarbonInterface|string|null $on = null): ?array
    {
        return $this->for($employee, $on)[$type->code] ?? null;
    }

    public function countsWeeklyOffs(Employee $employee, CarbonInterface|string|null $on = null): bool
    {
        return (bool) ($this->policyFor($employee, $on)?->setting('count_weekly_offs', false) ?? false);
    }

    public function countsHolidays(Employee $employee, CarbonInterface|string|null $on = null): bool
    {
        return (bool) ($this->policyFor($employee, $on)?->setting('count_holidays', false) ?? false);
    }
}

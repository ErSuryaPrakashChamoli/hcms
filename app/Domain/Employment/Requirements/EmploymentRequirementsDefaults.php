<?php

namespace App\Domain\Employment\Requirements;

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicySetup;
use App\Domain\Organisation\Enums\ActiveStatus;

/**
 * Master relationships R2, OD-4: a new tenant starts with an "Employment data requirements" policy for everyone,
 * requiring Location, Department, Designation and Employment type (Company always is) on Warn, with the consistency
 * checks on Warn. Seeded at provisioning only (peopleos.employment_requirements.seed_new_tenants); existing tenants
 * are never given one, so they keep requiring the company only until they configure more.
 */
final class EmploymentRequirementsDefaults
{
    public const CODE = 'EMPLOYMENT_DATA';

    public function __construct(private readonly Policies $policies) {}

    public function seed(): ?Policy
    {
        if (! config('peopleos.employment_requirements.seed_new_tenants', true)
            || Policy::query()->where('type', EmploymentRequirements::TYPE)->exists()) {
            return null;
        }

        $policy = Policy::query()->create([
            'type' => EmploymentRequirements::TYPE,
            'name' => 'Employment data requirements',
            'code' => self::CODE,
            'description' => 'What a hire or a position change must name. Warn shows what is missing; Block is switched on after a pre-flight check.',
            'status' => ActiveStatus::Active,
        ]);
        $settings = self::defaultSettings();
        $this->policies->draft($policy, $settings, 'Default employment data requirements for a new organisation');
        $this->policies->publish($policy, today(), 'Default employment data requirements for a new organisation');
        PolicyAssignmentRule::query()->create(['policy_id' => $policy->id, 'policy_type' => EmploymentRequirements::TYPE, 'name' => PolicySetup::APPLICABILITY,
            'priority' => 1000, 'match' => 'all', 'conditions' => [], 'status' => ActiveStatus::Active]);

        return $policy;
    }

    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        $settings = ['required_on_hire' => EmploymentRequirements::NEW_TENANT_REQUIRED, 'required_on_change' => EmploymentRequirements::NEW_TENANT_REQUIRED,
            'required_mode' => EmploymentRequirements::WARN];
        foreach (array_keys(EmploymentRequirements::CHECKS) as $check) {
            $settings["check_{$check}"] = EmploymentRequirements::WARN;
        }

        return $settings;
    }
}

<?php

namespace App\Domain\Talent\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 9: the one place that answers who may see career, talent and succession information about
 * an employee. Chain: tenant → permission → organisation scope (AccessScopes) → relationship scope
 * (PerformanceRelationships, configured types only) → field (sharing flags / confidential).
 *
 * - Career: the employee always; managers only what the employee shares; career.view within scope.
 * - Talent (pools, profiles, assessments, review outcomes): talent.view within scope — never the
 *   employee themself, never a manager by relationship alone.
 * - Succession (candidacy, readiness): succession.view within scope; succession.team for employees
 *   one manages; the employee only with succession.own_candidacy.
 * - Confidential notes / assessments: talent.confidential, and every read is audited.
 */
final class TalentAccess
{
    public function __construct(private readonly PerformanceRelationships $relationships, private readonly AccessScopes $scopes, private readonly AuditRecorder $audit) {}

    public function self(User $user, int|string|null $employeeId): bool
    {
        $me = $this->relationships->forUser($user);

        return $me !== null && $employeeId !== null && (int) $me->id === (int) $employeeId;
    }

    public function manages(User $user, int|string|null $employeeId): bool
    {
        return $this->relationships->manages($this->relationships->forUser($user), $employeeId);
    }

    public function inScope(User $user, int|string|null $employeeId): bool
    {
        return $employeeId !== null && $this->scopes->allowsEmployeeId($user, (int) $employeeId);
    }

    /** @param  'aspirations'|'goals'|'mobility'|'profile'  $field */
    public function mayViewCareer(User $user, int|string|null $employeeId, string $field = 'profile'): bool
    {
        if ($this->self($user, $employeeId)) {
            return true;
        }
        if ($user->hasPermission('career.view') && $this->inScope($user, $employeeId)) {
            return true;
        }
        if (! $user->hasPermission('career.team') || ! $this->manages($user, $employeeId)) {
            return false;
        }
        $profile = CareerProfile::query()->withoutGlobalScopes([AccessScope::class])->where('employee_id', $employeeId)->first();

        return match ($field) {
            'aspirations' => (bool) $profile?->share_aspirations_with_manager,
            'mobility' => (bool) $profile?->share_mobility_with_manager,
            'goals' => $profile === null || $profile->share_goals_with_manager,
            default => true,
        };
    }

    public function mayEditCareer(User $user, int|string|null $employeeId): bool
    {
        return ($this->self($user, $employeeId) && $user->hasPermission('career.self'))
            || ($user->hasPermission('career.manage') && $this->inScope($user, $employeeId));
    }

    public function mayViewTalent(User $user, int|string|null $employeeId): bool
    {
        return ! $this->self($user, $employeeId) && $user->hasPermission('talent.view') && $this->inScope($user, $employeeId);
    }

    public function mayManageTalent(User $user, int|string|null $employeeId): bool
    {
        return ! $this->self($user, $employeeId) && $user->hasPermission('talent.manage') && $this->inScope($user, $employeeId);
    }

    public function mayViewSuccession(User $user, int|string|null $employeeId): bool
    {
        if ($this->self($user, $employeeId)) {
            return $user->hasPermission('succession.own_candidacy');
        }

        return ($user->hasPermission('succession.view') && $this->inScope($user, $employeeId))
            || ($user->hasPermission('succession.team') && $this->manages($user, $employeeId));
    }

    public function mayManageSuccession(User $user, int|string|null $employeeId): bool
    {
        return ! $this->self($user, $employeeId) && $user->hasPermission('succession.manage') && $this->inScope($user, $employeeId);
    }

    /** Read a confidential attribute: talent.confidential, never about oneself, always audited. */
    public function confidential(Model $record, string $attribute, User $viewer): ?string
    {
        // Position-level records (succession plans) carry no employee; person-level ones do.
        $employeeId = array_key_exists('employee_id', $record->getAttributes()) ? $record->getAttribute('employee_id') : null;
        if (! $viewer->hasPermission('talent.confidential') || $this->self($viewer, $employeeId) || ($employeeId !== null && ! $this->inScope($viewer, $employeeId))) {
            return null;
        }
        $value = $record->getAttribute($attribute);
        if (filled($value)) {
            $this->audit->record(AuditAction::View, 'talent', $record, [], null, actor: $viewer, metadata: ['field' => $attribute, 'event' => 'confidential_talent_access']);
        }

        return $value;
    }

    public function employeeFor(User $user): ?Employee
    {
        return $this->relationships->forUser($user);
    }
}

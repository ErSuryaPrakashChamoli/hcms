<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Collection;

/**
 * Phase 11 field security for compensation, on the established chain: tenant → role → permission →
 * organisation scope → relationship scope → field → record.
 *
 *  - full  (compensation.view, within organisation scope): current, history, scheduled, components,
 *          changes and their reasons;
 *  - team  (compensation.team + a configured manager relationship, PerformanceRelationships): the
 *          approved compensation of people the user manages — no proposals, reasons or notes.
 *          Mentors, buddies and project leads grant nothing unless configured as manager types;
 *  - self  (compensation.self + tenant setting compensation.self_service): one's own approved
 *          compensation — no proposals, reasons, internal notes or anyone else's pay.
 */
final class CompensationAccess
{
    /** @var array<string, Collection<int, int>> */
    private array $teams = [];

    public function __construct(private readonly AccessScopes $scopes, private readonly PerformanceRelationships $relationships, private readonly SettingsRepository $settings) {}

    /** 'full' | 'team' | 'self' | null */
    public function level(?User $user, Employee $employee): ?string
    {
        if ($user === null) {
            return null;
        }
        if ($user->hasPermission('compensation.view') && $this->scopes->allows($user, $employee)) {
            return 'full';
        }
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $user->id) {
            return $user->hasPermission('compensation.self') && (bool) $this->settings->get('compensation.self_service', true) ? 'self' : null;
        }
        if ($user->hasPermission('compensation.team') && $this->teamOf($user)->contains((int) $employee->id) && $this->scopes->allows($user, $employee)) {
            return 'team';
        }

        return null;
    }

    public function mayViewCompensation(?User $user, Employee $employee): bool
    {
        return $this->level($user, $employee) !== null;
    }

    /** Proposals, reasons and decisions: full readers, and the people acting on that change. */
    public function mayViewChange(?User $user, CompensationChange $change): bool
    {
        if ($user === null) {
            return false;
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($change->employee_id);
        if ($employee === null || ! $this->scopes->allows($user, $employee) || ((int) $employee->user_id === (int) $user->id)) {
            return false;
        }
        if ($user->hasPermission('compensation.view') || in_array((int) $user->id, array_map('intval', $change->actors()), true)) {
            return true;
        }

        return match ($change->status) {
            'draft' => false,
            'submitted' => $user->hasPermission('compensation.review'),
            'under_review' => $user->hasPermission('compensation.approve'),
            'approved' => $user->hasPermission('compensation.execute'),
            default => false,
        };
    }

    /** @return Collection<int, int> employee ids the user manages through configured relationship types */
    public function teamOf(User $user): Collection
    {
        return $this->teams[$user->id] ??= $user->hasPermission('compensation.team')
            ? $this->relationships->reportIds($this->relationships->forUser($user))->map(fn ($id) => (int) $id)
            : collect();
    }
}

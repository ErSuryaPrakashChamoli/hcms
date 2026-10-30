<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\AccessScopeGroup;
use App\Domain\Identity\Models\AccessScopeGroupMember;
use App\Domain\Identity\Models\AccessScopeGroupRule;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity CA-9 (Access rights → Data access): data-access groups are written only here. `user.assign_roles` (the
 * right that edits a user's own organisation scope), a reason for every change, every row audited, only values the
 * person saving can see themselves, members of this organisation only, and the access-scope answers forgotten at once.
 *
 * A group only ever narrows its members' access (AccessScopes). Removing a member, switching a group off or deleting it
 * gives the members back what their own scope allows — an explicit, audited decision of the person doing it.
 */
final class AccessScopeGroups
{
    /** Organisation dimension => the model whose (viewer-scoped) rows a rule may name. */
    public const UNITS = [
        'company' => Company::class, 'location' => Location::class, 'business_unit' => BusinessUnit::class, 'division' => Division::class,
        'department' => Department::class, 'team' => Team::class, 'establishment' => Establishment::class,
        'designation' => Designation::class, 'grade' => Grade::class, 'level' => Level::class,
    ];

    public const LABELS = [
        'company' => 'Companies', 'location' => 'Locations (branches)', 'business_unit' => 'Business units', 'division' => 'Divisions',
        'department' => 'Departments', 'team' => 'Teams (sub-branches)', 'establishment' => 'Establishments', 'designation' => 'Designations',
        'grade' => 'Grades', 'level' => 'Levels', 'employee' => 'Employees',
    ];

    public function __construct(private readonly TenantContext $tenants, private readonly AccessScopes $scopes) {}

    /**
     * Create ($group null) or change a group: its details, the values it reaches and its members.
     *
     * @param  array{name: string, module: string, basis?: string, reportees?: string, include_sub_departments?: bool, status?: string, description?: ?string, rules?: array<string, list<int|string>>, members?: list<int|string>}  $data
     */
    public function save(?AccessScopeGroup $group, array $data, ?User $actor, string $reason): AccessScopeGroup
    {
        $this->assertMay($actor);
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }
        $tenantId = $this->tenants->id() ?? throw new RuntimeException('No organisation is selected.');
        if ($group !== null && (int) $group->tenant_id !== $tenantId) {
            throw new RuntimeException('This group belongs to another organisation.');
        }

        $attributes = $this->attributes($data);
        $rules = $this->rules($attributes['basis'], (array) ($data['rules'] ?? []));
        $members = $this->members((array) ($data['members'] ?? []));

        $saved = DB::transaction(function () use ($group, $attributes, $rules, $members, $reason): AccessScopeGroup {
            $group ??= new AccessScopeGroup;
            $group->fill($attributes)->withAuditReason($reason)->save();

            $existing = $group->rules()->get();
            foreach ($existing as $row) {
                if (! in_array((int) $row->scope_id, $rules[$row->dimension] ?? [], true)) {
                    $row->withAuditReason($reason)->delete();
                }
            }
            foreach ($rules as $dimension => $ids) {
                foreach ($ids as $id) {
                    if (! $existing->contains(fn (AccessScopeGroupRule $r) => $r->dimension === $dimension && (int) $r->scope_id === $id)) {
                        (new AccessScopeGroupRule(['access_scope_group_id' => $group->getKey(), 'dimension' => $dimension, 'scope_id' => $id]))->withAuditReason($reason)->save();
                    }
                }
            }

            $current = $group->members()->get();
            foreach ($current as $row) {
                if (! in_array((int) $row->user_id, $members, true)) {
                    $row->withAuditReason($reason)->delete();
                }
            }
            foreach (array_diff($members, $current->pluck('user_id')->map(fn ($id) => (int) $id)->all()) as $userId) {
                (new AccessScopeGroupMember(['access_scope_group_id' => $group->getKey(), 'user_id' => $userId]))->withAuditReason($reason)->save();
            }

            return $group;
        });
        $this->scopes->forget();

        return $saved;
    }

    public function delete(AccessScopeGroup $group, ?User $actor, string $reason): void
    {
        $this->assertMay($actor);
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }
        DB::transaction(function () use ($group, $reason): void {
            foreach ($group->members()->get() as $row) {
                $row->withAuditReason($reason)->delete();
            }
            foreach ($group->rules()->get() as $row) {
                $row->withAuditReason($reason)->delete();
            }
            $group->withAuditReason($reason)->delete();
        });
        $this->scopes->forget();
    }

    /** @return array<string, list<int>> the group's rules, dimension => ids (the form's state) */
    public function rulesOf(AccessScopeGroup $group): array
    {
        return $group->rules()->get()->groupBy('dimension')->map(fn (Collection $rows) => $rows->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all())->all();
    }

    /** @return list<int> */
    public function membersOf(AccessScopeGroup $group): array
    {
        return $group->members()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return Collection<int, AccessScopeGroup> the groups $user is in (this organisation), by module and name */
    public function groupsOf(User $user): Collection
    {
        return AccessScopeGroup::query()->whereIn('id', AccessScopeGroupMember::query()->select('access_scope_group_id')->where('user_id', $user->getKey()))
            ->orderBy('module')->orderBy('name')->get();
    }

    /**
     * Why a stored group reaches nobody (empty when well formed or inactive): shown on the group, because a member of a
     * malformed group sees nobody at all.
     *
     * @return list<string>
     */
    public function problems(AccessScopeGroup $group): array
    {
        if (($group->getAttributes()['status'] ?? null) === 'inactive') {
            return [];
        }

        return $this->scopes->definition($group, $group->rules()->get(), (int) $group->tenant_id) === null
            ? ['This group is not valid (unknown module, setting or rule): its members see nobody at all until it is corrected or switched off.']
            : [];
    }

    /** @return array<int, string> employees as "code · name" (within the viewer's scope) */
    public static function employeeOptions(?array $ids = null, ?string $search = null): array
    {
        $term = '%'.trim((string) $search).'%';

        return Employee::query()->with('person')
            ->when($ids !== null, fn (Builder $q) => $q->whereKey($ids))
            ->when(filled($search), fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('employee_code', 'like', $term)
                ->orWhereHas('person', fn (Builder $p) => $p->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term))))
            ->orderBy('employee_code')->limit($ids === null ? 50 : max(1, count($ids)))->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->employee_code.' · '.$e->person?->full_name])->all();
    }

    /** @return array<int, string> units of a dimension the viewer can see, by id */
    public static function unitOptions(string $dimension): array
    {
        $class = self::UNITS[$dimension] ?? null;

        return $class === null ? [] : $class::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> users of this organisation a group may hold */
    public static function userOptions(): array
    {
        return User::forCurrentTenant()->where('is_platform_admin', false)->whereIn('status', [UserStatus::Active->value, UserStatus::Invited->value])
            ->orderBy('name')->get(['id', 'name', 'email'])->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->email})"])->all();
    }

    private function assertMay(?User $actor): void
    {
        if ($actor !== null && ! $actor->hasPermission('user.assign_roles')) {
            throw new RuntimeException('You cannot change data access.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $module = (string) ($data['module'] ?? '');
        $basis = (string) ($data['basis'] ?? 'organisation');
        $reportees = (string) ($data['reportees'] ?? 'none');
        $status = (string) ($data['status'] ?? 'active');

        return match (true) {
            $name === '' || mb_strlen($name) > 120 => throw new RuntimeException('Give the group a name (at most 120 characters).'),
            ! array_key_exists($module, AccessScopes::modules()) => throw new RuntimeException('Choose the module the group is for.'),
            ! array_key_exists($basis, AccessScopeGroup::BASES) => throw new RuntimeException('Choose how the group reaches people.'),
            ! array_key_exists($reportees, AccessScopeGroup::REPORTEES) => throw new RuntimeException('Choose which reportees the members also see.'),
            ! array_key_exists($status, AccessScopeGroup::STATUSES) => throw new RuntimeException('Choose the group\'s status.'),
            default => ['name' => $name, 'module' => $module, 'basis' => $basis, 'reportees' => $reportees, 'status' => $status,
                'include_sub_departments' => (bool) ($data['include_sub_departments'] ?? false), 'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null],
        };
    }

    /**
     * The rules as dimension => ids, every id one the person saving can see (fail closed: anything else is refused).
     *
     * @param  array<string, mixed>  $given
     * @return array<string, list<int>>
     */
    private function rules(string $basis, array $given): array
    {
        $rules = [];
        foreach ($given as $dimension => $ids) {
            $ids = array_values(array_unique(array_map('intval', array_filter((array) $ids, fn ($id) => $id !== null && $id !== ''))));
            if ($ids === []) {
                continue;
            }
            if (! in_array($dimension, AccessScopeGroupRule::DIMENSIONS, true)) {
                throw new RuntimeException("Unknown data access dimension [{$dimension}].");
            }
            if (($basis === 'employees') !== ($dimension === 'employee')) {
                throw new RuntimeException($basis === 'employees' ? 'A group of specific employees names employees only.' : 'Choose "Specific employees" to name employees.');
            }
            $query = $dimension === 'employee' ? Employee::query() : (self::UNITS[$dimension])::query();
            if ($query->whereKey($ids)->count() !== count($ids)) {
                throw new RuntimeException('Choose '.mb_strtolower(self::LABELS[$dimension]).' you can see.');
            }
            $rules[$dimension] = $ids;
        }

        return $rules;
    }

    /**
     * @param  list<int|string>  $given
     * @return list<int>
     */
    private function members(array $given): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($given, fn ($id) => $id !== null && $id !== ''))));
        if ($ids !== [] && User::forCurrentTenant()->where('is_platform_admin', false)->whereKey($ids)->count() !== count($ids)) {
            throw new RuntimeException('Members must be users of this organisation.');
        }

        return $ids;
    }
}

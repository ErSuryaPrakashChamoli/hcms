<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\AccessScopeGroup;
use App\Domain\Identity\Models\AccessScopeGroupMember;
use App\Domain\Identity\Models\AccessScopeGroupRule;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Organisational access scoping (Phase 0.2 ABAC). Resolves a user's scope rows into query
 * constraints that are applied at the data-access layer by {@see AccessScope}, and answers
 * record-level questions for policies.
 *
 * Semantics: no rows = tenant-wide (subject to permissions). With rows, an employee is visible
 * when the user is that employee, when the employee reports to the user today (any reporting
 * type), or when the employee's position effective today matches every scoped dimension (any of
 * the listed values per dimension).
 *
 * HROne parity CA-9 (Data access groups) layers module data-access groups on top, and they only ever narrow:
 * - A user in active groups of a module sees, in that module, only themselves and the people their groups reach (several
 *   groups of a module add up), AND everything the rows above already limit. No group = exactly the behaviour above.
 * - A Workforce group narrows everything about people (the employee master and every employee-linked record, of every
 *   module); a group of another module narrows only the employee-linked records of that module's domains
 *   (peopleos.access.modules), on top of the Workforce groups. Organisation units are not affected by groups.
 * - Fail closed: a membership or group that is malformed (unknown module, basis, reportees or status, a rule of an
 *   unknown dimension or a bad id, another tenant's group) closes every layer: the member sees nobody at all. An unknown
 *   dimension in the user's own scope rows likewise reaches nobody (it used to fail with an SQL error).
 * - SV-7 (AR-3, AR-4): designation, grade and level are scope dimensions; a group may reach the member's reportees
 *   (direct or every level) and a department's sub-departments.
 * - CA-10 (CXO access): while a shared view renders (withinSlice()), its applicability is one more layer for everyone —
 *   only the employees whose position today matches every dimension of the slice (no self, no reportees), AND-ed with
 *   everything above. It narrows every employee-linked query of every module and never widens one. A malformed slice
 *   reaches nobody; without a signed-in tenant user nothing is shown while a slice is active (AccessScope).
 */
final class AccessScopes
{
    /** @var array<int, array<string, list<int>>|null> */
    private array $cache = [];

    /** @var array<int, array{modules: array<string, list<array<string, mixed>>>, closed: bool}> data-access groups per user */
    private array $groups = [];

    /** @var array<string, list<int>> id lists worked out for this request (all-level reportees, sub-departments) */
    private array $computed = [];

    /** @var array<string, string|null> model class => data-access module */
    private array $moduleOf = [];

    /** @var list<array{closed: bool, dimensions: array<string, list<int>>}> CA-10: the slices of the shared views rendering now */
    private array $slices = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array<string, list<int>>|null null = tenant-wide */
    public function for(User $user): ?array
    {
        if ($user->isPlatformAdmin()) {
            return null;
        }

        if (array_key_exists($user->getKey(), $this->cache)) {
            return $this->cache[$user->getKey()];
        }

        $rows = $this->tenants->bypass(fn () => UserAccessScope::query()
            ->where('user_id', $user->getKey())
            ->get(['dimension', 'scope_id']));

        $scope = $rows->isEmpty()
            ? null
            : $rows->groupBy('dimension')->map(fn ($group) => $group->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all())->all();

        return $this->cache[$user->getKey()] = $scope;
    }

    /**
     * Whether anything limits the employees $user reaches: their own scope rows, or (CA-9) data-access groups applying to
     * $module (Workforce groups always apply; null = no module).
     */
    public function isScoped(User $user, ?string $module = null): bool
    {
        return $this->for($user) !== null || $this->layers($user, $module) !== [];
    }

    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
            $this->groups = [];
            $this->computed = [];
        } else {
            unset($this->cache[$userId], $this->groups[$userId]);
            $this->computed = array_filter($this->computed, fn (string $key) => ! str_starts_with($key, $userId.':'), ARRAY_FILTER_USE_KEY);
        }
    }

    /**
     * CA-10: run $callback with a shared view's organisation slice ({dimension: [ids]}) narrowing every scoped query; null
     * = malformed (nothing is reachable); [] = no slice. Slices nest (each narrows); the slice ends with the callback.
     *
     * @template T
     *
     * @param  array<string, list<int>>|null  $slice
     * @param  callable(): T  $callback
     * @return T
     */
    public function withinSlice(?array $slice, callable $callback): mixed
    {
        if ($slice === []) {
            return $callback();
        }
        $closed = $slice === null;
        foreach ($slice ?? [] as $dimension => $ids) {
            if (! in_array($dimension, UserAccessScope::DIMENSIONS, true) || ! is_array($ids) || $ids === []) {
                $closed = true;
            }
        }
        $this->slices[] = ['closed' => $closed, 'dimensions' => $closed ? [] : array_map(fn ($ids) => array_values(array_map('intval', $ids)), $slice)];
        try {
            return $callback();
        } finally {
            array_pop($this->slices);
        }
    }

    /** CA-10: whether a shared view's slice narrows queries right now. */
    public function slicing(): bool
    {
        return $this->slices !== [];
    }

    /** @return array<string, string> the data-access modules (peopleos.access.modules), key => label */
    public static function modules(): array
    {
        return array_map(fn ($module) => (string) ($module['label'] ?? ''), (array) config('peopleos.access.modules', []));
    }

    /** CA-9: the data-access module of a model, by its domain (App\Domain\{Domain}\…); null when no module lists it. */
    public function moduleFor(Model|string $model): ?string
    {
        $class = is_string($model) ? $model : $model::class;
        if (array_key_exists($class, $this->moduleOf)) {
            return $this->moduleOf[$class];
        }
        $parts = explode('\\', $class);
        $domain = count($parts) > 3 && $parts[0] === 'App' && $parts[1] === 'Domain' ? $parts[2] : null;
        $found = null;
        foreach ((array) config('peopleos.access.modules', []) as $key => $module) {
            if ($domain !== null && in_array($domain, (array) ($module['domains'] ?? []), true)) {
                $found = (string) $key;
                break;
            }
        }

        return $this->moduleOf[$class] = $found;
    }

    /**
     * CA-9: the data-access group layers that limit $user for $module, each AND-ed with the others: the Workforce groups
     * (always), then the module's own groups. A malformed membership closes everything (one closed layer).
     *
     * @return list<array{closed: bool, groups: list<array<string, mixed>>}>
     */
    public function layers(User $user, ?string $module = null): array
    {
        $state = $this->groupState($user);
        if ($state['closed']) {
            return [['closed' => true, 'groups' => []]];
        }
        $layers = [];
        foreach (array_unique(array_filter(['workforce', $module])) as $key) {
            if (($state['modules'][$key] ?? []) !== []) {
                $layers[] = ['closed' => false, 'groups' => $state['modules'][$key]];
            }
        }
        // CA-10: a shared view's slice narrows every module.
        foreach ($this->slices as $slice) {
            $layers[] = ['closed' => $slice['closed'], 'groups' => [], 'slice' => $slice['dimensions']];
        }

        return $layers;
    }

    /**
     * Sub-select of employee ids the user may reach. Built without re-entering the access scope so
     * the position lookup itself is never scoped.
     *
     * CA-9: $module adds that module's data-access groups (Workforce groups always apply).
     */
    public function employeeKeys(User $user, ?string $module = null): QueryBuilder
    {
        $scope = $this->for($user);
        $layers = $this->layers($user, $module);

        return AccessScope::withoutScoping(function () use ($user, $scope, $layers) {
            $query = Employee::query()->select('employees.id');

            // The user's own scope, exactly as before (also for a user with no rows and no groups: callers asking
            // directly have always received this set).
            if ($scope !== null || $layers === []) {
                $this->constrainToScope($query, $user, $scope ?? []);
            }
            // CA-9: each layer only narrows.
            foreach ($layers as $layer) {
                $query->where(fn (Builder $q) => $this->applyLayer($q, $user, $layer));
            }

            return $query->toBase();
        });
    }

    public function constrainEmployees(Builder $query, User $user, ?string $module = null): Builder
    {
        return $query->whereIn($query->getModel()->qualifyColumn('id'), $this->employeeKeys($user, $module));
    }

    /**
     * Rows keyed by employee_id; rows with no employee (e.g. anonymous grievances) stay visible.
     * CA-9: the data-access groups of the model's module apply (unless $module names another).
     */
    public function constrainByEmployee(Builder $query, User $user, string $column = 'employee_id', ?string $module = null): Builder
    {
        $module ??= $this->moduleFor($query->getModel());
        $column = $query->getModel()->qualifyColumn($column);

        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereIn($column, $this->employeeKeys($user, $module)));
    }

    /**
     * Organisation units: companies are limited to the company scope; units under a company are
     * limited to the company scope and, when the unit's own dimension is scoped, to those ids.
     */
    public function constrainOrganisation(Builder $query, User $user, string $dimension): Builder
    {
        $scope = $this->for($user) ?? [];
        $model = $query->getModel();

        if ($dimension === 'company') {
            return isset($scope['company']) ? $query->whereIn($model->qualifyColumn('id'), $scope['company']) : $query;
        }

        if (isset($scope['company'])) {
            $query->whereIn($model->qualifyColumn('company_id'), $scope['company']);
        }

        if (isset($scope[$dimension])) {
            $query->whereIn($model->qualifyColumn('id'), $scope[$dimension]);
        }

        // Phase 6: an establishment-scoped user reaches statutory records of those establishments
        // only (legal-entity-level records such as TDS statements have no establishment and stay hidden),
        // and the legal entities that own them.
        if (isset($scope['establishment']) && $dimension !== 'establishment') {
            if ($model instanceof LegalEntity) {
                $query->whereIn($model->qualifyColumn('id'), Establishment::query()->withoutGlobalScope(AccessScope::class)->select('legal_entity_id')->whereIn('id', $scope['establishment']));
            } elseif (in_array('establishment_id', $model->getFillable(), true)) {
                $query->whereIn($model->qualifyColumn('establishment_id'), $scope['establishment']);
            }
        }

        return $query;
    }

    /** Record-level answer used by policies (defence in depth behind the query scope). */
    public function allows(User $user, Model $model): bool
    {
        // A record from another tenant is never reachable, whatever the scope rows say.
        $recordTenant = $model->getAttributes()['tenant_id'] ?? null;
        if ($recordTenant !== null && (int) $recordTenant !== (int) $this->tenants->id()) {
            return false;
        }

        // CA-9: the data-access groups of the record's module count too.
        $module = $this->moduleFor($model);
        if (! $this->isScoped($user, $module)) {
            return true;
        }

        if ($model instanceof Employee) {
            return $this->allowsEmployeeId($user, (int) $model->getKey());
        }

        if ($model instanceof Company) {
            return $this->constrainOrganisation(Company::query()->whereKey($model->getKey()), $user, 'company')->exists();
        }

        if (method_exists($model, 'applyAccessScope')) {
            $query = $model->newQueryWithoutScope(AccessScope::class)->whereKey($model->getKey());
            $model->applyAccessScope($query, $user, $this);

            return $query->exists();
        }

        $dimension = property_exists($model, 'accessScopeDimension') ? $model->accessScopeDimension : null;
        // Units without a dimension of their own (legal entities, establishments, statutory
        // records) are constrained through their denormalised company_id.
        if ($dimension !== null && (in_array($dimension, UserAccessScope::DIMENSIONS, true) || array_key_exists('company_id', $model->getAttributes()))) {
            return $this->constrainOrganisation($model->newQueryWithoutScope(AccessScope::class)->whereKey($model->getKey()), $user, $dimension)->exists();
        }

        // Phase 14: a model with no employee link (e.g. an audit event, scoped by its own query) is not
        // employee-constrained here; reading a missing attribute would throw under strict models.
        if (! array_key_exists('employee_id', $model->getAttributes()) && ! in_array('employee_id', $model->getFillable(), true)) {
            return true;
        }
        $employeeId = $model->getAttribute('employee_id');

        return $employeeId === null || $this->allowsEmployeeId($user, (int) $employeeId, $module);
    }

    public function allowsEmployeeId(User $user, int $employeeId, ?string $module = null): bool
    {
        // Inside an authorisation pass, an answer primed for this employee by primeEmployeeIds() (same constraint).
        $module = $this->effectiveModule($user, $module);
        $context = app(AuthorizationContext::class);
        $key = $this->primedKey($user, $employeeId, $module);
        if ($context->has($key)) {
            return (bool) $context->get($key);
        }

        return $this->employeeKeys($user, $module)->where('employees.id', $employeeId)->exists();
    }

    /**
     * UX.15 closure P1-02: decide reachability for many employees with one set query, for the current
     * authorisation pass only (nothing is kept outside AuthorizationContext::run()). The constraint is exactly
     * employeeKeys(), so each primed answer equals what allowsEmployeeId() would have queried.
     *
     * @param  iterable<int|string|null>  $employeeIds
     */
    public function primeEmployeeIds(User $user, iterable $employeeIds, ?string $module = null): void
    {
        $context = app(AuthorizationContext::class);
        $module = $this->effectiveModule($user, $module);
        if (! $context->active() || ! $this->isScoped($user, $module)) {
            return;
        }
        $ids = collect($employeeIds)->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $reachable = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->employeeKeys($user, $module)->whereIn('employees.id', $chunk)->pluck('id') as $id) {
                $reachable[(int) $id] = true;
            }
        }
        foreach ($ids as $id) {
            $context->put($this->primedKey($user, $id, $module), isset($reachable[$id]));
        }
    }

    /** CA-9: a primed answer belongs to one constraint, so the module whose groups shaped it is part of the key. */
    private function primedKey(User $user, int $employeeId, ?string $module = null): string
    {
        return 'scope:'.$this->tenants->id().':'.$user->getKey().':'.$employeeId.($module === null ? '' : ':'.$module)
            .($this->slices === [] ? '' : ':slice:'.md5((string) json_encode($this->slices)));
    }

    /** The module whose own groups limit $user (null when the user has none of that module: the Workforce answer holds). */
    private function effectiveModule(User $user, ?string $module): ?string
    {
        if ($module === null || $module === 'workforce') {
            return null;
        }
        $state = $this->groupState($user);

        return ! $state['closed'] && ($state['modules'][$module] ?? []) !== [] ? $module : null;
    }

    /**
     * The user's own scope rows as a constraint (Phase 0.2, unchanged): themselves, their reports today, and the employees
     * whose position today matches every scoped dimension. An unknown dimension reaches nobody (fail closed).
     *
     * @param  Builder<Employee>  $query
     * @param  array<string, list<int>>  $scope
     */
    private function constrainToScope(Builder $query, User $user, array $scope): void
    {
        $positions = EmployeePosition::query()->select('employee_id')->effectiveOn();

        foreach ($scope as $dimension => $ids) {
            if (! in_array($dimension, UserAccessScope::DIMENSIONS, true)) {
                $query->whereRaw('1 = 0');

                return;
            }
            if ($dimension === 'establishment') {
                // Phase 6: establishments come from the effective-dated assignment, not the position.
                $positions->whereIn('employee_id', EmployeeEstablishmentAssignment::query()->select('employee_id')->effectiveOn()->whereIn('establishment_id', $ids));

                continue;
            }
            $positions->whereIn("{$dimension}_id", $ids);
        }

        // Relationship scope (ADR-0004): a manager of any reporting type reaches the employees who
        // report to them today, whatever the organisation scope says.
        $reports = $this->directReports($user);

        $query->where(fn (Builder $q) => $q->where('employees.user_id', $user->getKey())->orWhereIn('employees.id', $positions)->orWhereIn('employees.id', $reports));
    }

    /**
     * CA-9: one layer of data-access groups: the user themselves, and everyone any of the layer's groups reaches.
     *
     * @param  Builder<Employee>  $query
     * @param  array{closed: bool, groups: list<array<string, mixed>>}  $layer
     */
    private function applyLayer(Builder $query, User $user, array $layer): void
    {
        if ($layer['closed']) {
            $query->whereRaw('1 = 0');

            return;
        }
        // CA-10: a shared view's slice: only the people of that part of the organisation (not the viewer as such).
        if (array_key_exists('slice', $layer)) {
            $query->whereIn('employees.id', $this->organisationReach(['dimensions' => $layer['slice'], 'sub_departments' => false]));

            return;
        }
        $query->where('employees.user_id', $user->getKey());
        foreach ($layer['groups'] as $group) {
            if ($group['basis'] === 'employees') {
                $query->orWhereIn('employees.id', $group['dimensions']['employee'] ?? []);
            } else {
                $query->orWhereIn('employees.id', $this->organisationReach($group));
            }
            if ($group['reportees'] === 'direct') {
                $query->orWhereIn('employees.id', $this->directReports($user));
            } elseif ($group['reportees'] === 'all') {
                $query->orWhereIn('employees.id', $this->allReportees($user));
            }
        }
    }

    /**
     * Employees whose position today matches every dimension of an organisation group (a group without rules reaches the
     * whole organisation, still inside the member's own scope).
     *
     * @param  array<string, mixed>  $group
     */
    private function organisationReach(array $group): Builder
    {
        $positions = EmployeePosition::query()->select('employee_id')->effectiveOn();
        foreach ($group['dimensions'] as $dimension => $ids) {
            if ($dimension === 'establishment') {
                $positions->whereIn('employee_id', EmployeeEstablishmentAssignment::query()->select('employee_id')->effectiveOn()->whereIn('establishment_id', $ids));

                continue;
            }
            if ($dimension === 'department' && $group['sub_departments']) {
                $ids = $this->withSubDepartments($ids);
            }
            $positions->whereIn("{$dimension}_id", $ids);
        }

        return $positions;
    }

    /** The employees reporting to $user today, any reporting type (ADR-0004). */
    private function directReports(User $user): Builder
    {
        return ReportingRelationship::query()->select('employee_id')->effectiveOn()
            ->whereIn('manager_id', Employee::query()->select('id')->where('user_id', $user->getKey()));
    }

    /**
     * SV-7 (AR-4): everyone below $user, every level (peopleos.access.max_depth levels at most), worked out once per request.
     *
     * @return list<int>
     */
    private function allReportees(User $user): array
    {
        return $this->computed[$user->getKey().':reportees'] ??= (function () use ($user): array {
            $frontier = Employee::query()->where('user_id', $user->getKey())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $seen = [];
            for ($depth = 0; $frontier !== [] && $depth < (int) config('peopleos.access.max_depth', 10); $depth++) {
                $next = ReportingRelationship::query()->effectiveOn()->whereIn('manager_id', $frontier)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
                $frontier = array_values(array_diff(array_unique($next), $seen, $frontier));
                array_push($seen, ...$frontier);
            }

            return array_values(array_unique($seen));
        })();
    }

    /**
     * SV-7 (AR-3): departments with the departments below them (peopleos.access.max_depth levels at most).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withSubDepartments(array $ids): array
    {
        sort($ids);

        return $this->computed['departments:'.implode(',', $ids)] ??= (function () use ($ids): array {
            $all = $ids;
            $frontier = $ids;
            for ($depth = 0; $frontier !== [] && $depth < (int) config('peopleos.access.max_depth', 10); $depth++) {
                $children = Department::query()->withoutGlobalScope(AccessScope::class)->whereIn('parent_department_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $frontier = array_values(array_diff($children, $all));
                array_push($all, ...$frontier);
            }

            return array_values(array_unique($all));
        })();
    }

    /**
     * CA-9: the user's active data-access groups by module, read once (under bypass, filtered to the user's own tenant).
     * Anything malformed closes everything.
     *
     * @return array{modules: array<string, list<array<string, mixed>>>, closed: bool}
     */
    private function groupState(User $user): array
    {
        $none = ['modules' => [], 'closed' => false];
        if ($user->isPlatformAdmin() || $user->tenant_id === null) {
            return $none;
        }
        $key = (int) $user->getKey();
        if (isset($this->groups[$key])) {
            return $this->groups[$key];
        }

        return $this->groups[$key] = $this->tenants->bypass(function () use ($user, $none): array {
            $tenantId = (int) $user->tenant_id;
            $memberships = AccessScopeGroupMember::query()->where('user_id', $user->getKey())->get(['tenant_id', 'access_scope_group_id']);
            if ($memberships->isEmpty()) {
                return $none;
            }
            $closed = ['modules' => [], 'closed' => true];
            $groups = AccessScopeGroup::query()->whereIn('id', $memberships->pluck('access_scope_group_id')->unique()->all())->get()->keyBy('id');
            $rules = AccessScopeGroupRule::query()->whereIn('access_scope_group_id', $groups->keys()->all())
                ->get(['tenant_id', 'access_scope_group_id', 'dimension', 'scope_id'])->groupBy('access_scope_group_id');
            $modules = [];
            foreach ($memberships as $membership) {
                $group = $groups[$membership->access_scope_group_id] ?? null;
                if ((int) $membership->tenant_id !== $tenantId || $group === null || (int) $group->tenant_id !== $tenantId) {
                    return $closed;
                }
                if (($group->getAttributes()['status'] ?? null) === 'inactive') {
                    continue;
                }
                $definition = $this->definition($group, $rules[$group->id] ?? collect(), $tenantId);
                if ($definition === null) {
                    return $closed;
                }
                $modules[$definition['module']][] = $definition;
            }

            return ['modules' => $modules, 'closed' => false];
        });
    }

    /**
     * A well-formed active group as the constraint reads it, or null when anything about it is unknown or malformed.
     *
     * @param  Collection<int, AccessScopeGroupRule>  $rules
     * @return array{id: int, module: string, basis: string, reportees: string, sub_departments: bool, dimensions: array<string, list<int>>}|null
     */
    public function definition(AccessScopeGroup $group, Collection $rules, int $tenantId): ?array
    {
        $raw = $group->getAttributes();
        $module = $raw['module'] ?? null;
        $basis = $raw['basis'] ?? null;
        $reportees = $raw['reportees'] ?? null;
        $sub = $raw['include_sub_departments'] ?? null;
        if (($raw['status'] ?? null) !== 'active'
            || ! is_string($module) || ! array_key_exists($module, (array) config('peopleos.access.modules', []))
            || ! is_string($basis) || ! array_key_exists($basis, AccessScopeGroup::BASES)
            || ! is_string($reportees) || ! array_key_exists($reportees, AccessScopeGroup::REPORTEES)
            || ! in_array($sub, [0, 1, true, false, '0', '1'], true)) {
            return null;
        }
        $dimensions = [];
        foreach ($rules as $rule) {
            $dimension = $rule->getAttributes()['dimension'] ?? null;
            $id = $rule->getAttributes()['scope_id'] ?? null;
            if ((int) $rule->tenant_id !== $tenantId || ! is_string($dimension) || ! in_array($dimension, AccessScopeGroupRule::DIMENSIONS, true)
                || ! is_numeric($id) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                return null;
            }
            // A group of named employees has only employees; an organisation group only organisation units.
            if (($basis === 'employees') !== ($dimension === 'employee')) {
                return null;
            }
            $dimensions[$dimension][] = (int) $id;
        }

        return [
            'id' => (int) $group->getKey(), 'module' => $module, 'basis' => $basis, 'reportees' => $reportees,
            'sub_departments' => in_array($sub, [1, true, '1'], true), 'dimensions' => array_map(fn ($ids) => array_values(array_unique($ids)), $dimensions),
        ];
    }

    /**
     * Replace the user's scope with the given map (dimension => ids), auditing each change.
     *
     * @param  array<string, list<int>>  $scope
     */
    public function assign(User $user, array $scope, ?string $reason = null): void
    {
        $existing = UserAccessScope::query()->where('user_id', $user->getKey())->get();

        foreach ($existing as $row) {
            if (! in_array((int) $row->scope_id, $scope[$row->dimension] ?? [], true)) {
                $row->withAuditReason($reason)->delete();
            }
        }

        foreach ($scope as $dimension => $ids) {
            if (! in_array($dimension, UserAccessScope::DIMENSIONS, true)) {
                continue;
            }

            foreach (array_unique(array_map('intval', $ids)) as $id) {
                if (! $existing->contains(fn ($row) => $row->dimension === $dimension && (int) $row->scope_id === $id)) {
                    (new UserAccessScope(['user_id' => $user->getKey(), 'dimension' => $dimension, 'scope_id' => $id]))->withAuditReason($reason)->save();
                }
            }
        }

        $this->forget((int) $user->getKey());
    }
}

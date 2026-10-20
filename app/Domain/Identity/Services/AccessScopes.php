<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Organisational access scoping (Phase 0.2 ABAC). Resolves a user's scope rows into query
 * constraints that are applied at the data-access layer by {@see AccessScope}, and answers
 * record-level questions for policies.
 *
 * Semantics: no rows = tenant-wide (subject to permissions). With rows, an employee is visible
 * when the user is that employee, when the employee reports to the user today (any reporting
 * type), or when the employee's position effective today matches every scoped dimension (any of
 * the listed values per dimension).
 */
final class AccessScopes
{
    /** @var array<int, array<string, list<int>>|null> */
    private array $cache = [];

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

    public function isScoped(User $user): bool
    {
        return $this->for($user) !== null;
    }

    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$userId]);
        }
    }

    /**
     * Sub-select of employee ids the user may reach. Built without re-entering the access scope so
     * the position lookup itself is never scoped.
     */
    public function employeeKeys(User $user): QueryBuilder
    {
        $scope = $this->for($user) ?? [];

        return AccessScope::withoutScoping(function () use ($user, $scope) {
            $positions = EmployeePosition::query()->select('employee_id')->effectiveOn();

            foreach ($scope as $dimension => $ids) {
                if ($dimension === 'establishment') {
                    // Phase 6: establishments come from the effective-dated assignment, not the position.
                    $positions->whereIn('employee_id', EmployeeEstablishmentAssignment::query()->select('employee_id')->effectiveOn()->whereIn('establishment_id', $ids));

                    continue;
                }
                $positions->whereIn("{$dimension}_id", $ids);
            }

            // Relationship scope (ADR-0004): a manager of any reporting type reaches the employees who
            // report to them today, whatever the organisation scope says.
            $reports = ReportingRelationship::query()->select('employee_id')->effectiveOn()
                ->whereIn('manager_id', Employee::query()->select('id')->where('user_id', $user->getKey()));

            return Employee::query()
                ->select('employees.id')
                ->where(fn (Builder $q) => $q->where('employees.user_id', $user->getKey())->orWhereIn('employees.id', $positions)->orWhereIn('employees.id', $reports))
                ->toBase();
        });
    }

    public function constrainEmployees(Builder $query, User $user): Builder
    {
        return $query->whereIn($query->getModel()->qualifyColumn('id'), $this->employeeKeys($user));
    }

    /** Rows keyed by employee_id; rows with no employee (e.g. anonymous grievances) stay visible. */
    public function constrainByEmployee(Builder $query, User $user, string $column = 'employee_id'): Builder
    {
        $column = $query->getModel()->qualifyColumn($column);

        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereIn($column, $this->employeeKeys($user)));
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

        if (! $this->isScoped($user)) {
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

        return $employeeId === null || $this->allowsEmployeeId($user, (int) $employeeId);
    }

    public function allowsEmployeeId(User $user, int $employeeId): bool
    {
        // Inside an authorisation pass, an answer primed for this employee by primeEmployeeIds() (same constraint).
        $context = app(AuthorizationContext::class);
        $key = $this->primedKey($user, $employeeId);
        if ($context->has($key)) {
            return (bool) $context->get($key);
        }

        return $this->employeeKeys($user)->where('employees.id', $employeeId)->exists();
    }

    /**
     * UX.15 closure P1-02: decide reachability for many employees with one set query, for the current
     * authorisation pass only (nothing is kept outside AuthorizationContext::run()). The constraint is exactly
     * employeeKeys(), so each primed answer equals what allowsEmployeeId() would have queried.
     *
     * @param  iterable<int|string|null>  $employeeIds
     */
    public function primeEmployeeIds(User $user, iterable $employeeIds): void
    {
        $context = app(AuthorizationContext::class);
        if (! $context->active() || ! $this->isScoped($user)) {
            return;
        }
        $ids = collect($employeeIds)->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $reachable = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->employeeKeys($user)->whereIn('employees.id', $chunk)->pluck('id') as $id) {
                $reachable[(int) $id] = true;
            }
        }
        foreach ($ids as $id) {
            $context->put($this->primedKey($user, $id), isset($reachable[$id]));
        }
    }

    private function primedKey(User $user, int $employeeId): string
    {
        return 'scope:'.$this->tenants->id().':'.$user->getKey().':'.$employeeId;
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

<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who may clone what, where (FR-1, FR-3, Security; decisions OD-2 to OD-4). Configuration lists are not filtered by
 * company today, so the clone engine enforces company scope itself:
 *
 * - the tenant has cloning switched on (feature configuration.cloning);
 * - configuration.clone, for a variation inside a company the source already reaches; configuration.clone.cross_company
 *   as well for another company or several at once;
 * - every destination is an active company inside the person's company scope, and for every destination the person
 *   holds configuration.clone and the type's own create permission for that company (the first caller of the
 *   company-aware User::hasPermission($key, $companyId), so a role limited to one company only clones into it);
 * - a person limited to some companies may clone a source that applies to every company, or only to their companies; a
 *   source aimed at other companies is refused.
 */
final class CloneScope
{
    public const FEATURE = 'configuration.cloning';

    public function __construct(
        private readonly AccessScopes $scopes,
        private readonly FeatureFlags $features,
    ) {}

    public function enabled(): bool
    {
        return $this->features->enabled(self::FEATURE);
    }

    /** Whether the person may clone this type at all (the action shows). */
    public function mayClone(User $user, CloneAdapter $adapter): bool
    {
        return $this->enabled() && $user->hasPermission('configuration.clone') && $user->hasPermission($adapter->createPermission());
    }

    public function mayCrossCompanies(User $user): bool
    {
        return $user->hasPermission('configuration.clone.cross_company');
    }

    /** @return list<int>|null the companies the person's scope limits them to; null = every company */
    public function companiesFor(User $user): ?array
    {
        $scope = $this->scopes->for($user);
        if ($scope === null) {
            return null;
        }
        if (isset($scope['company'])) {
            return array_values(array_map('intval', $scope['company']));
        }
        // Limited by other organisation units only: the companies those units belong to (none = no company at all).
        $companies = [];
        foreach (['location', 'business_unit', 'division', 'department', 'team'] as $dimension) {
            $field = "{$dimension}_id";
            foreach ((array) ($scope[$dimension] ?? []) as $id) {
                $owner = app(OrgUnitMapper::class)->companyOf($field, (int) $id);
                if (is_int($owner)) {
                    $companies[] = $owner;
                }
            }
        }

        return array_values(array_unique($companies));
    }

    /** @return list<int> every company of the tenant */
    public function allCompanyIds(): array
    {
        return Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The companies the person may pick as destinations for this source.
     *
     * @param  list<int>|null  $sourceCompanies  null = the source reaches every company
     * @return Collection<int, Company>
     */
    public function destinations(User $user, CloneAdapter $adapter, ?array $sourceCompanies): Collection
    {
        $mine = $this->companiesFor($user);
        $cross = $this->mayCrossCompanies($user);

        return Company::query()->withoutGlobalScope(AccessScope::class)->where('status', ActiveStatus::Active->value)->orderBy('name')->get()
            ->filter(fn (Company $c) => ($mine === null || in_array((int) $c->id, $mine, true))
                && ($cross || $sourceCompanies === null || in_array((int) $c->id, $sourceCompanies, true))
                && $user->hasPermission('configuration.clone', (int) $c->id) && $user->hasPermission($adapter->createPermission(), (int) $c->id))
            ->values();
    }

    /** The mode a set of destinations makes for this source. @param list<int>|null $sourceCompanies */
    public function mode(?array $sourceCompanies, array $destinationIds): CloneMode
    {
        if (count($destinationIds) > 1) {
            return CloneMode::Companies;
        }

        return $sourceCompanies === null || in_array((int) ($destinationIds[0] ?? 0), $sourceCompanies, true) ? CloneMode::Variation : CloneMode::Company;
    }

    /**
     * Refuses the clone unless the person may make it.
     *
     * @param  list<int>|null  $sourceCompanies
     * @param  list<int>  $destinationIds
     */
    public function authorise(User $user, CloneAdapter $adapter, ?array $sourceCompanies, array $destinationIds): CloneMode
    {
        if (! $this->enabled()) {
            throw new CloneRefusedException('Cloning is switched off for this organisation.');
        }
        if (! $user->hasPermission('configuration.clone') || ! $user->hasPermission($adapter->createPermission())) {
            throw new CloneRefusedException('Your roles do not let you clone '.self::plural($adapter).'.');
        }
        $limit = (int) config('peopleos.cloning.limits.destinations', 10);
        if ($destinationIds === [] || count($destinationIds) > $limit) {
            throw new CloneRefusedException("Choose between one and {$limit} companies to clone into.");
        }
        if (count($destinationIds) !== count(array_unique($destinationIds))) {
            throw new CloneRefusedException('Each company can be chosen once.');
        }
        $mode = $this->mode($sourceCompanies, $destinationIds);
        if ($mode->crossCompany() && ! $this->mayCrossCompanies($user)) {
            throw new CloneRefusedException('You may clone only as a variation inside a company the source already applies to.');
        }
        $mine = $this->companiesFor($user);
        if ($mine !== null && $sourceCompanies !== null && array_diff($sourceCompanies, $mine) !== []) {
            throw new CloneRefusedException('This '.Str::lower($adapter->label()).' applies to companies outside your access, so you cannot clone it.');
        }
        $companies = Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($destinationIds)->get()->keyBy('id');
        foreach ($destinationIds as $id) {
            $company = $companies->get($id);
            if ($company === null || $company->status !== ActiveStatus::Active) {
                throw new CloneRefusedException('One of the companies chosen does not exist or is not active.');
            }
            if ($mine !== null && ! in_array($id, $mine, true)) {
                throw new CloneRefusedException("{$company->name} is outside your access.");
            }
            if (! $user->hasPermission('configuration.clone', $id) || ! $user->hasPermission($adapter->createPermission(), $id)) {
                throw new CloneRefusedException('Your roles do not let you create '.self::plural($adapter)." for {$company->name}.");
            }
        }

        return $mode;
    }

    private static function plural(CloneAdapter $adapter): string
    {
        return Str::plural(Str::lower($adapter->label()));
    }
}

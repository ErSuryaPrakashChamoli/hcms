<?php

namespace App\Domain\Configuration\Applicability;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Cloning\CloneScope;
use App\Domain\Configuration\Models\ConfigurationApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicabilityCompany;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Which companies a configuration record applies to (universal entity applicability,
 * docs/architecture/configuration-applicability.md).
 *
 * One logical record serves several companies: its companies are rows in configuration_applicability_companies, each
 * effective-dated and only ever end-dated. A record without explicit applicability keeps its earlier meaning, so nothing
 * existing changes until someone chooses: a tenant-wide record applies to every company (now and later); a company-owned
 * record (a department or location with company_id) applies to its own company, or to every company when it has none.
 *
 * Changing applicability is planned first (plan(): who joins, who leaves, what blocks it) and then applied in one
 * transaction with an audit event. The person needs the type's own permission, configuration.share to make a record
 * reach more than one company, and every company touched must be inside their company scope.
 */
final class EntityApplicability
{
    public const SHARE_PERMISSION = 'configuration.share';

    /** Position dimensions (EmployeePosition::DIMENSIONS) held by registered configuration types. */
    public const POSITION_TYPES = [
        'location_id' => 'location', 'business_unit_id' => 'business_unit', 'division_id' => 'division', 'department_id' => 'department',
        'team_id' => 'team', 'designation_id' => 'designation', 'level_id' => 'level', 'grade_id' => 'grade',
        'employment_type_id' => 'employment_type', 'employee_category_id' => 'employee_category', 'work_mode_id' => 'work_mode',
        'cost_centre_id' => 'cost_centre', 'dimension_value_id' => 'dimension_value',
    ];

    /** @var array<string, array<int, array{mode: string, future: bool, rows: list<array{0: int, 1: ?string, 2: ?string}>}>> process-wide memo: tenant:type => id => entry */
    private static array $memo = [];

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    public function __construct(
        private readonly ConfigurationTypes $types,
        private readonly CloneScope $scope,
        private readonly ApplicabilityDependencies $dependencies,
        private readonly AuditRecorder $audit,
    ) {}

    public function type(Model $record): ConfigurationType
    {
        return $this->types->forModelOrFail($record);
    }

    public function header(ConfigurationType|string $type, int $id): ?ConfigurationApplicability
    {
        $key = $type instanceof ConfigurationType ? $type->key : $type;

        return ConfigurationApplicability::query()->where('configuration_type', $key)->where('configuration_id', $id)->first();
    }

    public function isExplicit(Model $record): bool
    {
        return $this->header($this->type($record), (int) $record->getKey()) !== null;
    }

    /**
     * The companies the record applies to on a date: a sorted list, or null for "every company" (a record whose companies
     * were never chosen and that no company owns).
     *
     * @return list<int>|null
     */
    public function reach(Model $record, CarbonInterface|string|null $on = null): ?array
    {
        $type = $this->type($record);
        $rows = $this->explicit($type->key)[(int) $record->getKey()] ?? null;
        if ($rows === null) {
            return $this->earlierReach($type, $record);
        }
        $date = self::date($on);
        $ids = [];
        foreach ($rows as [$company, $from, $to]) {
            if (($from === null || $from <= $date) && ($to === null || $to >= $date)) {
                $ids[] = $company;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Every explicitly chosen company row of a type, read once per type and request (resolvers ask for many records).
     *
     * @return array<int, list<array{0: int, 1: ?string, 2: ?string}>>
     */
    private function explicit(string $typeKey): array
    {
        return array_map(fn (array $entry) => $entry['rows'], $this->explicitEntries($typeKey));
    }

    /**
     * Every explicitly chosen record of a type with its mode, "later companies join" and company rows, read once per type
     * and request (resolvers and lists ask for many records).
     *
     * @return array<int, array{mode: string, future: bool, rows: list<array{0: int, 1: ?string, 2: ?string}>}>
     */
    private function explicitEntries(string $typeKey): array
    {
        $tenant = app(TenantContext::class)->id();
        $bucket = (string) ($tenant ?? '0').':'.$typeKey;
        if (! isset(self::$memo[$bucket])) {
            $map = [];
            $rows = DB::table('configuration_applicability as cfa')
                ->leftJoin('configuration_applicability_companies as cfac', 'cfac.applicability_id', '=', 'cfa.id')
                ->where('cfa.configuration_type', $typeKey)
                ->where('cfa.tenant_id', $tenant)
                ->get(['cfa.configuration_id', 'cfa.mode', 'cfa.include_future', 'cfac.company_id', 'cfac.effective_from', 'cfac.effective_to']);
            foreach ($rows as $row) {
                $map[(int) $row->configuration_id] ??= ['mode' => (string) $row->mode, 'future' => (bool) $row->include_future, 'rows' => []];
                if ($row->company_id !== null) {
                    $map[(int) $row->configuration_id]['rows'][] = [(int) $row->company_id, $row->effective_from === null ? null : substr((string) $row->effective_from, 0, 10),
                        $row->effective_to === null ? null : substr((string) $row->effective_to, 0, 10)];
                }
            }
            self::$memo[$bucket] = $map;
        }

        return self::$memo[$bucket];
    }

    /** @return list<int>|null the reach a record has without explicit applicability */
    public function earlierReach(ConfigurationType $type, Model $record): ?array
    {
        $owner = $type->ownerColumn === null ? null : $record->getAttribute($type->ownerColumn);

        return $owner === null ? null : [(int) $owner];
    }

    /** Whether the record applies to the company of a rule context (employee context; no company = not limited). */
    public function allowsContext(Model $record, array $context, CarbonInterface|string|null $on = null): bool
    {
        $company = $context['company_id'] ?? null;

        return ! filled($company) || $this->appliesTo($record, (int) $company, $on);
    }

    public function appliesTo(Model $record, int $companyId, CarbonInterface|string|null $on = null): bool
    {
        $reach = $this->reach($record, $on);

        return $reach === null || in_array($companyId, $reach, true);
    }

    /**
     * Limits a query of a registered type to the records that apply to these companies on a date (pickers, lists,
     * resolvers). Records whose companies were never chosen keep their earlier meaning.
     *
     * @param  int|list<int>  $companyIds
     */
    public function constrain(Builder $query, int|array $companyIds, CarbonInterface|string|null $on = null): Builder
    {
        $model = $query->getModel();
        $type = $this->types->forModelOrFail($model);
        $ids = array_values(array_map('intval', (array) $companyIds));
        $date = self::date($on);
        $key = $model->getQualifiedKeyName();

        return $query->where(function (Builder $q) use ($type, $ids, $date, $key, $model) {
            $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('configuration_applicability as cfa')
                ->join('configuration_applicability_companies as cfac', 'cfac.applicability_id', '=', 'cfa.id')
                ->where('cfa.configuration_type', $type->key)->whereColumn('cfa.configuration_id', $key)
                ->whereIn('cfac.company_id', $ids)
                ->where(fn ($w) => $w->whereNull('cfac.effective_from')->orWhere('cfac.effective_from', '<=', $date.' 23:59:59'))
                ->where(fn ($w) => $w->whereNull('cfac.effective_to')->orWhere('cfac.effective_to', '>=', $date)))
                ->orWhere(function (Builder $legacy) use ($type, $ids, $key, $model) {
                    $legacy->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('configuration_applicability as cfb')
                        ->where('cfb.configuration_type', $type->key)->whereColumn('cfb.configuration_id', $key));
                    if ($type->ownerColumn !== null) {
                        $column = $model->qualifyColumn($type->ownerColumn);
                        $legacy->where(fn ($o) => $o->whereNull($column)->orWhereIn($column, $ids));
                    }
                });
        });
    }

    /**
     * The selector's state for a record: mode, companies today, whether later companies join, and whether it was chosen.
     *
     * @return array{explicit: bool, mode: string, companies: list<int>, company: ?int, include_future: bool, effective_from: string}
     */
    public function state(Model $record): array
    {
        $type = $this->type($record);
        $header = $record->exists ? $this->header($type, (int) $record->getKey()) : null;
        $reach = $record->exists ? $this->reach($record) : $this->earlierReach($type, $record);
        $mode = $header?->mode ?? ($reach === null ? ApplicabilityMode::All : (count($reach) === 1 ? ApplicabilityMode::Single : ApplicabilityMode::Selected));
        $companies = $reach ?? $this->allCompanyIds();

        return [
            'explicit' => $header !== null,
            'mode' => $mode->value,
            'companies' => $companies,
            'company' => count($companies) === 1 ? $companies[0] : null,
            'include_future' => $header?->include_future ?? ($reach === null),
            'effective_from' => today()->toDateString(),
        ];
    }

    /**
     * Refuses a position (or anything else holding these dimensions) for a company when one of its records was explicitly
     * limited to other companies. Records whose companies were never chosen keep their earlier behaviour (not checked).
     * Past periods are history: the check looks at the later of the given date and today.
     *
     * @param  array<string, mixed>  $dimensions  column => id
     */
    public function assertUsableByCompany(array $dimensions, int $companyId, CarbonInterface|string|null $on = null): void
    {
        // Checked as of the later of the start date and today: a backdated entry is judged by what the company may use now
        // (applicability never starts in the past), and a future one by what it may use then.
        $on = max(self::date($on), today()->toDateString());
        $problems = [];
        $company = null;
        foreach (self::POSITION_TYPES as $column => $typeKey) {
            $id = $dimensions[$column] ?? null;
            if (! filled($id) || ! $this->types->has($typeKey) || ! isset($this->explicit($typeKey)[(int) $id])) {
                continue;
            }
            $type = $this->types->for($typeKey);
            $record = $type->model::query()->withoutGlobalScope(AccessScope::class)->find((int) $id);
            if ($record !== null && ! $this->appliesTo($record, $companyId, $on)) {
                $company ??= Company::query()->withoutGlobalScope(AccessScope::class)->find($companyId)?->name ?? 'this company';
                $problems[] = "{$type->label} \"{$type->describe($record)}\" does not apply to {$company}.";
            }
        }
        if ($problems !== []) {
            throw ApplicabilityRefusedException::because('These choices are not used by the company:', $problems);
        }
    }

    /**
     * Picker options of a registered type for a company (every record when no company is known yet), name => id.
     *
     * @param  class-string<Model>  $model
     * @return array<int, string>
     */
    public function options(string $model, ?int $companyId, string $label = 'name'): array
    {
        $query = $model::query();
        if ($companyId !== null && $this->types->forModel($model) !== null) {
            $this->constrain($query, $companyId);
        }

        return $query->orderBy($label)->pluck($label, $query->getModel()->getKeyName())->all();
    }

    /** A short sentence for lists and headers: "All entities", "Markedge, FynnEdge", "Markedge only". */
    public function summary(Model $record, ?array $names = null): string
    {
        $entry = $this->explicitEntries($this->type($record)->key)[(int) $record->getKey()] ?? null;
        $reach = $this->reach($record);
        if ($reach === null) {
            return 'All entities';
        }
        $names ??= $this->companyNames();
        if ($entry !== null && $entry['mode'] === ApplicabilityMode::All->value) {
            return 'All entities'.($entry['future'] ? '' : ' (not later ones)');
        }
        $labels = array_map(fn (int $id) => $names[$id] ?? "#{$id}", $reach);

        return match (count($labels)) {
            0 => 'No entity',
            1 => $labels[0].' only',
            default => implode(', ', $labels),
        };
    }

    /** "shared" (several companies or every company) or "single" (one company), today. */
    public function kind(Model $record): string
    {
        $reach = $this->reach($record);

        return $reach === null || count($reach) > 1 ? 'shared' : 'single';
    }

    /**
     * Limits a query of a registered type to records of an applicability kind: "shared" (every company, or several
     * chosen) or "single" (one company only), as they apply today.
     */
    public function constrainKind(Builder $query, string $kind): Builder
    {
        $model = $query->getModel();
        $type = $this->types->forModelOrFail($model);
        $entries = $this->explicitEntries($type->key);
        $today = today()->toDateString();
        $explicitSingle = [];
        $explicitShared = [];
        foreach ($entries as $id => $entry) {
            $count = count(array_unique(array_map(fn ($row) => $row[0], array_filter($entry['rows'], fn ($row) => ($row[1] === null || $row[1] <= $today) && ($row[2] === null || $row[2] >= $today)))));
            $count > 1 || $entry['mode'] === ApplicabilityMode::All->value ? $explicitShared[] = $id : $explicitSingle[] = $id;
        }
        $key = $model->getQualifiedKeyName();
        $legacy = fn (Builder $q) => $q->whereNotIn($key, array_keys($entries) ?: [0]);
        $owner = $type->ownerColumn === null ? null : $model->qualifyColumn($type->ownerColumn);

        return $query->where(function (Builder $q) use ($kind, $key, $explicitSingle, $explicitShared, $legacy, $owner) {
            if ($kind === 'single') {
                $q->whereIn($key, $explicitSingle ?: [0]);
                if ($owner !== null) {
                    $q->orWhere(fn (Builder $l) => $legacy($l)->whereNotNull($owner));
                }
            } else {
                $q->whereIn($key, $explicitShared ?: [0])->orWhere(fn (Builder $l) => $owner === null ? $legacy($l) : $legacy($l)->whereNull($owner));
            }
        });
    }

    /** Works out what a change would do, without writing anything (the review step). */
    public function plan(Model $record, ApplicabilitySelection $selection, ?User $actor): ApplicabilityPlan
    {
        $type = $this->type($record);
        $header = $this->header($type, (int) $record->getKey());
        $from = $selection->effectiveFrom !== null ? Carbon::parse($selection->effectiveFrom)->toDateString() : today()->toDateString();
        // A record not saved yet (an import's preview) reaches nobody: everything chosen is joining, nothing is leaving.
        $before = ! $record->exists ? [] : ($header === null ? $this->earlierReach($type, $record) : $this->companiesOn($header, $from));
        $all = $this->allCompanyIds();
        $names = $this->companyNames();

        $blockers = [];
        $after = $this->normalise($type, $record, $selection, $all, $names, $blockers);
        $beforeList = $before ?? $all;
        $added = array_values(array_diff($after, $beforeList));
        $removed = array_values(array_diff($beforeList, $after));
        sort($added);
        sort($removed);

        if ($from < today()->toDateString()) {
            $blockers[] = 'Applicability cannot start in the past: past transactions keep what applied to them. Choose today or a later date.';
        }
        $blockers = [...$blockers, ...$this->authorisation($type, $actor, $before, $after, $added, $removed, $selection, $names)];
        $warnings = [];
        if ($blockers === []) {
            [$depBlockers, $depWarnings] = $this->dependencies->check($type, $record, $after, $removed, $from, $selection->includeFuture, $names);
            $blockers = [...$blockers, ...$depBlockers];
            $warnings = $depWarnings;
        }
        if ($selection->includeFuture) {
            $warnings[] = 'Companies created later will use this '.strtolower($type->label).' automatically, from the day they are created.';
        }

        $selection = new ApplicabilitySelection($selection->mode, $after, $selection->includeFuture && $selection->mode === ApplicabilityMode::All, $from);

        return new ApplicabilityPlan($type->key, (int) $record->getKey(), $type->describe($record), $header !== null, $header?->mode, $before,
            $selection, $after, $added, $removed, $from, array_values(array_unique($blockers)), array_values(array_unique($warnings)), $names);
    }

    /** Plans and makes the change: one transaction, one audit event. Refuses when the plan has blockers. */
    public function apply(Model $record, ApplicabilitySelection $selection, ?User $actor, ?string $reason = null): ApplicabilityPlan
    {
        $plan = $this->plan($record, $selection, $actor);
        if ($plan->blocked()) {
            throw ApplicabilityRefusedException::because("{$plan->label} cannot be applied that way.", $plan->blockers);
        }
        $type = $this->type($record);

        DB::transaction(function () use ($plan, $type, $record, $actor, $reason) {
            $header = ConfigurationApplicability::query()->where('configuration_type', $type->key)->where('configuration_id', $record->getKey())->lockForUpdate()->first();
            if ($header === null) {
                $header = ConfigurationApplicability::create([
                    'configuration_type' => $type->key, 'configuration_id' => $record->getKey(), 'mode' => $plan->selection->mode,
                    'include_future' => $plan->selection->includeFuture, 'created_by' => $actor?->getKey(), 'updated_by' => $actor?->getKey(),
                ]);
                // History stays true: the reach the record had until now is written as it was, open at the start.
                foreach ($plan->before ?? $this->allCompanyIds() as $companyId) {
                    $header->companies()->create(['company_id' => $companyId, 'effective_from' => null, 'source' => ConfigurationApplicabilityCompany::EARLIER, 'added_by' => $actor?->getKey()]);
                }
            } else {
                $header->update(['mode' => $plan->selection->mode, 'include_future' => $plan->selection->includeFuture, 'updated_by' => $actor?->getKey()]);
            }

            $from = Carbon::parse($plan->effectiveFrom);
            $live = $header->companies()->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $plan->effectiveFrom))->get();
            foreach ($live->whereIn('company_id', $plan->removed) as $row) {
                // A row that has not started yet is closed before its start (it never applied); others end the day before.
                $end = $row->effective_from !== null && $row->effective_from->gte($from) ? $row->effective_from->copy()->subDay() : $from->copy()->subDay();
                $row->update(['effective_to' => $end->toDateString(), 'ended_by' => $actor?->getKey(), 'reason' => $reason !== null ? mb_substr($reason, 0, 500) : $row->reason]);
            }
            $present = $live->whereNotIn('company_id', $plan->removed)->pluck('company_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($plan->after, $present) as $companyId) {
                $header->companies()->create(['company_id' => $companyId, 'effective_from' => $plan->effectiveFrom, 'source' => ConfigurationApplicabilityCompany::CHOSEN,
                    'added_by' => $actor?->getKey(), 'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null]);
            }

            $this->audit->record(AuditAction::ApplicabilityChanged, $type->module, $record, [[
                'field' => 'applicability', 'before' => $plan->names($plan->before), 'after' => $plan->names($plan->after).($plan->selection->includeFuture ? ' and companies created later' : ''),
            ]], $reason, effectiveDate: $plan->effectiveFrom, metadata: ['configuration_type' => $type->key] + $plan->toArray(), actor: $actor);
        });
        self::flushMemo();

        return $plan;
    }

    /**
     * A company was created: every record whose applicability says "companies created later join" starts applying to it
     * from today.
     *
     * @return int how many records it joined
     */
    public function companyCreated(Company $company): int
    {
        $headers = ConfigurationApplicability::query()->where('include_future', true)->where('mode', ApplicabilityMode::All->value)->get();
        foreach ($headers as $header) {
            $header->companies()->create(['company_id' => $company->getKey(), 'effective_from' => today()->toDateString(), 'source' => ConfigurationApplicabilityCompany::FUTURE]);
        }
        self::flushMemo();
        if ($headers->isNotEmpty()) {
            $this->audit->record(AuditAction::ApplicabilityChanged, 'configuration', $company, [], 'New company joins configuration shared with companies created later',
                metadata: ['joined' => $headers->map(fn (ConfigurationApplicability $h) => ['type' => $h->configuration_type, 'id' => $h->configuration_id])->values()->all()]);
        }

        return $headers->count();
    }

    /**
     * Whether the person may change a record as it applies today: a person limited to some companies cannot change a
     * configuration that reaches other companies too (the record is shared beyond their access).
     */
    public function editableBy(Model $record, User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }
        $mine = $this->scope->companiesFor($user);
        if ($mine === null) {
            return true;
        }
        $reach = $this->reach($record);
        // A company-owned record moving between companies: the company it leaves counts as much as the one it joins.
        $type = $this->type($record);
        if ($type->ownerColumn !== null && $record->exists && $record->isDirty($type->ownerColumn) && ! $this->isExplicit($record)) {
            $original = $record->getOriginal($type->ownerColumn);
            $reach = $original === null || $reach === null ? null : array_values(array_unique([...$reach, (int) $original]));
        }

        return $reach !== null && array_diff($reach, $mine) === [];
    }

    /** @return list<int> every company of the tenant (active or not), by id */
    public function allCompanyIds(): array
    {
        return $this->scope->allCompanyIds();
    }

    /** @return array<int, string> */
    public function companyNames(): array
    {
        $bucket = (string) (app(TenantContext::class)->id() ?? '0').':companies';

        return self::$memo[$bucket] ??= Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(int) $id => (string) $n])->all();
    }

    /** @return list<int>|null the companies the person's scope limits them to (null = every company) */
    public function companiesFor(User $user): ?array
    {
        return $this->scope->companiesFor($user);
    }

    /** @return list<int> */
    private function companiesOn(ConfigurationApplicability $header, string $date): array
    {
        $ids = ConfigurationApplicabilityCompany::query()->where('applicability_id', $header->id)->on($date)->pluck('company_id')
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $ids;
    }

    /**
     * @param  list<int>  $all
     * @param  array<int, string>  $names
     * @param  list<string>  $blockers
     * @return list<int>
     */
    private function normalise(ConfigurationType $type, Model $record, ApplicabilitySelection $selection, array $all, array $names, array &$blockers): array
    {
        $owner = $type->ownerColumn === null ? null : $record->getAttribute($type->ownerColumn);
        $owner = $owner === null ? null : (int) $owner;

        $after = match ($selection->mode) {
            ApplicabilityMode::All => $all,
            ApplicabilityMode::Single => $owner !== null && $selection->companyIds === [] ? [$owner] : array_slice($selection->companyIds, 0, 1),
            ApplicabilityMode::Selected => array_values(array_unique($selection->companyIds)),
        };
        if ($selection->mode === ApplicabilityMode::Single && count(array_unique($selection->companyIds)) > 1) {
            $blockers[] = 'Choose one company for "Current entity only".';
        }
        $unknown = array_diff($after, $all);
        if ($unknown !== []) {
            $blockers[] = 'One of the companies chosen does not exist in this organisation.';
            $after = array_values(array_intersect($after, $all));
        }
        if ($after === []) {
            $blockers[] = 'Choose at least one company.';
        }
        if ($owner !== null && ! in_array($owner, $after, true)) {
            $blockers[] = 'This '.strtolower($type->label).' belongs to '.($names[$owner] ?? 'its company').', so it always applies there. Keep that company, or clone the record for the others.';
        }
        $after = array_values(array_unique(array_map('intval', $after)));
        sort($after);

        return $after;
    }

    /**
     * @param  list<int>|null  $before
     * @param  list<int>  $after
     * @param  list<int>  $added
     * @param  list<int>  $removed
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private function authorisation(ConfigurationType $type, ?User $actor, ?array $before, array $after, array $added, array $removed, ApplicabilitySelection $selection, array $names): array
    {
        if ($actor === null || $actor->isPlatformAdmin()) {
            return [];
        }
        $problems = [];
        $sharing = count($after) > 1 || $selection->includeFuture;
        if (! $actor->hasPermission($type->permission)) {
            return ['Your roles do not let you manage '.$type->plural().'.'];
        }
        if ($sharing && ! $actor->hasPermission(self::SHARE_PERMISSION)) {
            $problems[] = 'Your roles do not let you share configuration across several companies.';
        }
        $mine = $this->scope->companiesFor($actor);
        if ($mine !== null) {
            if ($before === null || array_diff($before, $mine) !== []) {
                $problems[] = 'This '.strtolower($type->label).' applies to companies outside your access, so you cannot change where it applies.';
            }
            if ($selection->includeFuture) {
                $problems[] = 'Only someone with access to every company can include companies created later.';
            }
            foreach ([...$added, ...$removed] as $companyId) {
                if (! in_array($companyId, $mine, true)) {
                    $problems[] = ($names[$companyId] ?? 'A company').' is outside your access.';
                }
            }
        }
        foreach ([...$added, ...$removed] as $companyId) {
            if (! $actor->hasPermission($type->permission, $companyId) || ($sharing && ! $actor->hasPermission(self::SHARE_PERMISSION, $companyId))) {
                $problems[] = 'Your roles do not let you manage '.$type->plural().' for '.($names[$companyId] ?? 'that company').'.';
            }
        }

        return array_values(array_unique($problems));
    }

    private static function date(CarbonInterface|string|null $on): string
    {
        return $on === null ? today()->toDateString() : Carbon::parse($on)->toDateString();
    }
}

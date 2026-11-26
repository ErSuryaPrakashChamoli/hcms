<?php

namespace App\Domain\Configuration\Applicability;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Cloning\CloneScope;
use App\Domain\Configuration\Models\ConfigurationApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicabilityCompany;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
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
 *
 * The companies are chosen in "Applies to" only: a company-owned record's own company follows that choice (it moves to
 * one of the chosen companies when it leaves its own), unless something it sits in fixes it (a location's establishment
 * or parent branch). What it uses (a parent department, a cost centre, …) can be brought along to the companies it does
 * not reach yet, in the same transaction, each with its own audit event.
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
     * @return array{explicit: bool, mode: string, companies: list<int>, company: ?int, include_future: bool, effective_from: string, with_dependencies: bool}
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
            'with_dependencies' => true,
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
        // Checked as of the later of the start date and today: a backdated entry is judged by what the company may use now,
        // and a future one by what it may use then.
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

    /**
     * Back-dating (owner, 2026-11-26: "by default today's date, but the calendar can pick a previous date … don't keep it
     * open for any back dated days, it must meet the compliances"): the earliest day a change to where a record applies
     * may start, for the companies it touches (those joining or leaving). Best practice for effective-dated configuration
     * in payroll systems, chosen here:
     *
     * - at most back to the start of the previous payroll month of each company (its own pay cycle: calendar months, or
     *   e.g. the 25th to the 24th), so a change can still correct the month that just ended before its payroll is run;
     * - never into a month whose payroll is finalized or paid, or whose attendance is closed, for any of those companies:
     *   payslips, statutory returns and closed attendance are never changed after the event (then it starts in the
     *   current month, or today when the current month is already closed);
     * - never before the last change already made to where the record applies, so its history stays in order.
     *
     * Payroll and attendance already worked out for open days keep their results until the month is run again. The payroll
     * months are BackdatingWindow's (shared with "Company values…"); the history rule is this record's own.
     *
     * @param  list<int>  $companyIds
     * @return array{date: string, reason: string} the earliest day (Y-m-d, today at the latest) and what sets it
     */
    public function earliestStart(Model $record, array $companyIds): array
    {
        // History stays in order: never before the last change already made to where it applies (or one already planned).
        $history = null;
        $header = $record->exists ? $this->header($this->type($record), (int) $record->getKey()) : null;
        if ($header !== null) {
            $last = $header->companies()->get(['effective_from', 'effective_to'])
                ->flatMap(fn (ConfigurationApplicabilityCompany $row) => array_filter([$row->effective_from?->toDateString(), $row->effective_to?->copy()->addDay()->toDateString()]))
                ->max();
            if ($last !== null) {
                $history = ['date' => $last, 'reason' => $last > today()->toDateString()
                    ? 'a change to where it applies is already planned from '.Carbon::parse($last)->format('j M Y')
                    : 'where it applies last changed on '.Carbon::parse($last)->format('j M Y')];
            }
        }

        return BackdatingWindow::later(app(BackdatingWindow::class)->for($companyIds), $history);
    }

    /** earliestStart() for a choice made on the "Applies to…" screen, before its date is picked (the calendar's first day). */
    public function earliestStartFor(Model $record, ApplicabilitySelection $selection): array
    {
        $type = $this->type($record);
        $header = $record->exists ? $this->header($type, (int) $record->getKey()) : null;
        $before = ! $record->exists ? [] : ($header === null ? $this->earlierReach($type, $record) : $this->companiesOn($header, today()->toDateString()));
        $all = $this->allCompanyIds();
        $blockers = [];
        $after = $this->normalise($type, $record, $selection, $all, $this->companyNames(), $blockers);
        $beforeList = $before ?? $all;
        $touched = array_values(array_unique([...array_diff($after, $beforeList), ...array_diff($beforeList, $after)]));

        return $this->earliestStart($record, $touched !== [] ? $touched : $after);
    }

    /** Works out what a change would do, without writing anything (the review step). */
    public function plan(Model $record, ApplicabilitySelection $selection, ?User $actor): ApplicabilityPlan
    {
        $visited = [];

        return $this->planWith($record, $selection, $actor, $visited);
    }

    /** @param  array<string, true>  $visited  records already planned in this change (type:id), so nothing is brought along twice */
    private function planWith(Model $record, ApplicabilitySelection $selection, ?User $actor, array &$visited): ApplicabilityPlan
    {
        $type = $this->type($record);
        $visited[$type->key.':'.$record->getKey()] = true;
        $header = $this->header($type, (int) $record->getKey());
        $from = $selection->effectiveFrom !== null ? Carbon::parse($selection->effectiveFrom)->toDateString() : today()->toDateString();
        // A record not saved yet (an import's preview) reaches nobody: everything chosen is joining, nothing is leaving.
        $before = ! $record->exists ? [] : ($header === null ? $this->earlierReach($type, $record) : $this->companiesOn($header, $from));
        $all = $this->allCompanyIds();
        $names = $this->companyNames();

        $blockers = [];
        $ownerAfter = null;
        $after = $this->normalise($type, $record, $selection, $all, $names, $blockers, $ownerAfter);
        $beforeList = $before ?? $all;
        $added = array_values(array_diff($after, $beforeList));
        $removed = array_values(array_diff($beforeList, $after));
        sort($added);
        sort($removed);

        // Back-dating (2026-11-26): only as far as the payroll months still open for the companies it touches, and never
        // before the last change to where it applies (earliestStart()).
        $earliest = null;
        if ($from < today()->toDateString()) {
            $earliest = $this->earliestStart($record, $added !== [] || $removed !== [] ? [...$added, ...$removed] : $after);
            if ($from < $earliest['date']) {
                $blockers[] = 'It can start on '.Carbon::parse($earliest['date'])->format('j M Y').' at the earliest: '.$earliest['reason'].'. Choose that day or a later one.';
            }
        }
        $blockers = [...$blockers, ...$this->authorisation($type, $actor, $before, $after, $added, $removed, $selection, $names)];
        $warnings = [];
        $extendable = [];
        $extensions = [];
        if ($blockers === []) {
            [$depBlockers, $depWarnings, $extendable] = $this->dependencies->check($type, $record, $after, $removed, $from, $selection->includeFuture, $names, $selection->withDependencies);
            $blockers = [...$blockers, ...$depBlockers];
            $warnings = $depWarnings;
            // Bring along what it uses to the companies it does not reach yet (planned the same way, so their own gaps and
            // rules count too).
            foreach ($selection->withDependencies ? $extendable : [] as $gap) {
                if (isset($visited[$gap['type'].':'.$gap['id']])) {
                    continue;
                }
                $targetType = $this->types->for($gap['type']);
                $target = $targetType->model::query()->withoutGlobalScope(AccessScope::class)->find($gap['id']);
                if ($target === null) {
                    continue;
                }
                $extension = $this->planWith($target, $this->extensionSelection($target, $gap['missing'], $from), $actor, $visited);
                foreach ($extension->blockers as $problem) {
                    $blockers[] = "To bring along the {$gap['typeLabel']} \"{$gap['label']}\": {$problem}";
                }
                $extensions[] = $extension;
            }
        }
        if ($selection->includeFuture) {
            $warnings[] = 'Companies created later will use this '.strtolower($type->label).' automatically, from the day they are created.';
        }

        $selection = new ApplicabilitySelection($selection->mode, $after, $selection->includeFuture && $selection->mode === ApplicabilityMode::All, $from);

        return new ApplicabilityPlan($type->key, (int) $record->getKey(), $type->describe($record), $header !== null, $header?->mode, $before,
            $selection, $after, $added, $removed, $from, array_values(array_unique($blockers)), array_values(array_unique($warnings)), $names,
            $extendable, $extensions, $ownerAfter, $type->label, $earliest['date'] ?? null);
    }

    /** A record brought along keeps what it applies to and adds the missing companies ("All entities" stays all). */
    private function extensionSelection(Model $target, array $missing, string $from): ApplicabilitySelection
    {
        $type = $this->type($target);
        $header = $this->header($type, (int) $target->getKey());
        if ($header !== null && ApplicabilityMode::tryFrom($header->mode instanceof ApplicabilityMode ? $header->mode->value : (string) $header->mode) === ApplicabilityMode::All) {
            return ApplicabilitySelection::all((bool) $header->include_future, $from)->bringingAlong();
        }
        $companies = array_values(array_unique([...($this->reach($target, $from) ?? []), ...array_map('intval', $missing)]));
        sort($companies);

        return ApplicabilitySelection::selected($companies, $from)->bringingAlong();
    }

    /** Plans and makes the change: one transaction, one audit event. Refuses when the plan has blockers. */
    public function apply(Model $record, ApplicabilitySelection $selection, ?User $actor, ?string $reason = null): ApplicabilityPlan
    {
        $plan = $this->plan($record, $selection, $actor);
        if ($plan->blocked()) {
            throw ApplicabilityRefusedException::because("{$plan->label} cannot be applied that way.", $plan->blockers);
        }
        // A back-dated change says why, in the audit trail (compliance: a change to days already lived is explained).
        if ($plan->backdated() && blank($reason)) {
            throw ApplicabilityRefusedException::because("{$plan->label} cannot be applied that way.", ['A back-dated change needs a reason.']);
        }
        $type = $this->type($record);

        DB::transaction(function () use ($plan, $type, $record, $actor, $reason) {
            $done = [];
            $this->writeExtensions($plan, $actor, $reason, $done);
            $this->write($plan, $type, $record, $actor, $reason);
        });
        self::flushMemo();

        return $plan;
    }

    /** @param  array<string, true>  $done */
    private function writeExtensions(ApplicabilityPlan $plan, ?User $actor, ?string $reason, array &$done): void
    {
        foreach ($plan->extensions as $extension) {
            if (isset($done[$extension->type.':'.$extension->recordId])) {
                continue;
            }
            $done[$extension->type.':'.$extension->recordId] = true;
            $this->writeExtensions($extension, $actor, $reason, $done);
            $type = $this->types->for($extension->type);
            $target = $type->model::query()->withoutGlobalScope(AccessScope::class)->findOrFail($extension->recordId);
            $this->write($extension, $type, $target, $actor, mb_substr('Brought along with '.strtolower($plan->typeLabel ?: 'record')." \"{$plan->label}\"".($reason !== null && $reason !== '' ? ": {$reason}" : ''), 0, 500));
        }
    }

    /** Writes one planned change: the company rows, the owner's move when it leaves its own company, one audit event. */
    private function write(ApplicabilityPlan $plan, ConfigurationType $type, Model $record, ?User $actor, ?string $reason): void
    {
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

        // A company-owned record that leaves its own company moves to one it now applies to (its own audit event).
        if ($plan->ownerAfter !== null && $type->ownerColumn !== null && (int) $record->getAttribute($type->ownerColumn) !== $plan->ownerAfter) {
            SharedConfigurationGuard::suspend(function () use ($record, $type, $plan, $reason) {
                try {
                    (method_exists($record, 'withAuditReason') ? $record->withAuditReason($reason) : $record)
                        ->forceFill([$type->ownerColumn => $plan->ownerAfter])->save();
                } catch (InvalidHierarchyException $problem) {
                    throw ApplicabilityRefusedException::because("{$plan->label} cannot be applied that way.", [$problem->getMessage()]);
                }
            });
        }
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

    /**
     * What fixes a company-owned record's company, when anything does: 'its establishment "Mysuru"' (the type's
     * 'owner_from' columns, filled on the record).
     */
    public function ownerFixedBy(ConfigurationType $type, Model $record): ?string
    {
        foreach ($type->ownerFrom as $column => $model) {
            $id = $record->getAttribute($column);
            if (filled($id) && ($found = $model::query()->withoutGlobalScope(AccessScope::class)->find((int) $id)) !== null) {
                $name = (string) ($found->getAttribute('name') ?? '');

                return 'its '.strtolower(str_replace('_', ' ', preg_replace('/_id$/', '', $column) ?? $column)).($name !== '' ? " \"{$name}\"" : '');
            }
        }

        return null;
    }

    /**
     * The company a new company-owned record belongs to, from what was chosen in "Applies to" (its company is never asked
     * for separately): what it sits in (a location's establishment or parent branch) decides first; then the one company,
     * or the first of the companies chosen; none for "All entities" (it applies to every company), unless the type needs
     * one.
     *
     * @param  array<string, mixed>  $values  the record's other fields (columns of the type's 'owner_from')
     */
    public function ownerFor(ConfigurationType $type, ApplicabilitySelection $selection, array $values = []): ?int
    {
        if ($type->ownerColumn === null) {
            return null;
        }
        foreach ($type->ownerFrom as $column => $model) {
            if (filled($values[$column] ?? null)) {
                $owner = $model::query()->withoutGlobalScope(AccessScope::class)->whereKey((int) $values[$column])->value($type->ownerColumn);
                if ($owner !== null) {
                    return (int) $owner;
                }
            }
        }
        $companies = array_values(array_intersect(array_map('intval', $selection->companyIds), $this->allCompanyIds()));

        return match ($selection->mode) {
            ApplicabilityMode::Single, ApplicabilityMode::Selected => $companies === [] ? null : ($selection->mode === ApplicabilityMode::Single ? $companies[0] : min($companies)),
            ApplicabilityMode::All => $type->ownerRequired && ($ids = $this->allCompanyIds()) !== [] ? min($ids) : null,
        };
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
     * @param  int|null  $ownerAfter  set when a company-owned record moves to another of its companies
     * @return list<int>
     */
    private function normalise(ConfigurationType $type, Model $record, ApplicabilitySelection $selection, array $all, array $names, array &$blockers, ?int &$ownerAfter = null): array
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
        $after = array_values(array_unique(array_map('intval', $after)));
        sort($after);
        if ($owner !== null && $after !== [] && ! in_array($owner, $after, true)) {
            // The company is chosen in "Applies to": the record moves to the company chosen (the first, when several) —
            // unless something it sits in fixes its company (a location's establishment or parent branch).
            $fixedBy = $this->ownerFixedBy($type, $record);
            if ($fixedBy !== null) {
                $blockers[] = 'This '.strtolower($type->label).' takes its company from '.$fixedBy.', so it always applies to '.($names[$owner] ?? 'its company').'. Change that first, or clone the record for the others.';
            } else {
                $ownerAfter = $selection->mode === ApplicabilityMode::Single ? $after[0] : min($after);
            }
        }

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

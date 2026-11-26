<?php

namespace App\Filament\Support\Applicability;

use App\Domain\Configuration\Applicability\ApplicabilityMode;
use App\Domain\Configuration\Applicability\ApplicabilityPlan;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationType;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Cloning\CloneRegistry;
use App\Domain\Configuration\Models\ConfigurationLineage;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Filament\Support\Cloning\CloneAction;
use App\Filament\Support\FormWizard;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The applicability building blocks every registered configuration screen gets (Part 8 of the applicability requirement),
 * added centrally by the shared list, manage, create and edit pages, so every module shows the same thing:
 *
 * - create forms: the "Applies to" step (All entities / Selected entities / Current entity only, and companies created
 *   later), checked before the record is created and saved after it. It is the only place companies are chosen: a
 *   company-owned record (department, location, …) takes its company from it (a hidden field), and what the record uses
 *   (its parent, cost centre, …) is brought along to companies it does not reach yet, shown before creating;
 * - edit forms: what the record applies to today, with the actions to change it, give a company its own values, or clone;
 * - lists: "Applies to", "Company values" and "Copied from" columns; filters by entity and by shared / single; the row
 *   actions Applies to…, Company values… and Clone.
 */
final class ApplicabilityUi
{
    public static function type(?string $model): ?ConfigurationType
    {
        return $model === null ? null : app(ConfigurationTypes::class)->forModel($model);
    }

    /**
     * Adds the applicability section to a registered type's form: the selector when creating, what it applies to (with the
     * actions) when editing; in a side drawer (manage pages) only the selector, as the list rows carry the actions.
     *
     * @param  'create'|'edit'|'manage'  $page
     */
    public static function extendForm(Schema $schema, ?string $model, string $page): Schema
    {
        // A quick-edit drawer (FormWizard::wants() false: one short screen) stays one screen; its records keep their usual
        // reach and the list row's "Applies to…" changes it later.
        if ($page === 'manage' && ! FormWizard::wants($schema)) {
            return $schema;
        }
        $section = $model === null ? null : match ($page) {
            'create', 'manage' => self::createSection($model),
            default => self::editSection($model),
        };
        if ($section === null) {
            return $schema;
        }
        $owner = $page === 'edit' ? null : self::ownerField($model);

        return $schema->components(array_values(array_filter([...$schema->getComponents(withHidden: true), $section, $owner])));
    }

    /**
     * A company-owned record's company on create, worked out from "Applies to" (and what the record sits in), never asked
     * for separately. Editing leaves it alone: "Applies to…" moves it when the record leaves its company.
     */
    public static function ownerField(string $model): ?Hidden
    {
        $type = self::type($model);
        if ($type === null || ! $type->share || $type->ownerColumn === null) {
            return null;
        }

        return Hidden::make($type->ownerColumn)
            ->dehydrated(fn (string $operation) => $operation === 'create')
            ->dehydrateStateUsing(fn (Get $get) => app(EntityApplicability::class)->ownerFor($type,
                ApplicabilitySelection::fromState((array) ($get('entity_applicability') ?? [])), self::linkValues($type, $get)));
    }

    /** The "Applies to" section of a create form (null when the model is not a registered type). */
    public static function createSection(string $model): ?Component
    {
        $type = self::type($model);
        if ($type === null || ! $type->share) {
            return null;
        }

        return Section::make('Applies to')
            ->description('Which companies use this '.strtolower($type->label).'. One record can serve several companies; each company can still change some values for itself later.')
            // Its own state, never part of the record's attributes: saved through the applicability service after create.
            ->statePath('entity_applicability')
            ->dehydrated(false)
            ->schema([
                ...self::selectorFields($type, forCreate: true),
                // What creating it this way also does: the parent, cost centre, … it uses are brought along to the companies
                // chosen.
                Text::make(fn (Get $get, mixed $livewire) => self::broughtAlongNote($type, $get, $livewire))
                    ->visible(fn (Get $get, mixed $livewire) => self::broughtAlongNote($type, $get, $livewire) !== null),
            ])
            ->visible(fn (string $operation) => $operation === 'create')
            ->saveRelationshipsUsing(function (Model $record, Get $get) use ($type) {
                self::saveOnCreate($type, $record, (array) ($get('entity_applicability') ?? []));
            });
    }

    /** What the record applies to, on its edit form, with the actions next to it. */
    public static function editSection(string $model): ?Component
    {
        $type = self::type($model);
        if ($type === null || ! $type->share) {
            return null;
        }

        return Section::make('Applies to')
            ->description('Which companies use this record. Change it here; each change is reviewed first and recorded in the audit trail.')
            ->schema([
                Text::make(fn (?Model $record) => $record?->exists ? app(EntityApplicability::class)->summary($record) : '—'),
            ])
            ->headerActions(array_values(array_filter([
                ManageApplicabilityAction::make($type->key, 'changeApplicability'),
                $type->supportsOverrides() ? CompanyValuesAction::make($type->key, 'companyValues') : null,
                $type->supportsCloning() && app(CloneRegistry::class)->has($type->clone) ? CloneAction::make($type->clone, 'cloneRecord') : null,
            ])))
            ->visible(fn (string $operation) => $operation === 'edit');
    }

    /**
     * The selector itself (also used by the "Applies to…" action).
     *
     * @return list<Component>
     */
    public static function selectorFields(ConfigurationType $type, bool $forCreate = false): array
    {
        $user = auth()->user();
        $limited = $user instanceof User ? app(EntityApplicability::class)->companiesFor($user) : null;
        $modes = collect(ApplicabilityMode::cases())->mapWithKeys(fn (ApplicabilityMode $m) => [$m->value => $m->label()])->all();
        $descriptions = collect(ApplicabilityMode::cases())->mapWithKeys(fn (ApplicabilityMode $m) => [$m->value => $m->description()])->all();
        if ($limited !== null) {
            unset($modes[ApplicabilityMode::All->value]);
        }

        $fields = [
            Radio::make('mode')->label('Applies to')->options($modes)->descriptions($descriptions)->required()->live()
                ->default($limited === null ? ApplicabilityMode::All->value : (count($limited) === 1 ? ApplicabilityMode::Single->value : ApplicabilityMode::Selected->value))
                // Creating: checked before the record is created (what it uses, what it sits in, the person's access).
                ->rules($forCreate ? [fn (Get $get, mixed $livewire): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($type, $get, $livewire): void {
                    if ($type->ownerRequired && self::createOwner($type, $get) === null) {
                        $fail('A '.strtolower($type->label).' always belongs to a company: choose one, or add a company first.');

                        return;
                    }
                    $plan = self::createPlan($type, $get, $livewire);
                    if ($plan !== null && $plan->blocked()) {
                        $fail(implode(' ', $plan->blockers));
                    }
                }] : []),
            CheckboxList::make('companies')->label('Companies')->options(fn () => self::companyOptions($limited))->columns(2)->bulkToggleable()
                ->default($limited ?? [])->live()
                ->visible(fn (Get $get) => $get('mode') === ApplicabilityMode::Selected->value)
                ->required(fn (Get $get) => $get('mode') === ApplicabilityMode::Selected->value),
            Select::make('company')->label('Company')->options(fn () => self::companyOptions($limited))->native(false)->live()
                ->default($limited !== null && count($limited) === 1 ? $limited[0] : null)
                ->visible(fn (Get $get) => $get('mode') === ApplicabilityMode::Single->value)
                ->required(fn (Get $get) => $get('mode') === ApplicabilityMode::Single->value),
            Toggle::make('include_future')->label('Include companies created later automatically')
                ->helperText('New companies start using this record from the day they are created. Leave off to add them yourself.')
                ->default($limited === null)->live()
                ->visible(fn (Get $get) => $get('mode') === ApplicabilityMode::All->value),
        ];

        return $fields;
    }

    /**
     * The record's links that decide or depend on its companies (references, and what fixes a company-owned record's
     * company), read from the rest of the create form.
     *
     * @return array<string, mixed>
     */
    private static function linkValues(ConfigurationType $type, Get $get, string $prefix = ''): array
    {
        $columns = array_unique([...array_filter(array_keys($type->references), fn (string $c) => ! str_contains($c, '.')), ...array_keys($type->ownerFrom)]);
        $values = [];
        foreach ($columns as $column) {
            $value = $get($prefix.$column);
            $values[$column] = blank($value) ? null : $value;
        }

        return $values;
    }

    /** @var \WeakMap<object, array<string, ApplicabilityPlan>>|null plans of a create form, per component instance (one request) */
    private static ?\WeakMap $createPlans = null;

    /** @return array{mode: mixed, companies: array<mixed>, company: mixed, include_future: bool} the selector's state, read from inside it */
    private static function selectorState(Get $get): array
    {
        return ['mode' => $get('mode'), 'companies' => (array) ($get('companies') ?? []), 'company' => $get('company'), 'include_future' => (bool) $get('include_future')];
    }

    private static function createOwner(ConfigurationType $type, Get $get): ?int
    {
        return app(EntityApplicability::class)->ownerFor($type, ApplicabilitySelection::fromState(self::selectorState($get)), self::linkValues($type, $get, '../'));
    }

    /**
     * Whether the choice means what the record means anyway (every company for a tenant-wide record, its own company for
     * a company's record): nothing is written for it, so nothing is checked (saveOnCreate()).
     */
    private static function keepsUsualReach(ConfigurationType $type, Model $record, ApplicabilitySelection $selection): bool
    {
        $service = app(EntityApplicability::class);
        $earlier = $service->earlierReach($type, $record);

        return (! $record->exists || ! $service->isExplicit($record)) && (
            ($earlier === null && $selection->mode === ApplicabilityMode::All && $selection->includeFuture)
            || ($earlier !== null && $selection->mode === ApplicabilityMode::Single && $selection->companyIds === $earlier)
        );
    }

    /**
     * What creating the record with this choice would do, from the create form (the selector's own state and the
     * record's links), bringing along what it uses; null before a choice is made, or when the choice keeps the record's
     * usual reach (nothing is written then). Worked out once per request and form state (the note and the check ask).
     */
    public static function createPlan(ConfigurationType $type, Get $get, mixed $livewire = null): ?ApplicabilityPlan
    {
        $state = self::selectorState($get);
        if (! filled($state['mode'])) {
            return null;
        }
        $values = self::linkValues($type, $get, '../');
        $key = $type->key.':'.md5((string) json_encode([$state, $values]));
        self::$createPlans ??= new \WeakMap;
        if (is_object($livewire) && isset(self::$createPlans[$livewire][$key])) {
            return self::$createPlans[$livewire][$key];
        }
        $service = app(EntityApplicability::class);
        $selection = ApplicabilitySelection::fromState($state)->bringingAlong();
        /** @var Model $probe */
        $probe = new $type->model;
        $probe->forceFill(array_filter($values, fn ($v) => $v !== null));
        if ($type->ownerColumn !== null) {
            $probe->setAttribute($type->ownerColumn, $service->ownerFor($type, $selection, $values));
        }
        $user = auth()->user();
        $plan = self::keepsUsualReach($type, $probe, $selection) ? null : $service->plan($probe, $selection, $user instanceof User ? $user : null);
        if (is_object($livewire) && $plan !== null) {
            self::$createPlans[$livewire] = [...(self::$createPlans[$livewire] ?? []), $key => $plan];
        }

        return $plan;
    }

    private static function broughtAlongNote(ConfigurationType $type, Get $get, mixed $livewire = null): ?HtmlString
    {
        $lines = self::createPlan($type, $get, $livewire)?->broughtAlong() ?? [];
        if ($lines === []) {
            return null;
        }

        return new HtmlString('<span class="font-medium">Also applies what it uses:</span><ul class="list-disc ps-5">'
            .implode('', array_map(fn (string $line) => '<li>'.e($line).'</li>', $lines)).'</ul>');
    }

    /** @param  list<int>|null  $limited  @return array<int, string> */
    public static function companyOptions(?array $limited = null): array
    {
        return Company::query()->withoutGlobalScope(AccessScope::class)->where('status', ActiveStatus::Active->value)
            ->when($limited !== null, fn ($q) => $q->whereKey($limited))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * After create: the companies chosen. A choice that means what the record means anyway (every company for a
     * tenant-wide record, its own company for a company's record) is not written, so nothing changes for it.
     *
     * @param  array<string, mixed>  $state
     */
    public static function saveOnCreate(ConfigurationType $type, Model $record, array $state): void
    {
        if ($state === [] || ! filled($state['mode'] ?? null)) {
            return;
        }
        $service = app(EntityApplicability::class);
        $selection = ApplicabilitySelection::fromState($state);
        $owner = $type->ownerColumn === null ? null : $record->getAttribute($type->ownerColumn);
        if ($selection->mode === ApplicabilityMode::Single && $owner !== null) {
            $selection = ApplicabilitySelection::single((int) $owner);
        }
        if (self::keepsUsualReach($type, $record, $selection)) {
            return;
        }
        try {
            // What it uses (its parent, cost centre, …) comes along to the companies chosen, as the form showed before creating.
            $plan = $service->apply($record, $selection->bringingAlong(), auth()->user() instanceof User ? auth()->user() : null, 'Chosen when the record was created');
        } catch (ApplicabilityRefusedException $refused) {
            Notification::make()->warning()->title('Created, but the companies were not applied')
                ->body($refused->getMessage().' It applies to '.$service->summary($record).' for now; use "Applies to…" to change it.')->persistent()->send();

            return;
        }
        if (($lines = $plan->broughtAlong()) !== []) {
            Notification::make()->info()->title('Also applied what it uses')->body(implode(' ', $lines))->send();
        }
    }

    /** Adds the columns, filters and row actions of a registered type to its list. */
    public static function decorateTable(Table $table, ?string $model): Table
    {
        $type = self::type($model);
        if ($type === null) {
            return $table;
        }
        $applicability = app(EntityApplicability::class);
        $columns = [];
        $filters = [];
        if ($type->share) {
            $columns[] = TextColumn::make('cfg_applies_to')->label('Applies to')
                ->state(fn (Model $record) => $applicability->summary($record))
                ->badge()->color(fn (Model $record) => $applicability->kind($record) === 'shared' ? 'info' : 'gray')
                ->wrap()->toggleable();
            $filters[] = SelectFilter::make('cfg_entity')->label('Entity')->options(fn () => self::companyOptions())
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $applicability->constrain($query, (int) $data['value']) : $query);
            $filters[] = SelectFilter::make('cfg_applicability')->label('Applicability')->options(['shared' => 'Shared (several or all entities)', 'single' => 'One entity only'])
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $applicability->constrainKind($query, (string) $data['value']) : $query);
        }
        if ($type->supportsOverrides()) {
            $columns[] = TextColumn::make('cfg_company_values')->label('Company values')
                ->state(fn (Model $record) => self::overriddenIn($type, $record))
                ->placeholder('Shared values')->badge()->color('warning')->toggleable();
        }
        if ($type->supportsCloning()) {
            $columns[] = TextColumn::make('cfg_copied_from')->label('Copied from')
                ->state(fn (Model $record) => self::copiedFrom($type, $record))->placeholder('—')->toggleable(isToggledHiddenByDefault: true);
        }
        $table->pushColumns($columns)->pushFilters($filters);

        $existing = collect($table->getRecordActions())->flatMap(fn ($a) => $a instanceof ActionGroup ? $a->getActions() : [$a])
            ->map(fn ($a) => $a instanceof Action ? $a->getName() : null)->filter()->all();
        $actions = array_values(array_filter([
            $type->share ? ManageApplicabilityAction::make($type->key) : null,
            $type->supportsOverrides() ? CompanyValuesAction::make($type->key) : null,
            $type->supportsCloning() && ! in_array('clone', $existing, true) && app(CloneRegistry::class)->has($type->clone) ? CloneAction::make($type->clone) : null,
        ]));
        if ($actions !== []) {
            $table->pushRecordActions([ActionGroup::make($actions)->label('Companies')->icon(Heroicon::OutlinedBuildingOffice2)->color('gray')->button()->size('sm')]);
        }

        return $table;
    }

    private static function overriddenIn(ConfigurationType $type, Model $record): ?string
    {
        $overrides = app(ConfigurationOverrides::class);
        if (! $overrides->hasAny($type->key, (int) $record->getKey())) {
            return null;
        }
        $names = app(EntityApplicability::class)->companyNames();
        $companies = [];
        foreach (array_keys($names) as $companyId) {
            if ($overrides->valuesFor($type->key, (int) $record->getKey(), $companyId) !== []) {
                $companies[] = $names[$companyId];
            }
        }

        return $companies === [] ? null : 'Own values: '.implode(', ', $companies);
    }

    private static function copiedFrom(ConfigurationType $type, Model $record): ?string
    {
        $sourceId = ConfigurationLineage::query()->where('adapter', $type->clone)->where('role', ConfigurationLineage::ROOT)->where('target_id', $record->getKey())->value('source_id');
        $source = $sourceId === null ? null : $type->model::query()->withoutGlobalScope(AccessScope::class)->find($sourceId);

        return $source === null ? null : $type->describe($source);
    }
}

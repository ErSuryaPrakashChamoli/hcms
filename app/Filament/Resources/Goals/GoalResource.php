<?php

namespace App\Filament\Resources\Goals;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Performance\Services\PerformanceMasters;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Support\GoalFieldRules;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Goals, OKRs, KRAs (§34) with cascade and key results. HROne setup parity: perspective, unit of measurement and key
 * result due dates (HP-8a), reverse KPIs and the KPI rating formula (HP-8c); labels follow the performance terminology.
 */
class GoalResource extends Resource
{
    protected static ?string $model = Goal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Goals';

    protected static ?int $navigationSort = 15;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'organisationNode.nodeable', 'parent', 'cycle', 'keyResults', 'perspective']);
        $user = auth()->user();

        if ($user->can('performance.view')) {
            return $query;
        }
        $me = EmployeeOwnedPolicy::employeeOf($user);
        $reports = $me && $user->can('performance.team') ? app(PerformanceRelationships::class)->reportIds($me) : collect();

        // Organisation-level goals are visible to everyone; personal goals to owner and manager.
        return $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', $reports));
    }

    public static function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $me = EmployeeOwnedPolicy::employeeOf($user);
        $canAssign = $user->can('performance.manage') || $user->can('performance.team');
        $settings = app(SettingsRepository::class);
        $masters = app(PerformanceMasters::class);
        $showReverse = (bool) $settings->get('performance.review.show_reverse_rating', false);
        $mayAddUnits = $user->can('performance.manage') || ($masters->employeesMayAddUnits() && $user->can('performance.goals'));
        $owner = fn (Get $get) => $get('employee_id') ? Employee::query()->find($get('employee_id')) : null;
        $unitOptions = fn (Get $get, ?Goal $record) => $masters->unitOptions($owner($get), $user, $record?->unit_id);
        $addUnit = $mayAddUnits ? [
            TextInput::make('name')->label('Label')->required()->maxLength(64),
            Select::make('measure_type')->label('Progress is calculated as')->options(config('peopleos.performance.measure_types'))->placeholder('None'),
        ] : null;
        $hasFormulas = KpiRatingFormula::query()->where('status', 'active')->exists();
        // Without any unit of measurement (and no right to add one) the form keeps its free-text unit, as before.
        $withUnits = $mayAddUnits || PerformanceUnit::query()->where('status', 'active')->exists();
        $freeUnitLabel = $withUnits ? 'Other '.mb_strtolower(PerformanceLabels::get('uom')) : PerformanceLabels::get('uom');
        // HP-8d: the goal sheet policy of the goal's owner — field rights for this user, mandatory UOM and target, equal
        // weightage set by the system. Worked out once per owner and goal while the form is built.
        $memo = [];
        $rights = function (Get $get, ?Goal $record) use (&$memo, $user): GoalFieldRules {
            $level = $get('level') ?? $record?->level ?? 'employee';
            $ownerId = (int) ($get('employee_id') ?: $record?->employee_id);
            if ($level !== 'employee' || $ownerId === 0) {
                return GoalFieldRules::unrestricted();
            }

            return $memo["r{$ownerId}:".($record?->id ?? 0)] ??= GoalFieldRules::for(Employee::query()->find($ownerId), $user, $record);
        };
        $sheet = function (callable $get, ?Goal $record, string $key) use (&$memo): mixed {
            $ownerId = (int) ($get('employee_id') ?: $record?->employee_id);
            if (($get('level') ?? 'employee') !== 'employee' || $ownerId === 0) {
                return null;
            }
            $memo["p{$ownerId}"] ??= [app(GoalSheetRules::class)->policyFor(Employee::query()->find($ownerId))];

            return GoalSheetRules::answer($memo["p{$ownerId}"][0], $key);
        };
        // Owner decision 2026-10-29: on a system-mapped goal sheet the employee's changes are proposals the reporting manager
        // confirms, so the goal plan sets the status (the service enforces it whatever the form sends).
        $proposes = function (Get $get, ?Goal $record) use (&$memo, $user): bool {
            $ownerId = (int) ($get('employee_id') ?: $record?->employee_id);
            $cycleId = $get('performance_cycle_id') ?: $record?->performance_cycle_id;
            if (($get('level') ?? $record?->level ?? 'employee') !== 'employee' || $ownerId === 0) {
                return false;
            }

            return $memo["m{$ownerId}:{$cycleId}"] ??= app(Goals::class)->proposes(Employee::query()->find($ownerId), $cycleId, $user);
        };
        $unitAndTarget = fn (Get $get, ?Goal $record) => in_array($sheet($get, $record, 'unit_and_target_required'), [true, 1, '1'], true);
        $equalWeights = fn (Get $get, ?Goal $record) => $sheet($get, $record, 'goal_weight_mode') === 'equal' && filled($get('performance_cycle_id'));

        return $schema->components([
            Section::make('Goal')->columns(3)->schema([
                Select::make('level')->options(config('peopleos.performance.goal_levels'))->default('employee')->required()->live()
                    ->disabled(fn () => ! $user->can('performance.manage'))->dehydrated(),
                Select::make('employee_id')->label('Employee')->searchable()
                    ->visible(fn (Get $get) => $get('level') === 'employee')
                    ->required(fn (Get $get) => $get('level') === 'employee')
                    ->options(function () use ($me, $canAssign, $user) {
                        $q = Employee::query()->with('person')->employed();
                        if (! $user->can('performance.manage')) {
                            $ids = collect([$me?->id])->merge($canAssign && $me ? app(PerformanceRelationships::class)->reportIds($me) : [])->filter();
                            $q->whereIn('id', $ids);
                        }

                        return $q->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
                    })
                    ->default(fn () => $me?->id),
                Select::make('organisation_node_id')->label('Organisation unit')->searchable()
                    ->visible(fn (Get $get) => $get('level') !== 'employee')
                    ->options(fn () => OrganisationNode::query()->with('nodeable')->get()->mapWithKeys(fn ($n) => [$n->id => $n->auditLabel()])->all()),
                Select::make('parent_id')->label('Aligned to')->searchable()->placeholder('None')
                    ->options(fn () => Goal::query()->with('employee.person')->whereIn('status', ['draft', 'active'])->orderBy('level')->get()->mapWithKeys(fn ($g) => [$g->id => (config("peopleos.performance.goal_levels.{$g->level}") ?? $g->level).': '.$g->title.($g->employee ? ' ('.$g->employee->person?->full_name.')' : '')])->all()),
                Select::make('performance_cycle_id')->label('Cycle')->placeholder('Not tied to a cycle')
                    ->options(fn () => PerformanceCycle::query()->where('status', '!=', 'closed')->orderByDesc('period_start')->pluck('name', 'id')->all())
                    ->default(fn () => PerformanceCycle::query()->where('status', 'active')->orderByDesc('period_start')->value('id')),
                Select::make('type')->options(PerformanceLabels::goalTypes())->default('goal')->required()->live(),
                self::governed(Select::make('kra_id')->label('From '.PerformanceLabels::get('kra').' library')->placeholder('—')->options(fn () => Kra::query()->where('status', 'active')->pluck('name', 'id')->all()), 'kra', $rights, fn (Get $get) => in_array($get('type'), ['kra', 'kpi'], true))->live()
                    // HP-8a: a goal taken from the library takes the KRA's perspective.
                    ->afterStateUpdated(fn (Set $set, $state) => $state && ($kra = Kra::query()->find($state))?->perspective_id ? $set('perspective_id', $kra->perspective_id) : null),
                // HP-8c: pick one of the KRA's suggested KPIs to take its name, target, unit, reverse flag and formula.
                Select::make('library_kpi')->label('Suggested '.PerformanceLabels::get('kpi'))->placeholder('—')->dehydrated(false)->live()
                    ->options(fn (Get $get) => collect(Kra::query()->find($get('kra_id'))?->kpis ?? [])->mapWithKeys(fn ($k, $i) => [$i => $k['name'] ?? '—'])->all())
                    ->visible(fn (Get $get, ?Goal $record) => in_array($get('type'), ['kra', 'kpi'], true) && ! empty(Kra::query()->find($get('kra_id'))?->kpis) && $rights($get, $record)->editable('kra'))
                    ->afterStateUpdated(function (Set $set, Get $get, $state) {
                        $kpi = (Kra::query()->find($get('kra_id'))?->kpis ?? [])[$state] ?? null;
                        if ($kpi === null) {
                            return;
                        }
                        $set('title', $kpi['name'] ?? $get('title'));
                        if (is_numeric($kpi['target'] ?? null)) {
                            $set('target_value', (float) $kpi['target']);
                        }
                        foreach (['unit_id', 'kpi_rating_formula_id'] as $field) {
                            if (! empty($kpi[$field])) {
                                $set($field, $kpi[$field]);
                            }
                        }
                        $set('is_reverse', (bool) ($kpi['is_reverse'] ?? false));
                    }),
                self::governed(Select::make('perspective_id')->label(PerformanceLabels::get('perspective'))->placeholder('None')->native(false)
                    ->options(fn (?Goal $record) => PerformanceLookup::options('perspective', $record?->perspective_id)), 'perspective', $rights),
                self::governed(TextInput::make('title')->maxLength(255)->columnSpan(2), 'title', $rights, required: true),
                self::governed(TextInput::make('weight')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'), 'weight', $rights)
                    ->disabled(fn (Get $get, ?Goal $record) => ! $rights($get, $record)->editable('weight') || $equalWeights($get, $record))
                    ->helperText(fn (Get $get, ?Goal $record) => $equalWeights($get, $record) ? 'Equal weightage: set by the system for every goal of the sheet.' : null),
                self::governed(Textarea::make('description')->rows(2)->columnSpanFull(), 'description', $rights),
            ]),
            Section::make('Measure')->columns(4)->schema([
                self::governed(Select::make('measure_type')->options(config('peopleos.performance.measure_types'))->default('percentage'), 'measure_type', $rights, required: true),
                self::governed(TextInput::make('start_value')->numeric()->default(0), 'start_value', $rights),
                self::governed(TextInput::make('target_value')->label(PerformanceLabels::get('target', 'Target value'))->numeric()->default(100), 'target_value', $rights, required: true),
                // HP-8a: a unit of measurement (its way of calculating progress and default target fill the goal).
                self::governed(Select::make('unit_id')->label(PerformanceLabels::get('uom'))->placeholder('Other (type it)')->native(false)->live(), 'unit', $rights, fn () => $withUnits)
                    ->required(fn (Get $get, ?Goal $record) => ($rights($get, $record)->required('unit') || $unitAndTarget($get, $record)) && blank($get('unit')))
                    ->options($unitOptions)
                    ->default(fn () => $masters->defaultUnit(null, $user)?->id)
                    ->afterStateUpdated(function (Set $set, $state) {
                        $unit = $state ? PerformanceUnit::query()->find($state) : null;
                        if ($unit?->measure_type) {
                            $set('measure_type', $unit->measure_type);
                        }
                        if ($unit?->default_target !== null) {
                            $set('target_value', (float) $unit->default_target);
                        }
                    })
                    ->createOptionForm($addUnit)
                    ->createOptionUsing(fn (array $data) => $masters->addUnitForUser($data['name'], $data['measure_type'] ?? null, auth()->user())->id),
                self::governed(TextInput::make('unit')->label($freeUnitLabel)->maxLength(32)->placeholder('%, INR, tickets…'), 'unit', $rights, fn (Get $get) => blank($get('unit_id')))
                    ->required(fn (Get $get, ?Goal $record) => ($rights($get, $record)->required('unit') || $unitAndTarget($get, $record)) && blank($get('unit_id'))),
                self::governed(DatePicker::make('start_date')->native(false)->default(now()), 'start_date', $rights),
                self::governed(DatePicker::make('due_date')->native(false), 'due_date', $rights),
                // HP-8d: "pending approval" is set only by submitting the goal sheet.
                Select::make('status')->options(fn (?Goal $record) => collect(config('peopleos.performance.goal_statuses'))->except($record?->status === 'pending_approval' ? [] : ['pending_approval'])->all())->default('active')->required()
                    ->disabled($proposes)->helperText(fn (Get $get, ?Goal $record) => $proposes($get, $record) ? 'Your reporting manager owns this goal sheet: your changes wait for their confirmation.' : null),
                Toggle::make('is_reverse')->label('Lower is better (reverse '.PerformanceLabels::get('kpi').')')->inline(false)->visible($showReverse),
                Select::make('kpi_rating_formula_id')->label(PerformanceLabels::get('kpi').' rating formula')->placeholder('The unit\'s formula, if any')->native(false)
                    ->options(fn (?Goal $record) => KpiRatingFormula::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $record?->kpi_rating_formula_id ?? 0))->orderBy('name')->pluck('name', 'id')->all())
                    ->visible($hasFormulas),
            ]),
            Section::make(PerformanceLabels::plural('kpi', 'Key results'))->description('For objectives: progress rolls up from the key results by weight.')
                ->visible(fn (Get $get, ?Goal $record) => $rights($get, $record)->visible('key_results'))->schema([
                    Repeater::make('keyResults')->relationship()->hiddenLabel()->columns(5)->default([])->reorderable()
                        // HP-8d: field rights on KPIs (a disabled repeater is not saved) and "KPIs mandatory".
                        ->disabled(fn (Get $get, ?Goal $record) => ! $rights($get, $record)->editable('key_results'))
                        ->minItems(fn (Get $get, ?Goal $record) => $rights($get, $record)->required('key_results') ? 1 : null)
                        ->schema([
                            TextInput::make('title')->required()->columnSpan(2),
                            Select::make('measure_type')->options(config('peopleos.performance.measure_types'))->default('percentage')->required(),
                            TextInput::make('start_value')->numeric()->default(0),
                            TextInput::make('target_value')->label(PerformanceLabels::get('target', 'Target value'))->numeric()->default(100)->required(),
                            TextInput::make('current_value')->label(PerformanceLabels::get('achievement', 'Current value'))->numeric()->default(0),
                            Select::make('unit_id')->label(PerformanceLabels::get('uom'))->placeholder('Other (type it)')->native(false)->live()->visible($withUnits)
                                ->required(fn (Get $get) => blank($get('unit')) && in_array($sheet(fn (string $k) => $get("../../{$k}"), null, 'unit_and_target_required'), [true, 1, '1'], true))
                                ->options(fn (Get $get, $record) => $masters->unitOptions($get('../../employee_id') ? Employee::query()->find($get('../../employee_id')) : null, $user, $record instanceof KeyResult ? $record->unit_id : null)),
                            TextInput::make('unit')->label($freeUnitLabel)->maxLength(32)->visible(fn (Get $get) => blank($get('unit_id'))),
                            TextInput::make('weight')->numeric()->default(0)->suffix('%'),
                            DatePicker::make('due_date')->label(PerformanceLabels::get('kpi_due_date', 'Due date'))->native(false)
                                ->visible(fn () => (bool) app(SettingsRepository::class)->get('performance.goals.kpi_due_date', false)),
                            Toggle::make('is_reverse')->label('Lower is better')->inline(false)->visible($showReverse),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::keyResultData($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::keyResultData($data)),
                ]),
        ]);
    }

    /**
     * HP-8d: a goal field governed by the goal sheet policy's field rights — hidden, disabled (not saved) or mandatory for
     * this user; $visible is the field's own condition, $required whether it is always mandatory.
     */
    private static function governed(Field $component, string $field, Closure $rights, ?Closure $visible = null, bool $required = false): Field
    {
        return $component
            ->visible(fn (Get $get, ?Goal $record) => ($visible === null || $visible($get)) && $rights($get, $record)->visible($field))
            ->disabled(fn (Get $get, ?Goal $record) => ! $rights($get, $record)->editable($field))
            ->required(fn (Get $get, ?Goal $record) => $required || $rights($get, $record)->required($field));
    }

    /** Key result data from the form: progress from its values, and the unit's code as its unit text (HP-8a). */
    private static function keyResultData(array $data): array
    {
        if (! empty($data['unit_id']) && ($unit = PerformanceUnit::query()->find($data['unit_id']))) {
            $data['unit'] = $unit->code;
        }

        return $data + ['progress' => Goal::progressFor($data['measure_type'] ?? 'percentage', (float) ($data['start_value'] ?? 0), (float) ($data['target_value'] ?? 100), (float) ($data['current_value'] ?? 0))];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('level')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.performance.goal_levels.{$state}", $state)),
                TextColumn::make('title')->searchable()->wrap()->description(fn (Goal $record) => $record->parent ? '↳ '.$record->parent->title : null),
                TextColumn::make('owner')->label('Owner')->state(fn (Goal $record) => $record->employee?->person?->full_name ?? $record->organisationNode?->auditLabel() ?? '—'),
                TextColumn::make('type')->badge()->color('info')->formatStateUsing(fn (string $state) => PerformanceLabels::goalTypes()[$state] ?? $state),
                TextColumn::make('perspective.name')->label(PerformanceLabels::get('perspective'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cycle.name')->label('Cycle')->placeholder('—')->toggleable(),
                TextColumn::make('weight')->suffix('%'),
                TextColumn::make('progress')->suffix('%')->color(fn ($state) => $state >= 70 ? 'success' : ($state >= 40 ? 'warning' : 'danger')),
                TextColumn::make('keyResults')->label(PerformanceLabels::custom('kpi') !== null ? PerformanceLabels::plural('kpi') : 'KRs')->state(fn (Goal $record) => $record->keyResults->count()),
                TextColumn::make('due_date')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success', 'completed' => 'primary', 'cancelled' => 'danger', default => 'gray'
                }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('level')->options(config('peopleos.performance.goal_levels')),
                SelectFilter::make('status')->options(config('peopleos.performance.goal_statuses')),
                SelectFilter::make('performance_cycle_id')->label('Cycle')->relationship('cycle', 'name'),
                // HROne parity GO-1: one employee's goal sheet (the Goal sheets / Goal requests registers open the list on it);
                // for HR and managers, whose list holds other people's goals. The options stay within the goals they may see.
                SelectFilter::make('employee_id')->label('Employee')->searchable()
                    ->visible(fn () => auth()->user()?->can('performance.view') || auth()->user()?->can('performance.team'))
                    ->options(fn () => Employee::query()->with('person')->whereIn('id', self::getEloquentQuery()->whereNotNull('employee_id')->select('employee_id'))
                        ->orderBy('employee_code')->limit(1000)->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
            ])
            ->recordActions([EditAction::make()->visible(fn (Goal $record) => ! $record->is_locked || auth()->user()->can('performance.manage')), ...PerformanceActions::forGoal()]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoals::route('/'),
            'create' => CreateGoal::route('/create'),
            'edit' => EditGoal::route('/{record}/edit'),
        ];
    }
}

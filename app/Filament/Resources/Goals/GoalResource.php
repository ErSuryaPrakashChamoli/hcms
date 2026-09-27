<?php

namespace App\Filament\Resources\Goals;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Goals, OKRs, KRAs (§34) with cascade and key results. */
class GoalResource extends Resource
{
    protected static ?string $model = Goal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Goals';

    protected static ?int $navigationSort = 15;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'organisationNode.nodeable', 'parent', 'cycle', 'keyResults']);
        $user = auth()->user();

        if ($user->can('performance.view')) {
            return $query;
        }
        $me = EmployeeOwnedPolicy::employeeOf($user);
        $reports = $me && $user->can('performance.team') ? $me->directReports()->currentlyEffective()->pluck('employee_id') : collect();

        // Organisation-level goals are visible to everyone; personal goals to owner and manager.
        return $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', $reports));
    }

    public static function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $me = EmployeeOwnedPolicy::employeeOf($user);
        $canAssign = $user->can('performance.manage') || $user->can('performance.team');

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
                            $ids = collect([$me?->id])->merge($canAssign && $me ? $me->directReports()->currentlyEffective()->pluck('employee_id') : [])->filter();
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
                Select::make('type')->options(config('peopleos.performance.goal_types'))->default('goal')->required()->live(),
                Select::make('kra_id')->label('From KRA library')->placeholder('—')->options(fn () => Kra::query()->where('status', 'active')->pluck('name', 'id')->all())
                    ->visible(fn (Get $get) => in_array($get('type'), ['kra', 'kpi'], true)),
                TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
                TextInput::make('weight')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Measure')->columns(4)->schema([
                Select::make('measure_type')->options(config('peopleos.performance.measure_types'))->default('percentage')->required(),
                TextInput::make('start_value')->numeric()->default(0),
                TextInput::make('target_value')->numeric()->default(100)->required(),
                TextInput::make('unit')->maxLength(32)->placeholder('%, INR, tickets…'),
                DatePicker::make('start_date')->native(false)->default(now()),
                DatePicker::make('due_date')->native(false),
                Select::make('status')->options(config('peopleos.performance.goal_statuses'))->default('active')->required(),
            ]),
            Section::make('Key results')->description('For objectives: progress rolls up from the key results by weight.')->schema([
                Repeater::make('keyResults')->relationship()->hiddenLabel()->columns(5)->default([])->reorderable()
                    ->schema([
                        TextInput::make('title')->required()->columnSpan(2),
                        Select::make('measure_type')->options(config('peopleos.performance.measure_types'))->default('percentage')->required(),
                        TextInput::make('start_value')->numeric()->default(0),
                        TextInput::make('target_value')->numeric()->default(100)->required(),
                        TextInput::make('current_value')->numeric()->default(0),
                        TextInput::make('unit')->maxLength(32),
                        TextInput::make('weight')->numeric()->default(0)->suffix('%'),
                    ])
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => $data + ['progress' => Goal::progressFor($data['measure_type'] ?? 'percentage', (float) ($data['start_value'] ?? 0), (float) ($data['target_value'] ?? 100), (float) ($data['current_value'] ?? 0))])
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => $data + ['progress' => Goal::progressFor($data['measure_type'] ?? 'percentage', (float) ($data['start_value'] ?? 0), (float) ($data['target_value'] ?? 100), (float) ($data['current_value'] ?? 0))]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('level')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.performance.goal_levels.{$state}", $state)),
                TextColumn::make('title')->searchable()->wrap()->description(fn (Goal $record) => $record->parent ? '↳ '.$record->parent->title : null),
                TextColumn::make('owner')->label('Owner')->state(fn (Goal $record) => $record->employee?->person?->full_name ?? $record->organisationNode?->auditLabel() ?? '—'),
                TextColumn::make('type')->badge()->color('info')->formatStateUsing(fn (string $state) => config("peopleos.performance.goal_types.{$state}", $state)),
                TextColumn::make('cycle.name')->label('Cycle')->placeholder('—')->toggleable(),
                TextColumn::make('weight')->suffix('%'),
                TextColumn::make('progress')->suffix('%')->color(fn ($state) => $state >= 70 ? 'success' : ($state >= 40 ? 'warning' : 'danger')),
                TextColumn::make('keyResults')->label('KRs')->state(fn (Goal $record) => $record->keyResults->count()),
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

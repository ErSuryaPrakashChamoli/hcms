<?php

namespace App\Filament\Resources\ImprovementPlans;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\ImprovementPlans\Pages\ManageImprovementPlans;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Performance improvement plans (§34). Sensitive: HR, the manager and the employee only. */
class ImprovementPlanResource extends Resource
{
    protected static ?string $model = ImprovementPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Improvement plans';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return auth()->user()->can('performance.manage') || auth()->user()->can('performance.team');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'manager.person']);
        $user = auth()->user();
        if ($user->can('performance.view')) {
            return $query;
        }
        $me = EmployeeOwnedPolicy::employeeOf($user);

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhere('manager_id', $me?->id ?? 0));
    }

    public static function form(Schema $schema): Schema
    {
        $me = PerformanceActions::me();

        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Employee')->required()->searchable()
                ->options(fn () => ($me && ! auth()->user()->can('performance.manage') ? Employee::query()->with('person')->whereIn('id', app(PerformanceRelationships::class)->reportIds($me)) : Employee::query()->with('person')->employed())->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
            Select::make('manager_id')->label('Manager')->searchable()->default(fn () => $me?->id)
                ->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
            DatePicker::make('start_date')->native(false)->required()->default(now()),
            DatePicker::make('end_date')->native(false)->required()->default(now()->addMonths(2))->afterOrEqual('start_date'),
            Textarea::make('reason')->required()->rows(3)->columnSpanFull(),
            Repeater::make('objectives')->columnSpanFull()->columns(3)->minItems(1)->schema([
                TextInput::make('objective')->required()->columnSpan(2),
                TextInput::make('measure')->placeholder('How success is measured'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—'),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date(),
                TextColumn::make('objectives')->label('Objectives')->state(fn (ImprovementPlan $record) => count($record->objectives ?? [])),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active', 'extended' => 'warning', 'completed' => 'success', 'unsuccessful' => 'danger', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.performance.pip_statuses.{$state}", $state)),
                TextColumn::make('outcome')->limit(60)->placeholder('—')->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.performance.pip_statuses'))])
            ->recordActions([
                Action::make('activate')->label('Activate')->icon('heroicon-m-play')->color('primary')
                    ->visible(fn (ImprovementPlan $record) => $record->status === 'draft' && auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (ImprovementPlan $record) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->activate($record, auth()->user()), 'Plan started')),
                Action::make('checkpoint')->label('Add checkpoint')->icon('heroicon-m-flag')->color('gray')
                    ->visible(fn (ImprovementPlan $record) => $record->isOpen() && auth()->user()->can('update', $record))
                    ->schema([TextInput::make('title')->required()->maxLength(255), DatePicker::make('due_date')->native(false)->required(), Textarea::make('notes')->maxLength(1000)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->addCheckpoint($record, $data['title'], $data['due_date'], $data['notes'] ?? null, auth()->user()), 'Checkpoint added')),
                Action::make('reviewCheckpoint')->label('Review checkpoint')->icon('heroicon-m-clipboard-document-check')->color('gray')
                    ->visible(fn (ImprovementPlan $record) => $record->isOpen() && auth()->user()->can('update', $record) && $record->checkpoints()->where('status', 'pending')->exists())
                    ->schema(fn (ImprovementPlan $record) => [
                        Select::make('checkpoint_id')->label('Checkpoint')->required()->options($record->checkpoints()->where('status', 'pending')->get()->mapWithKeys(fn ($c) => [$c->id => $c->due_date->toDateString().' · '.$c->title])->all()),
                        Select::make('status')->options(collect(config('peopleos.performance.checkpoint_statuses'))->except('pending')->all())->required(),
                        Textarea::make('outcome')->required()->maxLength(1000),
                    ])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->reviewCheckpoint($record->checkpoints()->findOrFail($data['checkpoint_id']), $data['status'], $data['outcome'], auth()->user()), 'Checkpoint reviewed')),
                Action::make('extend')->label('Extend')->icon('heroicon-m-calendar')->color('warning')
                    ->visible(fn (ImprovementPlan $record) => $record->isOpen() && auth()->user()->can('update', $record))
                    ->schema([DatePicker::make('end_date')->native(false)->required(), Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->extend($record, $data['end_date'], $data['reason'], auth()->user()), 'Plan extended')),
                Action::make('close')->label('Close')->icon('heroicon-m-check-circle')->color('success')
                    ->visible(fn (ImprovementPlan $record) => $record->isOpen() && auth()->user()->can('update', $record))
                    ->schema([Select::make('status')->options(collect(config('peopleos.performance.pip_statuses'))->only(['completed', 'unsuccessful', 'cancelled'])->all())->required(), Textarea::make('outcome')->required()->maxLength(1000)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->close($record, $data['status'], $data['outcome'], auth()->user()), 'Outcome recorded')),
                Action::make('closeOut')->label('Close plan')->icon('heroicon-m-lock-closed')->color('gray')
                    ->visible(fn (ImprovementPlan $record) => in_array($record->status, ['completed', 'unsuccessful'], true) && auth()->user()->can('update', $record))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->closeOut($record, $data['reason'], auth()->user()), 'Plan closed')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageImprovementPlans::route('/')];
    }
}

<?php

namespace App\Filament\Resources\OnboardingPlans;

use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\OnboardingPlans\Pages\ListOnboardingPlans;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** HR view of everyone being onboarded (§55). */
class OnboardingPlanResource extends Resource
{
    protected static ?string $model = OnboardingPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Onboarding';

    protected static ?string $modelLabel = 'onboarding plan';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'template'])->withCount(['tasks as open_tasks_count' => fn ($q) => $q->where('status', 'pending')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')->label('ID')->searchable(),
                TextColumn::make('employee.person.display_name')->label('Employee')->weight('medium'),
                TextColumn::make('template.name')->label('Template')->placeholder('—'),
                TextColumn::make('anchor_date')->label('Joining')->date()->sortable(),
                TextColumn::make('progress')->suffix('%')->sortable()->color(fn (int $state) => $state === 100 ? 'success' : ($state < 50 ? 'warning' : null)),
                TextColumn::make('open_tasks_count')->label('Open tasks'),
                TextColumn::make('overdue')->label('Overdue')->state(fn (OnboardingPlan $record) => $record->tasks()->where('status', 'pending')->whereDate('due_on', '<', now())->count())->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => OnboardingPlan::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'completed' => 'success', 'cancelled' => 'gray', default => 'info',
                }),
            ])
            ->filters([SelectFilter::make('status')->options(OnboardingPlan::STATUSES)->default('in_progress')])
            ->defaultSort('anchor_date', 'desc')
            ->recordActions([
                // UX.19: offered only when the Employee 360 opens for this person (its own record check).
                Action::make('open')->label('Open')->icon('heroicon-m-arrow-top-right-on-square')->url(fn (OnboardingPlan $record) => EmployeeResource::getUrl('view', ['record' => $record->employee_id]))
                    ->visible(fn (OnboardingPlan $record) => $record->employee !== null && EmployeeResource::canView($record->employee)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOnboardingPlans::route('/')];
    }
}

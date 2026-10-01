<?php

namespace App\Filament\Resources\TalentDevelopmentActions;

use App\Domain\Talent\Models\TalentDevelopmentAction;
use App\Filament\Resources\TalentDevelopmentActions\Pages\ManageTalentDevelopmentActions;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 9 development actions for successors and review outcomes. Each is a link to an item in the
 * employee's Phase 8 development plan, where the work (and any learning enrolment) happens — so
 * learning is never assigned twice. Progress is read from the plan item.
 */
class TalentDevelopmentActionResource extends Resource
{
    protected static ?string $model = TalentDevelopmentAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Development actions';

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('succession.view') || auth()->user()?->can('succession.manage') || auth()->user()?->can('talent.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'planItem.plan', 'successor.plan.position'])->whereNot('talent_development_actions.employee_id', TalentActions::me()?->id ?? 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable()->description(fn (TalentDevelopmentAction $record) => $record->employee?->employee_code),
                TextColumn::make('action_type')->label('Type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.talent.development_action_types.{$state}", $state)),
                TextColumn::make('planItem.title')->label('Plan item')->wrap(),
                TextColumn::make('successor.plan.position.title')->label('For')->placeholder('Review outcome'),
                TextColumn::make('planItem.due_on')->label('Due')->date()->placeholder('—'),
                TextColumn::make('planItem.status')->label('Progress')->badge()->color(fn (?string $state) => $state === 'completed' ? 'success' : 'info'),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No development actions')->emptyStateDescription('Add development actions from the Successors screen.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTalentDevelopmentActions::route('/')];
    }
}

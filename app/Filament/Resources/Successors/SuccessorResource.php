<?php

namespace App\Filament\Resources\Successors;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Filament\Resources\Successors\Pages\ManageSuccessors;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 9 successors across plans. succession.view sees successors within organisation scope;
 * succession.team only the employees the user manages (configured relationship types). Readiness
 * and development actions are recorded here; development actions become items of the employee's
 * Phase 8 development plan, worded neutrally.
 */
class SuccessorResource extends Resource
{
    protected static ?string $model = Successor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Successors';

    protected static ?int $navigationSort = 55;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'plan.position'])->withCurrentReadiness();
        $user = auth()->user();
        if ($user->can('succession.view') || $user->can('succession.manage')) {
            return $query->whereNot('successors.employee_id', TalentActions::me()?->id ?? 0);
        }
        $team = $user->can('succession.team') ? app(PerformanceRelationships::class)->reportIds(TalentActions::me()) : collect();

        return $query->whereIn('successors.employee_id', $team);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable()->description(fn (Successor $record) => $record->employee?->employee_code),
                TextColumn::make('plan.position.title')->label('Critical position')->wrap(),
                TextColumn::make('current_readiness')->label('Readiness')
                    ->formatStateUsing(fn (?string $state) => config("peopleos.talent.readiness_levels.{$state}", $state))->placeholder('Not assessed')->badge()->color('gray'),
                TextColumn::make('added_at')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'removed' => 'Removed'])->default('active')])
            ->recordActions([
                Action::make('readiness')->label('Record readiness')->icon(Heroicon::OutlinedFlag)
                    ->visible(fn (Successor $record) => $record->status === 'active' && auth()->user()->can('succession.assess'))
                    ->schema([
                        Select::make('level')->label('Readiness')->options(config('peopleos.talent.readiness_levels'))->required(),
                        Textarea::make('reason')->required()->maxLength(1000),
                        Textarea::make('evidence')->maxLength(2000),
                        DatePicker::make('effective_from')->native(false)->default(now()),
                    ])
                    ->action(fn (Successor $record, array $data) => TalentActions::run(fn () => app(Readiness::class)->assess(
                        Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($record->employee_id), $record->plan->critical_position_id, null, $data['level'], $data['reason'], $data['evidence'] ?? null, auth()->user(), $record, $data['effective_from'] ?? null), 'Readiness recorded')),
                Action::make('develop')->label('Development action')->icon(Heroicon::OutlinedAcademicCap)->color('primary')
                    ->visible(fn (Successor $record) => $record->status === 'active' && auth()->user()->can('succession.manage'))
                    ->schema([
                        Select::make('type')->options(config('peopleos.talent.development_action_types'))->required(),
                        TextInput::make('title')->required()->maxLength(255)->helperText('Shown to the employee in their development plan — keep it neutral; do not mention succession.'),
                        Select::make('skill_id')->label('Skill')->options(fn () => Skill::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                        TextInput::make('target_level')->numeric(),
                        Select::make('course_id')->label('Course')->options(fn () => Course::query()->whereIn('status', Course::ENROLLABLE)->orderBy('title')->pluck('title', 'id')->all())->searchable(),
                        Select::make('learning_path_id')->label('Learning path')->options(fn () => LearningPath::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                        DatePicker::make('due_on')->native(false),
                    ])
                    ->action(fn (Successor $record, array $data) => TalentActions::run(fn () => app(SuccessionPlans::class)->addDevelopmentAction($record, $data['type'], $data['title'], $data, auth()->user()), 'Development action added to the employee\'s plan')),
                Action::make('remove')->label('Remove')->icon(Heroicon::OutlinedUserMinus)->color('danger')->requiresConfirmation()
                    ->visible(fn (Successor $record) => $record->status === 'active' && auth()->user()->can('update', $record))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (Successor $record, array $data) => TalentActions::run(fn () => app(SuccessionPlans::class)->removeSuccessor($record, $data['reason'], auth()->user()), 'Successor removed')),
            ])
            ->emptyStateHeading('No successors')->emptyStateDescription('Successors are added to succession plans by people with succession.manage.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuccessors::route('/')];
    }
}

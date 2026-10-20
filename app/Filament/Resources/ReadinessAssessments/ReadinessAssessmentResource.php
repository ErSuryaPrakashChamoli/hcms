<?php

namespace App\Filament\Resources\ReadinessAssessments;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Services\Readiness;
use App\Filament\Resources\ReadinessAssessments\Pages\ManageReadinessAssessments;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Phase 9 readiness history: configured labels recorded by people, immutable, superseded by newer assessments. A label, not a prediction. */
class ReadinessAssessmentResource extends Resource
{
    protected static ?string $model = ReadinessAssessment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Readiness';

    protected static ?int $navigationSort = 80;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'position', 'designation', 'assessor']);
        $user = auth()->user();
        if ($user->can('succession.view') || $user->can('succession.manage')) {
            return $query->whereNot('readiness_assessments.employee_id', TalentActions::me()?->id ?? 0);
        }

        return $query->whereIn('readiness_assessments.employee_id', $user->can('succession.team') ? app(PerformanceRelationships::class)->reportIds(TalentActions::me()) : collect());
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('assess')->label('Record readiness')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('succession.assess'))
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => TalentActions::scopedPeopleOptions())->searchable()->required(),
                    Select::make('critical_position_id')->label('Critical position')->options(fn () => CriticalPosition::query()->where('status', 'active')->orderBy('title')->pluck('title', 'id')->all())->searchable(),
                    Select::make('designation_id')->label('…or a role')->options(fn () => TalentActions::designationOptions())->searchable(),
                    Select::make('level')->label('Readiness')->options(config('peopleos.talent.readiness_levels'))->required(),
                    Textarea::make('reason')->required()->maxLength(1000),
                    Textarea::make('evidence')->maxLength(2000),
                    DatePicker::make('effective_from')->native(false)->default(now()),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => app(Readiness::class)->assess(Employee::query()->findOrFail($data['employee_id']), $data['critical_position_id'] ?? null, $data['designation_id'] ?? null, $data['level'], $data['reason'], $data['evidence'] ?? null, auth()->user(), null, $data['effective_from'] ?? null), 'Readiness recorded')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable()->description(fn (ReadinessAssessment $record) => $record->employee?->employee_code),
                TextColumn::make('target')->label('For')->state(fn (ReadinessAssessment $record) => $record->position?->title ?? $record->designation?->name),
                TextColumn::make('readiness_level')->label('Readiness')->badge()->formatStateUsing(fn (string $state) => config("peopleos.talent.readiness_levels.{$state}", $state))
                    ->color(fn (string $state) => $state === 'ready_now' ? 'success' : 'gray'),
                TextColumn::make('assessor.name')->label('Recorded by'),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->label('Valid until')->date()->color(fn (ReadinessAssessment $record) => $record->effective_to?->isPast() ? 'danger' : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'current' ? 'success' : 'gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(['current' => 'Current', 'superseded' => 'Superseded'])->default('current')])
            ->emptyStateHeading('No readiness recorded');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReadinessAssessments::route('/')];
    }
}

<?php

namespace App\Filament\Resources\CriticalPositions;

use App\Domain\Organisation\Models\Designation;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\CriticalPositionAssessment;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Filament\Resources\CriticalPositions\Pages\ManageCriticalPositions;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 9 critical positions: a role (optionally within an organisation unit) designated critical
 * by a person with succession.manage. Criticality, impact, scarcity, replacement difficulty and
 * operational dependency are judgements recorded as immutable assessments — never computed.
 * Incumbents and recorded exits are read from employment.
 */
class CriticalPositionResource extends Resource
{
    protected static ?string $model = CriticalPosition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Critical positions';

    protected static ?int $navigationSort = 40;

    /** @return list<Component> */
    public static function assessmentFields(): array
    {
        return [
            Select::make('criticality')->options(config('peopleos.talent.criticality_levels'))->required(),
            Select::make('business_impact')->label('Business impact')->options(config('peopleos.talent.impact_levels'))->required(),
            Select::make('scarcity')->label('Talent scarcity')->options(config('peopleos.talent.impact_levels'))->required(),
            Select::make('replacement_difficulty')->label('Replacement difficulty')->options(config('peopleos.talent.impact_levels'))->required(),
            Select::make('operational_dependency')->label('Operational dependency')->options(config('peopleos.talent.impact_levels'))->required(),
            Textarea::make('reason')->label('Reason')->required()->maxLength(1000),
        ];
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('designate')->label('Designate critical position')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('succession.manage'))
                ->schema([
                    Select::make('designation_id')->label('Role')->options(fn () => TalentActions::designationOptions())->searchable()->required(),
                    Select::make('organisation_node_id')->label('Organisation unit (optional)')->options(fn () => TalentActions::nodeOptions())->searchable()->placeholder('Role-wide'),
                    TextInput::make('title')->required()->maxLength(255),
                    TextInput::make('review_frequency_months')->label('Review every (months)')->numeric()->minValue(1)->default(12)->required(),
                    ...self::assessmentFields(),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => app(CriticalPositions::class)->designate(
                    Designation::query()->findOrFail($data['designation_id']), $data['organisation_node_id'] ?? null, $data['title'], $data, (int) $data['review_frequency_months'], auth()->user()), 'Critical position designated')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['designation', 'currentAssessment'])->withCount(['plans as open_plans' => fn ($q) => $q->where('status', '!=', 'closed')]))
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('designation.name')->label('Role'),
                TextColumn::make('currentAssessment.criticality')->label('Criticality')->badge()->formatStateUsing(fn (?string $state) => config("peopleos.talent.criticality_levels.{$state}", $state))
                    ->color(fn (?string $state) => match ($state) {
                        'critical' => 'danger', 'high' => 'warning', default => 'gray'
                    }),
                TextColumn::make('open_plans')->label('Open plan')->formatStateUsing(fn (int $state) => $state > 0 ? 'Yes' : 'No'),
                TextColumn::make('next_review_on')->label('Next review')->date()->color(fn (CriticalPosition $record) => $record->next_review_on?->isPast() ? 'danger' : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'retired' => 'Retired'])->default('active')])
            ->recordActions([
                Action::make('facts')->label('Facts')->icon(Heroicon::OutlinedInformationCircle)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (CriticalPosition $record) => [
                        TextEntry::make('incumbents')->label('Current incumbents')->state(app(CriticalPositions::class)->incumbents($record)->map(fn ($e) => "{$e->employee_code} · {$e->person?->full_name}")->implode(', ') ?: 'Vacant'),
                        TextEntry::make('exit')->label('Recorded incumbent exit')->state(app(CriticalPositions::class)->upcomingIncumbentExit($record) ?? 'None recorded'),
                        ...$record->assessments()->with('assessor')->latest('id')->get()->map(fn (CriticalPositionAssessment $a) => TextEntry::make("a{$a->id}")
                            ->label($a->assessed_at?->toDateString().' · '.($a->assessor?->name ?? 'system'))
                            ->state("Criticality {$a->criticality}; impact {$a->business_impact}; scarcity {$a->scarcity}; replacement {$a->replacement_difficulty}; dependency {$a->operational_dependency}")
                            ->helperText($a->reason))->all(),
                    ]),
                Action::make('assess')->label('Reassess')->icon(Heroicon::OutlinedScale)
                    ->visible(fn (CriticalPosition $record) => $record->status === 'active' && auth()->user()->can('succession.manage'))
                    ->schema(self::assessmentFields())
                    ->action(fn (CriticalPosition $record, array $data) => TalentActions::run(fn () => app(CriticalPositions::class)->assess($record, $data, auth()->user()), 'Assessment recorded')),
                Action::make('plan')->label('Create succession plan')->icon(Heroicon::OutlinedUserGroup)->color('primary')
                    ->visible(fn (CriticalPosition $record) => $record->status === 'active' && $record->open_plans === 0 && auth()->user()->can('succession.manage'))
                    ->schema([
                        Select::make('vacancy_risk')->label('Vacancy risk (recorded judgement)')->options(config('peopleos.talent.vacancy_risk_levels')),
                        DatePicker::make('review_date')->native(false),
                        Textarea::make('confidential_notes')->label('Confidential notes')->rows(2),
                    ])
                    ->action(fn (CriticalPosition $record, array $data) => TalentActions::run(fn () => app(SuccessionPlans::class)->create($record, $data, auth()->user()), 'Succession plan created')),
                Action::make('retire')->label('Retire')->icon(Heroicon::OutlinedArchiveBox)->color('danger')->requiresConfirmation()
                    ->visible(fn (CriticalPosition $record) => $record->status === 'active' && auth()->user()->can('succession.manage'))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (CriticalPosition $record, array $data) => TalentActions::run(fn () => app(CriticalPositions::class)->retire($record, $data['reason'], auth()->user()), 'Critical position retired')),
            ])
            ->emptyStateHeading('No critical positions')->emptyStateDescription('People with succession.manage designate roles as critical and record why.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCriticalPositions::route('/')];
    }
}

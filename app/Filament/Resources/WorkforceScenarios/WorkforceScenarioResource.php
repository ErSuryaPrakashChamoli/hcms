<?php

namespace App\Filament\Resources\WorkforceScenarios;

use App\Domain\Workforce\Models\WorkforceScenario;
use App\Domain\Workforce\Services\WorkforceScenarios;
use App\Filament\Resources\WorkforceScenarios\Pages\ManageWorkforceScenarios;
use App\Filament\Support\WorkforceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 10 scenarios: tenant-named, with explicit labelled planning assumptions; approved by a second person and then locked. */
class WorkforceScenarioResource extends Resource
{
    protected static ?string $model = WorkforceScenario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Scenarios';

    protected static ?int $navigationSort = 50;

    /** @return list<Field> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required(),
            Textarea::make('description')->rows(2),
            TextInput::make('assumptions.attrition_rate_percent')->label(config('peopleos.workforce.scenario_assumptions.attrition_rate_percent'))->numeric()->minValue(0)->maxValue(100)
                ->helperText('A planning assumption for the whole population — never a prediction about any employee.'),
            TextInput::make('assumptions.growth_rate_percent')->label(config('peopleos.workforce.scenario_assumptions.growth_rate_percent'))->numeric(),
            Textarea::make('assumptions.notes')->label(config('peopleos.workforce.scenario_assumptions.notes'))->rows(2),
        ];
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('create')->label('New scenario')->icon(Heroicon::OutlinedPlus)->visible(fn () => auth()->user()->can('workforce.plan'))
                ->schema([TextInput::make('code')->required()->maxLength(32)->alphaDash(), ...self::fields()])
                ->action(fn (array $data) => WorkforceActions::run(fn () => app(WorkforceScenarios::class)->create($data['code'], $data['name'], $data['description'] ?? null, (array) ($data['assumptions'] ?? []), auth()->user()), 'Scenario created')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('assumptions')->label('Planning assumptions')->state(fn (WorkforceScenario $record) => collect($record->assumptions ?? [])->map(fn ($v, $k) => config("peopleos.workforce.scenario_assumptions.{$k}").': '.$v)->implode(' · ') ?: '—')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'archived' => 'gray', default => 'info'
                }),
            ])
            ->recordActions([
                Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->visible(fn (WorkforceScenario $record) => $record->status === 'draft' && auth()->user()->can('workforce.plan'))
                    ->fillForm(fn (WorkforceScenario $record) => ['name' => $record->name, 'description' => $record->description, 'assumptions' => $record->assumptions ?? []])
                    ->schema(self::fields())
                    ->action(fn (WorkforceScenario $record, array $data) => WorkforceActions::run(fn () => app(WorkforceScenarios::class)->update($record, $data, auth()->user()), 'Scenario saved')),
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (WorkforceScenario $record) => $record->status === 'draft' && auth()->user()->can('workforce.approve') && (int) $record->created_by !== (int) auth()->id())
                    ->action(fn (WorkforceScenario $record) => WorkforceActions::run(fn () => app(WorkforceScenarios::class)->approve($record, auth()->user()), 'Scenario approved')),
                Action::make('archive')->label('Archive')->icon(Heroicon::OutlinedArchiveBox)->color('danger')
                    ->visible(fn (WorkforceScenario $record) => $record->status !== 'archived' && auth()->user()->can('workforce.plan'))
                    ->schema([Textarea::make('reason')->required()])
                    ->action(fn (WorkforceScenario $record, array $data) => WorkforceActions::run(fn () => app(WorkforceScenarios::class)->archive($record, $data['reason'], auth()->user()), 'Scenario archived')),
            ])
            ->emptyStateHeading('No scenarios')->emptyStateDescription('Name your own scenarios (Baseline, Growth, Cost reduction …). They never change live data.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageWorkforceScenarios::route('/')];
    }
}

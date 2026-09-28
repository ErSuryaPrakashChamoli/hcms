<?php

namespace App\Filament\Resources\CalibrationSessions;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\CalibrationSession;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Calibrations;
use App\Filament\Resources\CalibrationSessions\Pages\ManageCalibrationSessions;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 7: calibration sessions with an immutable adjustment history. Confidential (performance.calibrate). */
class CalibrationSessionResource extends Resource
{
    protected static ?string $model = CalibrationSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Calibration sessions';

    protected static ?int $navigationSort = 26;

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('open')->label('Open session')->icon(Heroicon::OutlinedPlus)->color('primary')
                ->visible(fn () => auth()->user()->can('performance.calibrate'))
                ->schema([
                    Select::make('cycle_id')->label('Cycle')->required()->live()->options(fn () => PerformanceCycle::query()->where('status', 'active')->pluck('name', 'id')->all()),
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('appraisal_ids')->label('Population')->multiple()->helperText('Leave empty for everyone in the cycle.')
                        ->options(fn (Get $get) => Appraisal::query()->with('employee.person')->where('performance_cycle_id', $get('cycle_id') ?? 0)->get()->mapWithKeys(fn (Appraisal $a) => [$a->id => $a->employee?->employee_code.' · '.$a->employee?->person?->full_name])->all()),
                ])
                ->action(fn (array $data) => PerformanceActions::run(fn () => app(Calibrations::class)->openSession(PerformanceCycle::query()->findOrFail($data['cycle_id']), $data['name'], empty($data['appraisal_ids']) ? null : array_map('intval', $data['appraisal_ids']), [auth()->id()], auth()->user()), 'Session opened')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['cycle', 'facilitator'])->withCount('adjustments'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('cycle.name')->label('Cycle'),
                TextColumn::make('facilitator.name')->label('Facilitator')->placeholder('—'),
                TextColumn::make('population')->label('Population')->state(fn (CalibrationSession $record) => count($record->population ?? [])),
                TextColumn::make('adjustments_count')->label('Adjustments'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'warning' : 'gray'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('adjust')->label('Adjust rating')->icon(Heroicon::OutlinedAdjustmentsVertical)->color('warning')
                    ->visible(fn (CalibrationSession $record) => $record->status === 'open' && auth()->user()->can('performance.calibrate'))
                    ->schema(fn (CalibrationSession $record) => [
                        Select::make('appraisal_id')->label('Appraisal')->required()->live()
                            ->options(Appraisal::query()->with('employee.person')->whereIn('id', $record->population ?? [])->whereNotIn('status', ['finalized', 'acknowledged'])->get()->mapWithKeys(fn (Appraisal $a) => [$a->id => $a->employee?->employee_code.' · '.$a->employee?->person?->full_name.' (current '.($a->effectiveRating() ?? '—').')'])->all())
                            ->afterStateUpdated(fn ($state, callable $set) => $set('expected', Appraisal::query()->find($state)?->effectiveRating())),
                        Hidden::make('expected'),
                        Select::make('rating')->label('Calibrated rating')->required()->options($record->cycle->ratingScale()->options()),
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(fn (CalibrationSession $record, array $data) => PerformanceActions::run(fn () => app(Calibrations::class)->adjust(Appraisal::query()->findOrFail($data['appraisal_id']), (float) $data['rating'], $data['reason'], filled($data['expected'] ?? null) ? (float) $data['expected'] : null, $record, auth()->user()), 'Adjustment recorded')),
                Action::make('history')->label('History')->icon(Heroicon::OutlinedClock)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (CalibrationSession $record) => $record->adjustments()->with(['appraisal.employee', 'actor'])->get()->map(fn ($a) => TextEntry::make("adj{$a->id}")
                        ->label($a->created_at?->toDateTimeString().' · '.$a->appraisal?->employee?->employee_code.' · '.$a->actor?->name)
                        ->state(sprintf('%s → %s (original %s): %s', $a->previous_rating ?? '—', $a->adjusted_rating, $a->original_rating ?? '—', $a->reason)))->all()),
                Action::make('close')->label('Close session')->icon(Heroicon::OutlinedLockClosed)->color('gray')
                    ->visible(fn (CalibrationSession $record) => $record->status === 'open' && auth()->user()->can('performance.calibrate'))
                    ->requiresConfirmation()
                    ->action(fn (CalibrationSession $record) => PerformanceActions::run(fn () => app(Calibrations::class)->closeSession($record, auth()->user()), 'Session closed')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCalibrationSessions::route('/')];
    }
}

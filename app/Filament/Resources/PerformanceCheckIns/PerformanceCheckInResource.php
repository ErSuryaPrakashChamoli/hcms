<?php

namespace App\Filament\Resources\PerformanceCheckIns;

use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Services\PerformanceCheckIns;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\PerformanceCheckIns\Pages\ManagePerformanceCheckIns;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Phase 7: continuous check-ins — employees write their own, managers (configured relationships) respond. */
class PerformanceCheckInResource extends Resource
{
    protected static ?string $model = PerformanceCheckIn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Check-ins';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'manager.person']);
        $user = auth()->user();
        if ($user->can('performance.view')) {
            return $query;
        }
        $me = PerformanceActions::me();
        $team = app(PerformanceRelationships::class)->teamOf($user);

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', $team));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('employee.person.full_name')->label('Employee'),
            TextEntry::make('period_date')->date()->label('Period'),
            ...collect(['went_well' => 'Went well', 'blockers' => 'Blockers', 'support_needed' => 'Support needed', 'priorities' => 'Priorities', 'employee_feedback' => 'Employee feedback', 'manager_feedback' => 'Manager feedback'])
                ->map(fn ($label, $field) => TextEntry::make($field)->label($label)->placeholder('—')->columnSpanFull())->values()->all(),
            TextEntry::make('actions')->label('Actions')->state(fn (PerformanceCheckIn $record) => collect($record->actions ?? [])->pluck('item')->filter()->implode(' · ') ?: '—')->columnSpanFull(),
        ]);
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('checkIn')->label('My check-in')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn () => PerformanceActions::me() !== null && (auth()->user()->can('performance.checkins') || auth()->user()->can('performance.goals')))
                ->schema([
                    Select::make('cadence')->options(config('peopleos.performance.check_in_cadences'))->default('weekly')->required(),
                    DatePicker::make('date')->native(false)->default(now())->required(),
                    Textarea::make('went_well')->label('What went well')->rows(2),
                    Textarea::make('blockers')->rows(2),
                    Textarea::make('support_needed')->label('Support needed')->rows(2),
                    Textarea::make('priorities')->label('Next priorities')->rows(2),
                    Repeater::make('goal_progress')->label('Goal progress')->columns(3)->default([])->schema([
                        Select::make('goal_id')->label('Goal')->required()->options(fn () => Goal::query()->where('employee_id', PerformanceActions::me()?->id ?? 0)->whereIn('status', ['draft', 'active'])->pluck('title', 'id')->all()),
                        TextInput::make('value')->numeric()->required(),
                        TextInput::make('note')->maxLength(255),
                    ]),
                    Textarea::make('employee_feedback')->label('Feedback for my manager')->rows(2),
                    Toggle::make('submit')->label('Submit to my manager')->default(true),
                ])
                ->action(fn (array $data) => PerformanceActions::run(fn () => app(PerformanceCheckIns::class)->save(PerformanceActions::me(), $data['cadence'], $data['date'], $data, (bool) ($data['submit'] ?? false), auth()->user()), 'Check-in saved')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_date')->label('Period')->date()->sortable(),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(),
                TextColumn::make('cadence')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.performance.check_in_cadences.{$state}", $state)),
                TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'reviewed' => 'success', 'submitted' => 'warning', default => 'gray'
                }),
                TextColumn::make('submitted_at')->since()->placeholder('—'),
            ])
            ->defaultSort('period_date', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.performance.check_in_statuses'))])
            ->recordActions([
                ViewAction::make(),
                Action::make('respond')->label('Respond')->icon(Heroicon::OutlinedChatBubbleBottomCenterText)->color('primary')
                    ->visible(fn (PerformanceCheckIn $record) => $record->status === 'submitted' && app(PerformanceRelationships::class)->manages(PerformanceActions::me(), $record->employee_id))
                    ->schema([
                        Textarea::make('manager_feedback')->label('Your feedback')->required()->rows(3),
                        Repeater::make('actions')->label('Agreed actions')->columns(2)->default([])->schema([TextInput::make('item')->required(), TextInput::make('owner')->maxLength(64)]),
                    ])
                    ->action(fn (PerformanceCheckIn $record, array $data) => PerformanceActions::run(fn () => app(PerformanceCheckIns::class)->respond($record, $data['manager_feedback'], $data['actions'] ?? [], auth()->user()), 'Response recorded')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformanceCheckIns::route('/')];
    }
}

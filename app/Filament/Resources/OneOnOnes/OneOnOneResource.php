<?php

namespace App\Filament\Resources\OneOnOnes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\OneOnOnes;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\OneOnOnes\Pages\ManageOneOnOnes;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** One-on-ones / check-ins (§34). */
class OneOnOneResource extends Resource
{
    protected static ?string $model = OneOnOne::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'One-on-ones';

    protected static ?int $navigationSort = 31;

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
            Select::make('manager_id')->label('Manager')->required()->searchable()->default(fn () => $me?->id)
                ->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
            DateTimePicker::make('scheduled_at')->native(false)->required()->default(now()->addDay()->setTime(10, 0)),
            Select::make('status')->options(OneOnOne::STATUSES)->default('scheduled')->required(),
            Textarea::make('agenda')->rows(3)->columnSpanFull(),
            Textarea::make('notes')->label('Shared notes')->rows(4)->columnSpanFull()->helperText('Visible to the employee, the manager and HR'),
            // Phase 7: the manager's private notes — never shown to the employee; others need
            // performance.private_notes and every read is audited (OneOnOnes service).
            Textarea::make('private_notes')->label('Private manager notes')->rows(3)->columnSpanFull()
                ->visible(fn (?OneOnOne $record) => $record !== null && app(OneOnOnes::class)->canReadPrivateNotes($record, auth()->user()))
                ->afterStateHydrated(fn (Textarea $component, ?OneOnOne $record) => $component->state($record ? app(OneOnOnes::class)->privateNotesFor($record, auth()->user()) : null))
                ->disabled(fn (?OneOnOne $record) => $record === null || ! app(OneOnOnes::class)->isOwningManager($record, auth()->user()))
                ->dehydrated(fn (?OneOnOne $record) => $record !== null && app(OneOnOnes::class)->isOwningManager($record, auth()->user()))
                ->helperText('Only you can read these. The employee never sees them.'),
            Repeater::make('action_items')->columnSpanFull()->columns(3)->default([])->schema([
                TextInput::make('item')->required()->columnSpan(2),
                Toggle::make('done')->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scheduled_at')->dateTime()->sortable(),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('manager.person.full_name')->label('Manager'),
                TextColumn::make('agenda')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('action_items')->label('Actions')->state(fn (OneOnOne $record) => collect($record->action_items ?? [])->where('done', true)->count().' / '.count($record->action_items ?? [])),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'held' => 'success', 'cancelled' => 'danger', default => 'gray'
                }),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->recordActions([EditAction::make()->mutateDataUsing(fn (array $data) => $data + ['held_at' => ($data['status'] ?? null) === 'held' ? now() : null])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOneOnOnes::route('/')];
    }
}

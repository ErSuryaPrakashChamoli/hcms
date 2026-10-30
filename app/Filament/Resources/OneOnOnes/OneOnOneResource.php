<?php

namespace App\Filament\Resources\OneOnOnes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\OneOnOnes;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\OneOnOnes\Pages\ManageOneOnOnes;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * One-on-ones / check-ins (§34). HROne setup parity HP-8b: with performance.one_on_one.scope = organisation anyone
 * hosts a one-on-one with any colleague (scheduled through OneOnOnes::schedule); after a held meeting the roles in
 * performance.one_on_one.feedback_from give a feedback card (rating and comment).
 */
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
        $admin = auth()->user()->can('performance.manage');
        $organisation = app(OneOnOnes::class)->scope() === 'organisation';

        return $schema->columns(2)->components([
            Section::make('Meeting')->columns(2)->schema([
                Select::make('employee_id')->label($organisation ? 'With' : 'Employee')->required()->searchable()
                    ->options(fn () => ($me && ! $admin && ! $organisation ? Employee::query()->with('person')->whereIn('id', app(PerformanceRelationships::class)->reportIds($me)) : Employee::query()->with('person')->employed()->when($me && ! $admin, fn ($q) => $q->whereKeyNot($me->id)))->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                // Organisation scope: the person scheduling hosts the meeting (only administrators choose another host).
                Select::make('manager_id')->label($organisation ? 'Host' : 'Manager')->required()->searchable()->default(fn () => $me?->id)
                    ->disabled(fn (string $operation) => $organisation && ! $admin && $operation === 'create')->dehydrated()
                    ->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                DateTimePicker::make('scheduled_at')->native(false)->required()->default(now()->addDay()->setTime(10, 0)),
                Select::make('status')->options(OneOnOne::STATUSES)->default('scheduled')->required(),
                Textarea::make('agenda')->rows(3)->columnSpanFull(),
            ]),
            Section::make('Notes and action items')->columns(2)->schema([
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
                TextColumn::make('feedback_count')->counts('feedback')->label(PerformanceLabels::get('feedback'))->toggleable()
                    ->visible(fn () => app(OneOnOnes::class)->feedbackRoles() !== []),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'held' => 'success', 'cancelled' => 'danger', default => 'gray'
                }),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => $data + ['held_at' => ($data['status'] ?? null) === 'held' ? now() : null]),
                // HP-8b: the feedback card after a held one-on-one.
                Action::make('giveFeedback')->label('Give '.mb_strtolower(PerformanceLabels::get('feedback')))->icon('heroicon-m-chat-bubble-left-right')->color('primary')
                    ->visible(fn (OneOnOne $record) => app(OneOnOnes::class)->owesFeedback($record, PerformanceActions::me()))
                    ->schema([
                        Select::make('rating')->label('How useful was this one-on-one?')->options([5 => '5 · Very useful', 4 => '4 · Useful', 3 => '3 · Fair', 2 => '2 · Not very useful', 1 => '1 · Not useful'])->required()->native(false),
                        Textarea::make('comment')->maxLength(1000),
                    ])
                    ->action(fn (OneOnOne $record, array $data) => PerformanceActions::run(fn () => app(OneOnOnes::class)->giveFeedback($record, auth()->user(), (int) $data['rating'], $data['comment'] ?? null), 'Thank you for your feedback')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOneOnOnes::route('/')];
    }
}

<?php

namespace App\Filament\Resources\Initiatives;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\InitiativeFeedback;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Services\Initiatives;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\Initiatives\Pages\ManageInitiatives;
use App\Filament\Support\PerformanceActions;
use App\Filament\Support\PerformanceRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * HROne setup parity HP-8j (Performance → Initiative; Profile → Goals and initiatives): "My initiatives" for employees
 * (under Me), with the team's initiatives for performance managers and every initiative in scope for HR (under
 * Performance). Initiatives::visibleTo decides the list and Initiatives each action; feedback asked of the person waits
 * on the "Asked for my feedback" tab (and in My tasks).
 */
class InitiativeResource extends Resource
{
    protected static ?string $model = Initiative::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $modelLabel = 'initiative';

    protected static ?int $navigationSort = 45;

    public static function isHr(?User $user = null): bool
    {
        $user ??= auth()->user();

        return (bool) ($user?->hasPermission('performance.initiatives_manage') || $user?->hasPermission('performance.view'));
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::isHr() ? 'Performance' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return self::isHr() ? 'Initiatives' : 'My initiatives';
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('create', Initiative::class);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(Initiatives::class)->visibleTo(auth()->user())
            ->with(['employee.person', 'owner.person', 'type', 'goal'])
            ->withCount(['feedback as feedback_given_count' => fn (Builder $q) => $q->where('status', 'given')]);
    }

    /** The employees the signed-in person may create an initiative for: themself, the people they manage, HR's scope. */
    public static function assignableEmployees(): array
    {
        $user = auth()->user();
        $relationships = app(PerformanceRelationships::class);
        $me = $relationships->forUser($user);
        $query = Employee::query()->with('person')->employed();
        if (! $user->hasPermission('performance.initiatives_manage')) {
            $ids = $user->hasPermission('performance.initiatives') ? array_filter([$me?->id, ...$relationships->reportIds($me)->all()]) : [];
            $query->whereIn('id', $ids);
        }

        return $query->orderBy('employee_code')->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $me = PerformanceActions::me();

        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Assign initiative to')->required()->searchable()->live()->columnSpanFull()
                ->options(fn () => self::assignableEmployees())->default(fn () => $me?->id)
                ->disabled(fn (?Initiative $record) => $record !== null)->dehydrated(fn (?Initiative $record) => $record === null),
            ...self::detailFields(null),
        ]);
    }

    /**
     * The initiative's own fields. $employeeId fixes whose goals are offered (the 9-box action); null = the form's
     * "Assign initiative to" (or the record's employee).
     */
    public static function detailFields(?int $employeeId): array
    {
        $employee = fn (Get $get, ?Initiative $record = null) => $employeeId ?? (int) ($record?->employee_id ?? $get('employee_id'));

        return [
            Select::make('initiative_type_id')->label('Initiative type')->native(false)
                ->options(fn (?Initiative $record = null) => PerformanceLookup::options('initiative_type', $record?->initiative_type_id))
                ->required(fn () => PerformanceLookup::options('initiative_type') !== []),
            TextInput::make('title')->label('Initiative')->required()->maxLength(200),
            Textarea::make('description')->rows(3)->maxLength(5000)->columnSpanFull(),
            Select::make('goal_id')->label('Linked goal')->native(false)->placeholder('Not mapped')
                ->options(fn (Get $get, ?Initiative $record = null) => Goal::query()->where('employee_id', $employee($get, $record) ?: 0)
                    ->where(fn (Builder $q) => $q->where('status', '!=', 'cancelled')->when($record?->goal_id, fn (Builder $k) => $k->orWhere('id', $record->goal_id)))
                    ->orderBy('title')->pluck('title', 'id')->all()),
            DatePicker::make('start_date')->native(false),
            DatePicker::make('due_date')->label('Due date')->native(false)->afterOrEqual('start_date'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Initiative')->columns(3)->schema([
                TextEntry::make('title')->label('Initiative')->columnSpanFull(),
                TextEntry::make('employee.person.full_name')->label('Assigned to'),
                TextEntry::make('owner.person.full_name')->label('Assigned by')->placeholder('Self'),
                TextEntry::make('type.name')->label('Initiative type')->placeholder('—'),
                TextEntry::make('goal.title')->label('Linked goal')->placeholder('Not mapped'),
                TextEntry::make('start_date')->date()->placeholder('—'),
                TextEntry::make('due_date')->label('Due date')->date()->placeholder('—'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.initiative_statuses.{$state}", $state)),
                TextEntry::make('progress')->label('Completion')->suffix('%'),
                TextEntry::make('completed_at')->label('Completed on')->date()->placeholder('—'),
                TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                TextEntry::make('outcome')->columnSpanFull()->visible(fn (Initiative $record) => filled($record->outcome)),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->columnSpanFull()->visible(fn (Initiative $record) => filled($record->cancel_reason)),
            ]),
            Section::make('Feedback')->schema([
                TextEntry::make('feedback_list')->hiddenLabel()->html()
                    ->state(fn (Initiative $record) => self::feedbackHtml($record)),
            ]),
        ]);
    }

    /** The feedback the signed-in person may read on the initiative (Initiatives::feedbackVisibleTo), escaped. */
    public static function feedbackHtml(Initiative $record): HtmlString
    {
        $rows = app(Initiatives::class)->feedbackVisibleTo(auth()->user(), $record);
        if ($rows->isEmpty()) {
            return new HtmlString('<span class="text-sm text-gray-500">No feedback yet.</span>');
        }

        return new HtmlString($rows->map(function (InitiativeFeedback $f) {
            $who = e($f->reviewer?->person?->full_name ?? 'A colleague');
            $status = e(config("peopleos.performance.initiative_feedback_statuses.{$f->status}", $f->status));
            $when = $f->responded_at?->toDateString() ?? $f->requested_at?->toDateString();
            $text = $f->status === 'given' ? '<div class="text-sm" style="white-space: pre-line">'.e((string) $f->feedback).'</div>' : '';

            return '<div class="mt-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3"><div class="text-xs text-gray-500">'.$who.' · '.$status.($when ? ' · '.e($when) : '').'</div>'.$text.'</div>';
        })->implode(''));
    }

    public static function table(Table $table): Table
    {
        $initiatives = fn () => app(Initiatives::class);
        $pending = fn (Initiative $record): ?InitiativeFeedback => ($me = PerformanceActions::me()) === null ? null
            : InitiativeFeedback::query()->where('initiative_id', $record->id)->where('reviewer_id', $me->id)->where('status', 'requested')->first();

        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Assigned to')->description(fn (Initiative $record) => $record->employee?->employee_code),
                TextColumn::make('title')->label('Initiative')->searchable()->wrap()->description(fn (Initiative $record) => $record->type?->name),
                TextColumn::make('goal.title')->label('Goal')->placeholder('Not mapped')->toggleable(),
                TextColumn::make('due_date')->label('Due date')->date()->sortable()->placeholder('—'),
                TextColumn::make('progress')->label('Completion')->suffix('%')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'in_progress' => 'warning', 'completed' => 'success', 'cancelled' => 'gray', default => 'info'
                    })
                    ->formatStateUsing(fn (string $state) => config("peopleos.performance.initiative_statuses.{$state}", $state)),
                TextColumn::make('owner.person.full_name')->label('Assigned by')->placeholder('Self')->toggleable(),
                TextColumn::make('feedback_given_count')->label('Feedback')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.performance.initiative_statuses')),
                SelectFilter::make('initiative_type_id')->label('Initiative type')->options(fn () => PerformanceLookup::query()->ofKind('initiative_type')->orderBy('sort_order')->pluck('name', 'id')->all()),
            ])
            ->emptyStateHeading('No initiatives yet')
            ->emptyStateDescription('Create an initiative: something you take on, such as being a buddy, an induction or a training need.')
            ->recordActions([
                ViewAction::make(),
                // HROne parity GO-1 (§27.4.3 #2, "Goals and initiative" flyer): the employee's goals and initiatives of a cycle,
                // for whoever may see that employee's goals (HR, the employee, their performance managers).
                PerformanceRegisters::goalsAndInitiativesAction(fn (Initiative $record) => $record->employee, fn (Initiative $record) => $record->goal?->performance_cycle_id),
                EditAction::make()->visible(fn (Initiative $record) => auth()->user()->can('update', $record))
                    ->using(fn (Initiative $record, array $data) => $initiatives()->update($record, $data, auth()->user())),
                Action::make('start')->label('Start')->icon(Heroicon::OutlinedPlay)->color('primary')
                    ->visible(fn (Initiative $record) => $record->status === 'planned' && auth()->user()->can('update', $record))
                    ->action(fn (Initiative $record) => PerformanceActions::run(fn () => $initiatives()->start($record, auth()->user()), 'Initiative started')),
                Action::make('progress')->label('Update completion')->icon(Heroicon::OutlinedChartBar)->color('gray')
                    ->visible(fn (Initiative $record) => auth()->user()->can('update', $record))
                    ->schema(fn (Initiative $record) => [TextInput::make('progress')->label('Completion (%)')->numeric()->minValue(0)->maxValue(100)->required()->default($record->progress)])
                    ->action(fn (Initiative $record, array $data) => PerformanceActions::run(fn () => $initiatives()->update($record, ['progress' => $data['progress']], auth()->user()), 'Completion updated')),
                Action::make('complete')->label('Complete')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (Initiative $record) => auth()->user()->can('update', $record))
                    ->requiresConfirmation()->modalDescription('Are you sure you want to complete this initiative? It cannot be changed afterwards.')
                    ->schema([Textarea::make('outcome')->maxLength(2000)])
                    ->action(fn (Initiative $record, array $data) => PerformanceActions::run(fn () => $initiatives()->complete($record, $data['outcome'] ?? null, auth()->user()), 'Initiative completed')),
                Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (Initiative $record) => auth()->user()->can('update', $record))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (Initiative $record, array $data) => PerformanceActions::run(fn () => $initiatives()->cancel($record, $data['reason'], auth()->user()), 'Initiative cancelled')),
                Action::make('requestFeedback')->label('Request feedback')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                    ->visible(fn (Initiative $record) => $record->status !== 'cancelled' && $record->employee !== null && $initiatives()->mayAssignTo(auth()->user(), $record->employee))
                    ->modalDescription('Choose an employee to get feedback from. They are told, and answer from My tasks.')
                    ->schema(fn (Initiative $record) => [Select::make('reviewer_id')->label('Employee')->required()->searchable()
                        ->options(fn () => Employee::query()->with('person')->employed()->whereKeyNot($record->employee_id)->orderBy('employee_code')->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all())])
                    ->action(fn (Initiative $record, array $data) => PerformanceActions::run(fn () => $initiatives()->requestFeedback($record, Employee::query()->findOrFail($data['reviewer_id']), auth()->user()), 'Feedback requested')),
                Action::make('giveFeedback')->label('Give feedback')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->visible(fn (Initiative $record) => $initiatives()->mayGiveFeedback(auth()->user(), $record))
                    ->schema([Textarea::make('feedback')->required()->maxLength(5000)])
                    ->action(fn (Initiative $record, array $data) => PerformanceActions::run(fn () => $initiatives()->giveFeedback($record, $data['feedback'], auth()->user()), 'Feedback given')),
                Action::make('respond')->label('Answer feedback request')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                    ->visible(fn (Initiative $record) => $record->status !== 'cancelled' && $pending($record) !== null)
                    ->schema([Textarea::make('feedback')->label('Enter feedback')->required()->maxLength(5000)])
                    ->action(fn (Initiative $record, array $data) => PerformanceActions::run(fn () => $initiatives()->respond($pending($record), $data['feedback'], auth()->user()), 'Feedback sent')),
                Action::make('ignore')->label('Ignore request')->icon(Heroicon::OutlinedEyeSlash)->color('gray')
                    ->visible(fn (Initiative $record) => $record->status !== 'cancelled' && $pending($record) !== null)
                    ->requiresConfirmation()->modalDescription('The request is closed without feedback.')
                    ->action(fn (Initiative $record) => PerformanceActions::run(fn () => $initiatives()->ignore($pending($record), auth()->user()), 'Request ignored')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageInitiatives::route('/')];
    }
}

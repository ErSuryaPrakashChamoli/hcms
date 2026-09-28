<?php

namespace App\Filament\Resources\FeedbackEntries;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\Feedback;
use App\Filament\Resources\FeedbackEntries\Pages\ManageFeedbackEntries;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Continuous feedback (§34). */
class FeedbackEntryResource extends Resource
{
    protected static ?string $model = FeedbackEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $me = EmployeeOwnedPolicy::employeeOf($user);

        if ($me === null && ! $user->can('performance.view')) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return ($me ? app(Feedback::class)->visibleTo($me, $user->can('performance.view')) : parent::getEloquentQuery())->with(['employee.person', 'requestedFrom.person', 'goal', 'competency', 'author.person']);
    }

    /** @return array<int, Component> */
    public static function peopleOptions(): array
    {
        return Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function giveAction(): Action
    {
        return Action::make('give')->label('Give feedback')->icon(Heroicon::OutlinedHandThumbUp)->color('primary')
            ->visible(fn () => auth()->user()->can('performance.feedback') && PerformanceActions::me() !== null)
            ->schema([
                Select::make('employee_id')->label('About')->required()->searchable()->options(fn () => self::peopleOptions())->live(),
                Select::make('type')->options(['praise' => 'Praise', 'constructive' => 'Constructive'])->default('praise')->required(),
                Select::make('visibility')->options(config('peopleos.performance.feedback_visibility'))->default('manager')->required(),
                Select::make('goal_id')->label('Related goal')->placeholder('—')->options(fn (Get $get) => Goal::query()->where('employee_id', $get('employee_id'))->whereIn('status', ['active', 'completed'])->pluck('title', 'id')->all()),
                Select::make('competency_id')->label('Competency')->placeholder('—')->options(fn () => Competency::query()->where('status', 'active')->pluck('name', 'id')->all()),
                Textarea::make('message')->required()->maxLength(2000)->rows(4),
                Toggle::make('is_anonymous')->label('Give anonymously')->helperText('Your name is hidden from the recipient, their manager and HR.'),
            ])
            ->action(fn (array $data) => PerformanceActions::run(fn () => app(Feedback::class)->give(Employee::query()->findOrFail($data['employee_id']), PerformanceActions::me(), $data['type'], $data['message'], $data['visibility'], $data['goal_id'] ?? null, $data['competency_id'] ?? null, null, (bool) ($data['is_anonymous'] ?? false)), 'Feedback shared'));
    }

    public static function requestAction(): Action
    {
        return Action::make('request')->label('Request feedback')->icon(Heroicon::OutlinedQuestionMarkCircle)->color('gray')
            ->visible(fn () => auth()->user()->can('performance.feedback') && PerformanceActions::me() !== null)
            ->schema([
                Select::make('about_id')->label('About')->required()->searchable()->options(fn () => self::peopleOptions())->default(fn () => PerformanceActions::me()?->id)
                    ->visible(fn () => auth()->user()->can('performance.team') || auth()->user()->can('performance.manage')),
                Select::make('from_id')->label('Ask')->required()->searchable()->options(fn () => self::peopleOptions()),
                Select::make('goal_id')->label('About a goal')->placeholder('—')->options(fn (Get $get) => Goal::query()->where('employee_id', $get('about_id') ?? PerformanceActions::me()?->id)->whereIn('status', ['active', 'completed'])->pluck('title', 'id')->all()),
                Textarea::make('message')->label('What would you like feedback on?')->required()->maxLength(1000),
            ])
            ->action(fn (array $data) => PerformanceActions::run(fn () => app(Feedback::class)->request(Employee::query()->findOrFail($data['about_id'] ?? PerformanceActions::me()->id), Employee::query()->findOrFail($data['from_id']), $data['message'], $data['goal_id'] ?? null, PerformanceActions::me()), 'Request sent'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->sortable(),
                TextColumn::make('type')->badge()->color(fn (string $state) => match ($state) {
                    'praise' => 'success', 'constructive' => 'warning', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_types.{$state}", $state)),
                TextColumn::make('employee.person.full_name')->label('About'),
                TextColumn::make('from')->label('From')->state(fn (FeedbackEntry $record) => $record->authorLabel()),
                TextColumn::make('requestedFrom.person.full_name')->label('Asked')->placeholder('—')->toggleable(),
                TextColumn::make('message')->wrap()->limit(160),
                TextColumn::make('goal.title')->label('Goal')->placeholder('—')->toggleable(),
                TextColumn::make('visibility')->badge()->color('gray')->toggleable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'requested' ? 'warning' : 'gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('type')->options(config('peopleos.performance.feedback_types'))])
            ->recordActions([
                Action::make('answer')->label('Answer')->icon('heroicon-m-arrow-uturn-left')->color('primary')
                    ->visible(fn (FeedbackEntry $record) => $record->status === 'requested' && $record->requested_from_id === PerformanceActions::me()?->id)
                    ->schema([
                        Select::make('type')->options(['praise' => 'Praise', 'constructive' => 'Constructive'])->default('constructive')->required(),
                        Select::make('visibility')->options(config('peopleos.performance.feedback_visibility'))->default('manager')->required(),
                        Textarea::make('message')->required()->maxLength(2000)->rows(4),
                    ])
                    ->action(fn (FeedbackEntry $record, array $data) => PerformanceActions::run(fn () => app(Feedback::class)->give($record->employee, PerformanceActions::me(), $data['type'], $data['message'], $data['visibility'], $record->goal_id, null, $record), 'Feedback shared')),
                Action::make('reveal')->label('Reveal author')->icon(Heroicon::OutlinedEye)->color('danger')
                    ->visible(fn (FeedbackEntry $record) => $record->is_anonymous && auth()->user()->can('performance.anonymous_identity'))
                    ->schema([Textarea::make('reason')->label('Reason (audited)')->required()->maxLength(500)])
                    ->action(fn (FeedbackEntry $record, array $data) => PerformanceActions::run(fn () => app(Feedback::class)->authorFor($record, auth()->user(), $data['reason']), fn (?Employee $author) => 'Author: '.($author?->person?->full_name ?? 'unknown'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFeedbackEntries::route('/')];
    }
}

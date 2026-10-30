<?php

namespace App\Filament\Resources\FeedbackRequests;

use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Filament\Resources\FeedbackRequests\Pages\ListFeedbackRequests;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity P13: feedback asked of the signed-in person (answer it here; it is also in My tasks) and the feedback
 * they may read (HR for the processes they run, the next level when copied). Nobody sees answers about themselves given
 * by others.
 */
class FeedbackRequestResource extends Resource
{
    protected static ?string $model = FeedbackRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    protected static ?int $navigationSort = 45;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(FeedbackRequests::class)->visibleTo(auth()->user())->with(['employee.person', 'respondentUser', 'respondentRole']);
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(FeedbackRequests::class);

        return $table
            ->columns([
                TextColumn::make('title')->weight('medium')->wrap()->description(fn (FeedbackRequest $r) => $r->processLabel()),
                TextColumn::make('employee.person.display_name')->label('About')->placeholder('—'),
                TextColumn::make('respondent')->label('Given by')->state(fn (FeedbackRequest $r) => $r->respondentUser?->name ?? ($r->respondentRole ? 'Role: '.$r->respondentRole->name : '—')),
                TextColumn::make('due_on')->label('Due')->date()->placeholder('—')->sortable()->color(fn (FeedbackRequest $r) => $r->status === 'pending' && $r->due_on?->isPast() ? 'danger' : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => FeedbackRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'submitted' => 'success', 'cancelled' => 'gray', default => 'warning',
                    }),
                TextColumn::make('submitted_at')->label('Given on')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(FeedbackRequest::STATUSES),
                SelectFilter::make('process')->label('Process')->options(collect(config('peopleos.feedback.processes'))->map(fn (array $p) => $p['label'])->all()),
            ])
            ->defaultSort('due_on')
            ->recordActions([
                Action::make('answer')->label('Give feedback')->icon('heroicon-m-pencil-square')->color('primary')
                    ->visible(fn (FeedbackRequest $r) => $r->status === 'pending' && $r->isRespondent(auth()->user()))
                    ->modalHeading(fn (FeedbackRequest $r) => $r->title)
                    ->schema(fn (FeedbackRequest $r) => self::answerFields((array) $r->questions))
                    ->action(function (FeedbackRequest $record, array $data) use ($service) {
                        try {
                            $service()->submit($record, (array) ($data['answers'] ?? []), auth()->user());
                            Notification::make()->success()->title('Feedback given')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('read')->label('Answers')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)
                    ->visible(fn (FeedbackRequest $r) => $r->status === 'submitted' && $service()->canRead(auth()->user(), $r))
                    ->modalHeading(fn (FeedbackRequest $r) => $r->title)
                    ->schema(fn (FeedbackRequest $r) => self::answerEntries($r)),
                Action::make('cancel')->label('Cancel')->icon('heroicon-m-x-mark')->color('gray')
                    ->visible(fn (FeedbackRequest $r) => $r->status === 'pending' && $service()->canManage(auth()->user(), $r))
                    ->schema([Textarea::make('reason')->label('Why is it no longer needed?')->required()->maxLength(500)])
                    ->action(function (FeedbackRequest $record, array $data) use ($service) {
                        try {
                            $service()->cancel($record, (string) $data['reason'], auth()->user());
                            Notification::make()->success()->title('Feedback cancelled')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /**
     * One field per question, by response type (the answers are checked again by FeedbackQuestions::validate).
     *
     * @param  list<array<string, mixed>>  $questions
     * @return array<int, Component>
     */
    public static function answerFields(array $questions): array
    {
        return array_map(function (array $q) {
            $name = 'answers.'.$q['key'];
            $options = array_column($q['options'] ?? [], 'label', 'value');
            $field = match ($q['type']) {
                'single_choice' => Radio::make($name)->options($options),
                'multiple_choice' => CheckboxList::make($name)->options($options)->columns(2),
                'rating' => Radio::make($name)->options(array_combine(range(1, (int) ($q['scale_max'] ?? 5)), array_map('strval', range(1, (int) ($q['scale_max'] ?? 5)))))->inline(),
                'likert' => Radio::make($name)->options(config('peopleos.engagement.likert_options'))->inline(),
                'yes_no' => Radio::make($name)->options(['yes' => 'Yes', 'no' => 'No'])->inline(),
                'number' => TextInput::make($name)->numeric(),
                'date' => DatePicker::make($name)->native(false),
                default => Textarea::make($name)->rows(3)->maxLength(5000),
            };

            return $field->label($q['title'])->required((bool) ($q['mandatory'] ?? false));
        }, $questions);
    }

    /** @return array<int, Component> */
    public static function answerEntries(FeedbackRequest $request): array
    {
        $entries = array_map(fn (array $q) => TextEntry::make('answer_'.$q['key'])->label($q['title'])->state(FeedbackQuestions::display($q, $request->answers[$q['key']] ?? null)), (array) $request->questions);
        $entries[] = TextEntry::make('given')->label('Given')->state(trim(($request->submitter?->name ?? '').' · '.$request->submitted_at?->format('d M Y H:i'), ' ·'));

        return $entries;
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedbackRequests::route('/')];
    }
}

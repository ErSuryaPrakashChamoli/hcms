<?php

namespace App\Filament\Resources\Letters;

use App\Domain\Employment\Models\Employee;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Letters\Pages\ListLetters;
use App\Filament\Resources\Letters\Pages\ViewLetter;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Generated letters (§40): approve, issue, read. Employees see their own issued letters under "Me". */
class LetterResource extends Resource
{
    protected static ?string $model = Letter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->can('letter.issue') || auth()->user()?->can('letter.view') ? 'Letters' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->can('letter.issue') || auth()->user()?->can('letter.view') ? 'Letters' : 'My letters';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'template', 'requester', 'approver']);
        $user = auth()->user();
        if ($user->can('letter.issue') || $user->can('letter.view') || $user->can('letter.manage')) {
            return $query;
        }

        return $query->where('status', 'issued')->whereHas('employee', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'issued' => 'success', 'approved' => 'primary', 'pending_approval' => 'warning', 'rejected' => 'danger', default => 'gray'
        };
    }

    public static function generate(): Action
    {
        return Action::make('generate')->label('Generate letter')->icon(Heroicon::OutlinedDocumentPlus)->color('primary')
            ->visible(fn () => auth()->user()->can('letter.issue'))
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => Employee::query()->with('person')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                Select::make('template_id')->label('Template')->required()->options(fn () => LetterTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                KeyValue::make('extra')->label('Extra variables')->keyLabel('Variable')->valueLabel('Value'),
            ])
            ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->generate(LetterTemplate::query()->findOrFail($data['template_id']), Employee::query()->findOrFail($data['employee_id']), $data['extra'] ?? [], auth()->user()), fn ($l) => "Letter {$l->number} ".($l->status === 'approved' ? 'ready to issue' : 'sent for approval')));
    }

    /** @return array<int, Action> */
    public static function forLetter(): array
    {
        $issue = fn () => auth()->user()->can('letter.issue');

        return [
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Letter $record) => $record->status === 'pending_approval' && $issue())
                ->schema([Textarea::make('note')->maxLength(500)])
                ->action(fn (Letter $record, array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Approved')),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (Letter $record) => in_array($record->status, ['pending_approval', 'approved'], true) && $issue())
                ->schema([Textarea::make('note')->required()->maxLength(500)])
                ->action(fn (Letter $record, array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->reject($record, auth()->user(), $data['note']), 'Rejected')),
            Action::make('issue')->label('Issue')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')
                ->visible(fn (Letter $record) => $record->status === 'approved' && $issue())
                ->requiresConfirmation()->modalDescription('Stores the letter in the employee\'s documents and notifies them.')
                ->action(fn (Letter $record) => ServiceDeskActions::run(fn () => app(Letters::class)->issue($record, auth()->user()), 'Letter issued')),
            Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (Letter $record) => $record->status === 'issued')
                ->action(fn (Letter $record) => response()->streamDownload(fn () => print (app(Letters::class)->html($record)), $record->number.'.html', ['Content-Type' => 'text/html'])),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Letter $record) => "{$record->number} · {$record->subject}")->columns(4)->schema([
                TextEntry::make('employee.person.full_name')->label('Employee'),
                TextEntry::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.letters.types.{$state}", $state)),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.letters.statuses.{$state}", $state)),
                TextEntry::make('template.name')->label('Template')->placeholder('—'),
                TextEntry::make('requester.name')->label('Requested by')->placeholder('—'),
                TextEntry::make('approver.name')->label('Approved by')->placeholder('—'),
                TextEntry::make('issued_at')->dateTime()->placeholder('—'),
                TextEntry::make('review_note')->placeholder('—'),
                TextEntry::make('body')->hiddenLabel()->markdown()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.letters.types.{$state}", $state)),
                TextColumn::make('subject')->limit(50),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.letters.statuses.{$state}", $state)),
                TextColumn::make('created_at')->since()->sortable(),
                TextColumn::make('issued_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.letters.statuses')), SelectFilter::make('type')->options(config('peopleos.letters.types'))])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLetters::route('/'),
            'view' => ViewLetter::route('/{record}'),
        ];
    }
}

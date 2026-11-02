<?php

namespace App\Filament\Resources\LetterSchedules;

use App\Domain\Employment\Models\Employee;
use App\Domain\Letters\Models\LetterSchedule;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\LetterEmails;
use App\Domain\Letters\Services\LetterSchedules;
use App\Filament\Resources\LetterSchedules\Pages\CreateLetterSchedule;
use App\Filament\Resources\LetterSchedules\Pages\EditLetterSchedule;
use App\Filament\Resources\LetterSchedules\Pages\ListLetterSchedules;
use App\Filament\Resources\LetterSchedules\Pages\ViewLetterSchedule;
use App\Filament\Resources\LetterSchedules\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne parity WO-13 (§23.7.2 #2–5, 7, 8, 11: "Select event frequency", "Publish later", event types, applicability, "Save
 * for later use", "View schedules"): letter schedules, kept by people who issue letters. Written through LetterSchedules.
 */
class LetterScheduleResource extends Resource
{
    protected static ?string $model = LetterSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Letters';

    protected static ?string $navigationLabel = 'Letter schedules';

    protected static ?string $modelLabel = 'letter schedule';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        $event = fn (Get $get) => $get('trigger') === 'event';

        return $schema->columns(2)->components([
            Section::make('Letter')->columns(2)->schema([
                TextInput::make('name')->label('Title')->required()->maxLength(255),
                Select::make('letter_template_id')->label('Template')->required()->searchable()
                    ->options(fn () => LetterTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('signatory_ids')->label('Signatories (up to four, in signing order)')->multiple()->maxItems(4)->placeholder('As on the template')
                    ->options(fn () => LetterTemplateResource::signatories())->columnSpanFull(),
                KeyValue::make('extra')->label('Extra variables')->keyLabel('Variable')->valueLabel('Value')->columnSpanFull()
                    ->helperText('Values for variables the template asks for that are not employee details.'),
            ]),
            Section::make('When')->columns(2)->schema([
                Radio::make('trigger')->label('Select event frequency')->options(LetterSchedule::TRIGGERS)->default('one_time')->required()->live()->inline(),
                Radio::make('publish')->label('Publish')->options(['now' => 'Publish now', 'later' => 'Publish later'])->default('now')->live()->inline()
                    ->visible(fn (Get $get) => ! $event($get)),
                DateTimePicker::make('run_at')->label('Publish date and time')->seconds(false)->minDate(fn () => now()->startOfDay())
                    ->visible(fn (Get $get) => ! $event($get) && $get('publish') === 'later')->required(fn (Get $get) => ! $event($get) && $get('publish') === 'later'),
                Select::make('event')->label('Event')->options(fn () => (array) config('peopleos.letters.schedule_events'))
                    ->visible($event)->required($event),
                Radio::make('offset_direction')->label('Before or after the event')->options(['before' => 'Before', 'after' => 'After'])->default('after')->inline()->visible($event),
                TextInput::make('offset_days')->label('Number of days (0 = on the day)')->numeric()->minValue(0)->maxValue(365)->default(0)->visible($event),
            ]),
            Section::make('Who')->schema([
                Radio::make('audience')->label('Applicability')->options(LetterSchedule::AUDIENCES)->default('employees')->required()->live(),
                Select::make('employee_ids')->label(fn (Get $get) => $get('audience') === 'upcoming_joiners' ? 'Upcoming joiners (empty = every upcoming joiner)' : 'Employees')
                    ->multiple()->searchable()->options(fn (Get $get) => self::employees($get('audience') === 'upcoming_joiners'))
                    ->visible(fn (Get $get) => in_array($get('audience'), ['employees', 'upcoming_joiners'], true))
                    ->required(fn (Get $get) => $get('audience') === 'employees'),
                AudienceCriteriaSchema::section('audience_criteria', 'Employees organisation-wise')->visible(fn (Get $get) => $get('audience') === 'criteria'),
            ]),
            Section::make('Publishing')->columns(2)->schema([
                Toggle::make('issue')->label('Issue each letter at once when the template needs no approval')->default(true)->inline(false)
                    ->helperText('Letters whose template needs approval wait for it, as usual.'),
                CheckboxList::make('email_to')->label('Also e-mail the issued letter to')->visible(fn () => app(LetterEmails::class)->enabled())
                    ->options(fn () => auth()->user()?->hasPermission('employee.sensitive.view') ? LetterEmails::RECIPIENTS : ['work' => LetterEmails::RECIPIENTS['work']]),
                Toggle::make('save_for_later')->label('Save for later use (does not run until started)')->default(false)->inline(false),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (LetterSchedule $record) => $record->name)->columns(4)->schema([
                TextEntry::make('template.name')->label('Template'),
                TextEntry::make('frequency')->label('Event frequency')->state(fn (LetterSchedule $record) => $record->frequencyLabel()),
                TextEntry::make('occurrence')->label('Occurrence')->state(fn (LetterSchedule $record) => $record->occurrenceLabel()),
                TextEntry::make('run_at')->label('Scheduled date')->dateTime()->placeholder('—'),
                TextEntry::make('audience')->label('Applicability')->formatStateUsing(fn (string $state) => LetterSchedule::AUDIENCES[$state] ?? $state),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => LetterSchedule::STATUSES[$state] ?? $state),
                TextEntry::make('creator.name')->label('Set up by')->placeholder('—'),
                TextEntry::make('created_at')->label('Created on')->dateTime(),
                TextEntry::make('last_run_on')->label('Last run')->date()->placeholder('—'),
                TextEntry::make('letters_count')->label('Letters generated'),
                TextEntry::make('last_error')->label('Note')->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Title')->searchable(),
                TextColumn::make('template.name')->label('Template name'),
                TextColumn::make('frequency')->label('Event frequency')->state(fn (LetterSchedule $record) => $record->frequencyLabel()),
                TextColumn::make('occurrence')->label('Occurrence')->state(fn (LetterSchedule $record) => $record->occurrenceLabel()),
                TextColumn::make('run_at')->label('Scheduled date')->dateTime()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => LetterSchedule::STATUSES[$state] ?? $state),
                TextColumn::make('letters_count')->label('Letters'),
                TextColumn::make('created_at')->label('Created on')->date()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(LetterSchedule::STATUSES), SelectFilter::make('trigger')->label('Event frequency')->options(LetterSchedule::TRIGGERS)])
            ->recordActions([ViewAction::make()->label('Open'), EditAction::make()->visible(fn (LetterSchedule $record) => auth()->user()->can('update', $record)), ...self::lifecycleActions()]);
    }

    /** Start, pause, cancel, copy ("Save for later use" → use it). @return list<Action> */
    public static function lifecycleActions(): array
    {
        $schedules = fn () => app(LetterSchedules::class);

        return [
            Action::make('activate')->label('Start')->icon(Heroicon::OutlinedPlay)->color('success')
                ->visible(fn (LetterSchedule $record) => in_array($record->status, ['saved', 'paused'], true) && auth()->user()->can('update', $record))
                ->requiresConfirmation()
                ->action(fn (LetterSchedule $record) => ServiceDeskActions::run(fn () => $schedules()->activate($record, auth()->user()), 'Schedule started')),
            Action::make('pause')->label('Pause')->icon(Heroicon::OutlinedPause)->color('gray')
                ->visible(fn (LetterSchedule $record) => $record->status === 'active' && auth()->user()->can('update', $record))
                ->action(fn (LetterSchedule $record) => ServiceDeskActions::run(fn () => $schedules()->pause($record, auth()->user()), 'Schedule paused')),
            Action::make('copy')->label('Use again')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->visible(fn () => auth()->user()->can('create', LetterSchedule::class))
                ->action(fn (LetterSchedule $record) => ServiceDeskActions::run(fn () => $schedules()->duplicate($record, auth()->user()), 'Copy saved for later use',
                    fn (LetterSchedule $copy) => [Action::make('open')->label('Open')->url(self::getUrl('edit', ['record' => $copy]))])),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (LetterSchedule $record) => ! in_array($record->status, ['completed', 'cancelled'], true) && auth()->user()->can('update', $record))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (LetterSchedule $record, array $data) => ServiceDeskActions::run(fn () => $schedules()->cancel($record, auth()->user(), (string) $data['reason']), 'Schedule cancelled')),
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success', 'paused' => 'warning', 'cancelled' => 'danger', 'completed' => 'primary', default => 'gray',
        };
    }

    /** @return array<int, string> */
    private static function employees(bool $joiners): array
    {
        return Employee::query()->with('person')
            ->when($joiners, fn ($q) => $q->whereIn('lifecycle_state', ['pre_employee', 'preboarding']), fn ($q) => $q->employed())
            ->orderBy('employee_code')->limit(1000)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLetterSchedules::route('/'),
            'create' => CreateLetterSchedule::route('/create'),
            'view' => ViewLetterSchedule::route('/{record}'),
            'edit' => EditLetterSchedule::route('/{record}/edit'),
        ];
    }
}

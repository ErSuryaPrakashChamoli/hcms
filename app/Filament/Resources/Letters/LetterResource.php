<?php

namespace App\Filament\Resources\Letters;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\LetterDownloads;
use App\Domain\Letters\Services\LetterEmails;
use App\Domain\Letters\Services\Letters;
use App\Domain\Letters\Services\LetterSalaryChecks;
use App\Domain\Letters\Support\LetterVariables;
use App\Domain\Letters\Support\PolicyLetters;
use App\Domain\Organisation\Models\EmploymentType;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Letters\Pages\ListLetters;
use App\Filament\Resources\Letters\Pages\ViewLetter;
use App\Filament\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
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
        $query = parent::getEloquentQuery()->with(['employee.person', 'template', 'signatory', 'requester', 'approver']);
        $user = auth()->user();
        if ($user->can('letter.issue') || $user->can('letter.view') || $user->can('letter.manage')) {
            return $query;
        }

        return $query->where('status', 'issued')->whereHas('employee', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'issued' => 'success', 'approved' => 'primary', 'pending_approval' => 'warning', 'rejected', 'revoked' => 'danger', default => 'gray'
        };
    }

    public static function generate(): Action
    {
        return Action::make('generate')->label('Generate letter')->icon(Heroicon::OutlinedDocumentPlus)->color('primary')
            ->visible(fn () => auth()->user()->can('letter.issue'))
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => Employee::query()->with('person')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                Select::make('template_id')->label('Template')->required()->options(fn () => LetterTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('letter_signatory_id')->label('Signing authority')->placeholder('As on the template')->options(fn () => LetterTemplateResource::signatories()),
                // HROne parity WO-13 (§23.7.1 #8): up to four signatories; blank = the template's.
                ...self::moreSignatoryFields(),
                KeyValue::make('extra')->label('Extra variables')->keyLabel('Variable')->valueLabel('Value')
                    ->helperText('Values for variables the template asks for that are not employee details, such as purpose = Visa application.'),
            ])
            ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->generate(LetterTemplate::query()->findOrFail($data['template_id']), Employee::query()->findOrFail($data['employee_id']), $data['extra'] ?? [], auth()->user(),
                signatory: filled($data['letter_signatory_id'] ?? null) ? LetterSignatory::query()->findOrFail($data['letter_signatory_id']) : null, others: self::othersFrom($data)), fn ($l) => "Letter {$l->number} ".($l->status === 'approved' ? 'ready to issue' : 'sent for approval')));
    }

    /** HROne parity WO-13: the second, third and fourth signatory pickers (blank = as on the template). @return list<Select> */
    public static function moreSignatoryFields(string $placeholder = 'As on the template'): array
    {
        return array_map(fn (int $n) => Select::make("letter_signatory_{$n}_id")->label(LetterVariables::MORE_SIGNATORIES[$n].' signatory')->placeholder($placeholder)
            ->options(fn () => LetterTemplateResource::signatories()), array_keys(LetterVariables::MORE_SIGNATORIES));
    }

    /**
     * The chosen second to fourth signatories by position, or null when none is chosen (then the template's apply).
     *
     * @return array<int, LetterSignatory>|null
     */
    public static function othersFrom(array $data): ?array
    {
        $others = [];
        foreach (array_keys(LetterVariables::MORE_SIGNATORIES) as $n) {
            if (filled($data["letter_signatory_{$n}_id"] ?? null)) {
                $others[$n] = LetterSignatory::query()->where('status', 'active')->findOrFail((int) $data["letter_signatory_{$n}_id"]);
            }
        }

        return $others === [] ? null : $others;
    }

    /** Readers of the letters register (HR): the employee's own "My letters" view gets none of the register tools. */
    public static function readsLetters(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('letter.issue') || $user->can('letter.view') || $user->can('letter.manage'));
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
                ->visible(fn (Letter $record) => $record->status === 'issued' || ($record->status === 'revoked' && self::readsLetters()))
                ->action(function (Letter $record) {
                    $file = app(Letters::class)->download($record, auth()->user());

                    return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => $file['mime']]);
                }),
            Action::make('draft')->label(fn (Letter $record) => $record->isDocx() ? 'Download Word draft' : 'Download draft')->icon(Heroicon::OutlinedDocumentMagnifyingGlass)->color('gray')
                ->visible(fn (Letter $record) => in_array($record->status, ['pending_approval', 'approved'], true) && $issue())
                ->action(function (Letter $record) {
                    $file = app(Letters::class)->draft($record, auth()->user());

                    return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => $file['mime']]);
                }),
            ...self::lifecycleActions(),
        ];
    }

    /**
     * HROne parity WO-13: e-mail an issued letter, revoke it (D-WO5) and generate it again (D-WO6). For people who issue
     * letters; nothing is ever deleted.
     *
     * @return array<int, Action>
     */
    public static function lifecycleActions(): array
    {
        $issue = fn () => auth()->user()->can('letter.issue');

        return [
            Action::make('email')->label('E-mail the letter')->icon(Heroicon::OutlinedEnvelope)->color('gray')
                ->visible(fn (Letter $record) => $record->status === 'issued' && $issue() && app(LetterEmails::class)->enabled())
                ->modalDescription('The letter goes as an attachment, from your organisation\'s e-mail. The message is not kept in the audit trail.')
                ->schema([
                    CheckboxList::make('to')->label('Send to')->required()->default(['work'])
                        ->options(fn () => auth()->user()?->hasPermission('employee.sensitive.view') ? LetterEmails::RECIPIENTS : ['work' => LetterEmails::RECIPIENTS['work']]),
                    Select::make('cc_users')->label('CC: people of your organisation')->multiple()->searchable()->options(fn () => LetterEmails::people()),
                    TagsInput::make('cc_addresses')->label('CC: other e-mail addresses')->placeholder('name@example.com')->nestedRecursiveRules(['email']),
                    Select::make('bcc_users')->label('BCC: people of your organisation')->multiple()->searchable()->options(fn () => LetterEmails::people()),
                    TagsInput::make('bcc_addresses')->label('BCC: other e-mail addresses')->placeholder('name@example.com')->nestedRecursiveRules(['email']),
                    TextInput::make('subject')->maxLength(200)->placeholder('The letter\'s subject'),
                    Textarea::make('message')->rows(3)->maxLength(5000)->placeholder('Please find attached your letter.'),
                ])
                ->action(fn (Letter $record, array $data) => ServiceDeskActions::run(fn () => app(LetterEmails::class)->send($record, auth()->user(), (array) ($data['to'] ?? ['work']), [
                    'cc' => ['users' => (array) ($data['cc_users'] ?? []), 'addresses' => (array) ($data['cc_addresses'] ?? [])],
                    'bcc' => ['users' => (array) ($data['bcc_users'] ?? []), 'addresses' => (array) ($data['bcc_addresses'] ?? [])],
                ], $data['subject'] ?? null, $data['message'] ?? null), 'Letter e-mailed')),
            Action::make('supersede')->label('Generate again')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn (Letter $record) => in_array($record->status, ['pending_approval', 'approved', 'issued'], true) && $issue() && $record->replacement === null)
                ->modalDescription('A new letter is generated and linked to this one — nothing is overwritten. A letter not yet issued is revoked now; an issued one stays with the employee until its replacement is issued, then it is revoked (kept, hidden from the employee).')
                ->schema([
                    Select::make('template_id')->label('Template')->placeholder('The same template')
                        ->options(fn () => LetterTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                    Textarea::make('reason')->label('Why')->required()->maxLength(500),
                    Toggle::make('issue')->label('Issue the new letter at once when it needs no approval')->default(false),
                ])
                ->action(fn (Letter $record, array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->supersede($record, auth()->user(), (string) $data['reason'],
                    filled($data['template_id'] ?? null) ? LetterTemplate::query()->findOrFail($data['template_id']) : null, (bool) ($data['issue'] ?? false)),
                    fn (Letter $new) => "Letter {$new->number} ".match ($new->status) {
                        'issued' => 'issued', 'approved' => 'ready to issue', default => 'sent for approval'
                    }, fn (Letter $new) => [Action::make('open')->label('Open')->url(self::getUrl('view', ['record' => $new]))])),
            Action::make('revoke')->label('Revoke')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (Letter $record) => $record->status === 'issued' && $issue())
                ->modalDescription('The letter is withdrawn from the employee: it is marked revoked and its document is hidden from them. The letter and its file are kept; nothing is deleted.')
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Letter $record, array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->revoke($record, auth()->user(), (string) $data['reason']), 'Letter revoked')),
        ];
    }

    /** HROne parity WO-13 (§23.7.1 #6): a zip of the issued letters among the chosen ones, prepared in the background. */
    public static function requestDownload(array $ids, string $selection): void
    {
        ServiceDeskActions::run(fn () => app(LetterDownloads::class)->request(auth()->user(), $ids, $selection),
            'Preparing the zip: you will be notified when it is ready to download');
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
                TextEntry::make('format')->label('Made as')->formatStateUsing(fn (string $state) => match ($state) {
                    'docx' => 'Word file', 'html' => 'Written in PeopleOS', default => 'Markdown'
                }),
                TextEntry::make('signatory.name')->label('Signed by')->placeholder('—'),
                // HROne parity WO-13: further signatories, the process, the letters it replaces / is replaced by, revocation.
                TextEntry::make('other_signatories')->label('Also signed by')->visible(fn (Letter $record) => $record->letter_signatory_2_id || $record->letter_signatory_3_id || $record->letter_signatory_4_id)
                    ->state(fn (Letter $record) => collect([$record->signatory2, $record->signatory3, $record->signatory4])->filter()->pluck('name')->implode(', ')),
                TextEntry::make('process')->label('Process')->state(fn (Letter $record) => PolicyLetters::label($record))->visible(fn () => self::readsLetters()),
                TextEntry::make('supersedes.number')->label('Replaces')->placeholder('—')->visible(fn (Letter $record) => $record->supersedes_letter_id !== null && self::readsLetters())
                    ->url(fn (Letter $record) => $record->supersedes_letter_id ? self::getUrl('view', ['record' => $record->supersedes_letter_id]) : null),
                TextEntry::make('replaced_by')->label('Replaced by')->visible(fn (Letter $record) => self::readsLetters() && $record->supersededBy()->exists())
                    ->state(fn (Letter $record) => $record->supersededBy()->orderByDesc('id')->get(['number', 'status'])->map(fn (Letter $l) => $l->number.' ('.config("peopleos.letters.statuses.{$l->status}", $l->status).')')->implode(', ')),
                TextEntry::make('revoked_at')->label('Revoked')->dateTime()->visible(fn (Letter $record) => $record->status === 'revoked' && self::readsLetters())
                    ->helperText(fn (Letter $record) => trim(($record->revoker?->name ? 'By '.$record->revoker->name.'. ' : '').(string) $record->revoke_reason)),
                ViewEntry::make('salary_checks')->hiddenLabel()->columnSpanFull()->view('filament.letters.salary-checks')
                    ->visible(fn (Letter $record) => self::readsLetters() && $record->usesSalary())
                    ->viewData(fn (Letter $record) => ['checks' => app(LetterSalaryChecks::class)->forLetter($record)]),
                ViewEntry::make('body')->hiddenLabel()->columnSpanFull()->view('filament.letters.letter-body')
                    ->viewData(fn (Letter $record) => ['page' => $record->isDocx() ? null : app(Letters::class)->html($record), 'text' => $record->isDocx() ? $record->body : null]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.letters.types.{$state}", $state)),
                TextColumn::make('subject')->limit(50),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.letters.statuses.{$state}", $state)),
                TextColumn::make('created_at')->since()->sortable(),
                TextColumn::make('issued_at')->dateTime()->placeholder('—'),
                // HROne parity WO-13 (§23.7.1 #2–4): letter process, template, generated by and signatory, for the letters register only
                // (toggled off by default; the per-type views show process and generated by).
                TextColumn::make('process')->label('Process')->state(fn (Letter $record) => PolicyLetters::label($record))
                    ->visible(fn () => self::readsLetters())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('template.name')->label('Template')->placeholder('—')->visible(fn () => self::readsLetters())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requester.name')->label('Generated by')->placeholder('—')->visible(fn () => self::readsLetters())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('signatory.name')->label('Signed by')->placeholder('—')->visible(fn () => self::readsLetters())->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.letters.statuses')), SelectFilter::make('type')->options(config('peopleos.letters.types')),
                // HROne parity WO-2: the shared employee filters and the date the letter was generated.
                ...EmployeeFilters::forRegister('employee', 'created_at', 'Generated', excludeLeavers: false),
                ...self::registerFilters()])
            ->recordActions([ViewAction::make()->label('Open')])
            ->toolbarActions([BulkRecordActions::selected('letters', ListLetters::BULK_OPERATIONS), self::downloadSelected()]);
    }

    /**
     * HROne parity WO-13 (§23.7.1 #3): template, signatory, generated by, process and employment type — for the letters
     * register only (people who read letters), not on "My letters".
     *
     * @return list<SelectFilter>
     */
    private static function registerFilters(): array
    {
        return [
            SelectFilter::make('letter_template_id')->label('Letter template')->visible(fn () => self::readsLetters())
                ->options(fn () => LetterTemplate::query()->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('signatory')->label('Signatory')->visible(fn () => self::readsLetters())->options(fn () => LetterTemplateResource::signatories())
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->where(fn (Builder $q) => $q->where('letter_signatory_id', $data['value'])->orWhere('letter_signatory_2_id', $data['value'])
                        ->orWhere('letter_signatory_3_id', $data['value'])->orWhere('letter_signatory_4_id', $data['value']))
                    : $query),
            SelectFilter::make('requested_by')->label('Generated by')->visible(fn () => self::readsLetters())->searchable()
                ->options(fn () => User::forCurrentTenant()->whereIn('id', Letter::query()->select('requested_by')->whereNotNull('requested_by'))->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('process')->label('Letter process')->visible(fn () => self::readsLetters())->options(PolicyLetters::options()),
            SelectFilter::make('employment_type')->label('Employment type')->visible(fn () => self::readsLetters())
                ->options(fn () => EmploymentType::query()->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereHas('employee.currentPosition', fn (Builder $p) => $p->where('employment_type_id', $data['value']))
                    : $query),
        ];
    }

    /** HROne parity WO-13: the selected rows' issued letters as one zip (queued). */
    private static function downloadSelected(): BulkAction
    {
        return BulkAction::make('downloadZip')->label('Download as zip')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn () => LetterDownloads::mayDownload(auth()->user()))
            ->fetchSelectedRecords(false)->deselectRecordsAfterCompletion()
            ->requiresConfirmation()->modalHeading('Download the selected letters')
            ->modalDescription('Issued letters you may open go in one zip, prepared in the background (you are notified when it is ready). Each letter in it is recorded as downloaded.')
            ->action(fn (BulkAction $action) => self::requestDownload(collect($action->getSelectedRecords())->all(), 'selected'));
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

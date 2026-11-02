<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\DocumentShareBatch;
use App\Domain\Documents\Models\DocumentShareItem;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Documents\Services\DocumentShares;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeSelection;
use App\Domain\Experience\Services\BulkRuns;
use App\Filament\Resources\DocumentShareBatches\DocumentShareBatchResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Support\Storage\StagedUpload;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * HROne parity WO-14 (§23.7.3, §23.7.4, §23.9 #20 "Share letters / documents"): the "Share documents" step wizard
 * (Document → Files → Who → Notify → Review), "Share letters" (the letters made in PeopleOS: the Letters register's letters
 * ready to issue, where "Generate & publish" publishes them), the sheet template, the errors CSV and "Unpublish" (one
 * document, the documents ticked, a whole share). Everything goes through DocumentShares; nothing here decides who may
 * do what beyond hiding what the person cannot use.
 */
final class DocumentShareActions
{
    /** The wizard's "Who" fields read for a common file (EmployeeSelection criteria). */
    private const WHO_KEYS = ['source', 'codes', 'lifecycle_state', 'joined_from', 'joined_until', 'created_from', 'created_until', 'everyone', ...EmployeeSelection::ORGANISATION];

    public static function allowed(): bool
    {
        $user = auth()->user();

        return DocumentShares::mayShare($user) && $user->hasPermission('employee.view');
    }

    /** "Share documents" (the register's header; `?action=shareDocuments` opens it directly). */
    public static function share(string $name = 'shareDocuments'): Action
    {
        $sheet = fn (Get $get) => $get('mode') === 'sheet';
        $common = fn (Get $get) => $get('mode') === 'common';

        return Action::make($name)
            ->label('Share documents')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => self::allowed())
            ->modalHeading('Share documents with employees')
            ->modalDescription('Files made outside PeopleOS (Form 16, insurance e-cards, ID cards, circulars …) shared with many employees at once. Each employee finds theirs in My HR → Documents. Anything that cannot be shared is listed with the reason.')
            ->modalSubmitActionLabel('Share')
            ->fillForm(fn () => ['mode' => 'sheet', 'source' => 'filters', 'notify_in_app' => true, 'email' => false, 'email_to' => 'work'])
            ->steps(fn ($livewire) => [
                Step::make('Document')->description('Type, title and message')->schema([
                    Select::make('document_type_id')->label('Document type')->required()->searchable()
                        ->options(fn () => DocumentShares::types()->pluck('name', 'id')->all())
                        ->helperText('Only active types employees may see (Setup → Document types).'),
                    TextInput::make('title')->label('Title')->required()->maxLength(255)->placeholder('e.g. Form 16 for 2025-26'),
                    Textarea::make('message')->label('Message to employees (optional)')->rows(3)->maxLength(DocumentShares::limit('message_max', 1000)),
                ]),
                Step::make('Files')->description('A zip with a sheet, or one file')->schema([
                    Radio::make('mode')->label('How are the files given?')->options(DocumentShareBatch::MODES)->required()->live(),
                    Text::make(new HtmlString('<strong>1.</strong> Download the sheet template (button "Sheet template" on the register) and fill one row per employee and file: the employee code and the file\'s name (with or without its extension). <strong>2.</strong> Upload the sheet. <strong>3.</strong> Upload all the files as one zip.'))
                        ->visible($sheet),
                    FileUpload::make('sheet')->label('Sheet (employee code, file name)')->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize(2048)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])->storeFileNamesIn('sheet_name')
                        ->visible($sheet)->required($sheet),
                    FileUpload::make('zip')->label('Zip of the files')->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize(DocumentShares::limit('zip_max_kb', 12288))
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip'])->storeFileNamesIn('zip_name')
                        ->helperText('At most '.DocumentShares::limit('zip_max_entries', 2000).' files; each one is checked like any upload ('.implode(', ', (array) config('peopleos.documents.mimes')).', up to '.(int) config('peopleos.documents.max_kb').' KB).')
                        ->visible($sheet)->required($sheet),
                    FileUpload::make('file')->label('The file everyone gets')->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize((int) config('peopleos.documents.max_kb'))
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                        ->storeFileNamesIn('file_name')->visible($common)->required($common),
                ]),
                Step::make('Who')->description('Employees who get the file')->schema([
                    Text::make('The sheet names who gets which file: each row\'s employee code. Codes nobody in your access has are listed as failed.')->visible($sheet),
                    Group::make(WorkforceBulkWizards::whoFields($livewire, 'share', false))->visible($common),
                ]),
                Step::make('Notify')->description('In-app, e-mail')->schema([
                    Toggle::make('notify_in_app')->label('Tell each employee in PeopleOS (notification)')->default(true),
                    Toggle::make('email')->label('Also e-mail the document to each employee')->default(false)->live()
                        ->helperText('Off by default. Only to the employee, with the document attached — never a copy to anyone else.')
                        ->visible(fn () => DocumentShares::emailAvailable()),
                    Radio::make('email_to')->label('Share on')->required(fn (Get $get) => (bool) $get('email'))
                        ->options(fn () => auth()->user()?->hasPermission('employee.sensitive.view') ? DocumentShareBatch::EMAIL_TO : ['work' => DocumentShareBatch::EMAIL_TO['work']])
                        ->visible(fn (Get $get) => (bool) $get('email') && DocumentShares::emailAvailable()),
                ]),
                Step::make('Review')->description('What is shared, then confirm')->schema([
                    Text::make(fn (Get $get) => self::summary($livewire, $get))->columnSpanFull(),
                    TextInput::make('confirm_count')
                        ->label(fn (Get $get) => 'Type '.(self::count($livewire, $get) ?? 0).' to confirm')
                        ->helperText('More than '.BulkRuns::confirmOver().' employees get the same file: type the number to confirm.')
                        ->visible(fn (Get $get) => $common($get) && BulkRuns::needsTypedCount((int) self::count($livewire, $get)))
                        ->required(fn (Get $get) => $common($get) && BulkRuns::needsTypedCount((int) self::count($livewire, $get)))
                        ->in(fn (Get $get) => [(string) self::count($livewire, $get)])->validationMessages(['in' => 'Type exactly the number of employees.']),
                ]),
            ])
            ->action(fn (array $data) => self::start($data));
    }

    /** "Share letters": letters made in PeopleOS are published from the Letters register (ready to issue → Generate & publish). */
    public static function shareLetters(string $name = 'shareLetters'): Action
    {
        return Action::make($name)->label('Share letters')->icon(Heroicon::OutlinedEnvelope)->color('gray')
            ->visible(fn () => LetterResource::canAccess() && (auth()->user()?->hasPermission('letter.issue') ?? false))
            ->url(fn () => LetterResource::getUrl('index', ['view' => 'to_issue']));
    }

    public static function template(): Action
    {
        return Action::make('shareTemplate')->label('Sheet template')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn () => self::allowed())
            ->action(fn () => response()->streamDownload(fn () => print (DocumentShares::template()), 'document-share-sheet.csv', ['Content-Type' => 'text/csv']));
    }

    public static function downloadErrors(): Action
    {
        return Action::make('downloadErrors')->label('Download errors')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (DocumentShareBatch $record) => $record->isFinished() && ($record->failed > 0 || filled($record->unused_files)) && DocumentShares::mayShare(auth()->user()))
            ->action(fn (DocumentShareBatch $record) => response()->streamDownload(fn () => print (app(DocumentShares::class)->errorCsv($record)), "document-share-{$record->id}-errors.csv", ['Content-Type' => 'text/csv']));
    }

    /** Unpublish a whole share: every document of it still shared, inside the person's access. */
    public static function unpublishBatch(): Action
    {
        return Action::make('unpublishBatch')->label('Unpublish')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
            ->visible(fn (DocumentShareBatch $record) => $record->isFinished() && $record->unpublished_at === null && DocumentShares::mayShare(auth()->user())
                && DocumentShareItem::query()->where('document_share_batch_id', $record->id)->where('status', 'shared')->exists())
            ->modalHeading('Unpublish this share')
            ->modalDescription('Every document of this share is hidden from the employees (they no longer see or open it). The documents and their files are kept, and the share stays in the register.')
            ->schema([self::reasonField()])
            ->action(fn (DocumentShareBatch $record, array $data) => self::report(fn () => app(DocumentShares::class)->unpublish($record, auth()->user(), (string) $data['reason'])));
    }

    public static function unpublishItem(): Action
    {
        return Action::make('unpublish')->label('Unpublish')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
            ->visible(fn (DocumentShareItem $record) => $record->status === 'shared' && DocumentShares::mayShare(auth()->user()))
            ->modalDescription('The employee no longer sees or opens this document. It is kept, with its file.')
            ->schema([self::reasonField()])
            ->action(fn (DocumentShareItem $record, array $data) => ServiceDeskActions::run(fn () => app(DocumentShares::class)->unpublishItem($record, auth()->user(), (string) $data['reason']), 'Document unpublished'));
    }

    public static function unpublishSelected(): BulkAction
    {
        return BulkAction::make('unpublishSelected')->label('Unpublish')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
            ->visible(fn () => DocumentShares::mayShare(auth()->user()))
            ->modalDescription('The employees no longer see or open these documents. They are kept, with their files. Documents no longer shared are skipped.')
            ->schema([self::reasonField()])
            ->deselectRecordsAfterCompletion()
            ->action(fn ($records, array $data) => self::report(fn () => app(DocumentShares::class)->unpublish($records, auth()->user(), (string) $data['reason'])));
    }

    /** Open a shared document (the documents' own signed, authorised download; document.view within the scope). */
    public static function viewDocument(): Action
    {
        return Action::make('viewDocument')->label('View')->icon(Heroicon::OutlinedEye)->color('gray')
            ->visible(fn (DocumentShareItem $record) => $record->employee_document_id !== null && $record->document !== null && (auth()->user()?->can('view', $record->document) ?? false))
            ->url(fn (DocumentShareItem $record) => $record->document instanceof EmployeeDocument ? app(Documents::class)->downloadUrl($record->document) : null)
            ->openUrlInNewTab();
    }

    /** @param  array<string, mixed>  $data */
    private static function start(array $data): void
    {
        $files = [];
        $staged = [];
        try {
            foreach (['zip' => 'zip_name', 'sheet' => 'sheet_name', 'file' => 'file_name'] as $key => $nameKey) {
                $path = $data[$key] ?? null;
                if (is_array($path)) {
                    $path = array_values($path)[0] ?? null;
                }
                if (! is_string($path) || $path === '') {
                    continue;
                }
                $copy = StagedUpload::toUploadedFile($path);
                $staged[] = [$path, $copy];
                $original = $data[$nameKey] ?? null;
                $original = is_array($original) ? (array_values($original)[0] ?? null) : $original;
                $files[$key] = new UploadedFile($copy->getPathname(), is_string($original) && $original !== '' ? basename($original) : basename($path), $copy->getClientMimeType(), null, true);
            }
            $selection = array_intersect_key($data, array_flip(self::WHO_KEYS));
            $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
            $batch = app(DocumentShares::class)->start(auth()->user(), [
                'document_type_id' => $data['document_type_id'] ?? null, 'title' => $data['title'] ?? null, 'message' => $data['message'] ?? null, 'mode' => $data['mode'] ?? null,
                'notify_in_app' => (bool) ($data['notify_in_app'] ?? true), 'email' => (bool) ($data['email'] ?? false), 'email_to' => $data['email_to'] ?? 'work', 'selection' => $selection,
            ], $files, $confirmed);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not shared')->body($e->getMessage())->send();

            return;
        } finally {
            foreach ($staged as [$path, $copy]) {
                StagedUpload::discard($path, $copy);
            }
        }
        $batch->refresh();
        $open = Action::make('open')->label('See each row')->url(DocumentShareBatchResource::getUrl('view', ['record' => $batch]));
        $notification = $batch->isFinished()
            ? Notification::make()->title('Documents shared: '.$batch->summary())->body($batch->failed > 0 ? 'Some rows were not shared; the share lists each one with the reason.' : 'Shared with every employee.')
            : Notification::make()->title('Share queued for '.$batch->total.' row(s)')->body('You will be notified when it is done.');
        $notification->status($batch->status === 'failed' ? 'danger' : ($batch->failed > 0 ? 'warning' : 'success'))->actions([$open])->send();
    }

    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('Why are they unpublished?')->required()->rows(2)->maxLength(500);
    }

    /** @param  callable(): array{unpublished: int, skipped: int}  $callback */
    private static function report(callable $callback): void
    {
        ServiceDeskActions::run($callback, fn (array $r) => $r['unpublished'].' document(s) unpublished'.($r['skipped'] > 0 ? ", {$r['skipped']} skipped (no longer shared, or not in your access)" : ''));
    }

    private static function count(object $livewire, Get $get): ?int
    {
        if ($get('mode') !== 'common') {
            return null;
        }

        return rescue(fn () => count(WorkforceBulkWizards::selectedIds($livewire, WorkforceBulkWizards::state($get))), null, false);
    }

    private static function summary(object $livewire, Get $get): HtmlString|string
    {
        $type = DocumentShares::types()->firstWhere('id', (int) $get('document_type_id'));
        $what = '<strong>'.e((string) ($get('title') ?: 'Untitled')).'</strong> ('.e($type?->name ?? 'no document type').')';
        $tell = array_filter([(bool) $get('notify_in_app') ? 'a notification in PeopleOS' : null, (bool) $get('email') ? 'an e-mail with the document to the '.mb_strtolower(DocumentShareBatch::EMAIL_TO[$get('email_to') ?? 'work'] ?? 'work e-mail') : null]);
        $tellLine = 'Employees get '.($tell === [] ? 'no notification (they find it in My HR → Documents)' : implode(' and ', $tell)).'.';
        if ($get('mode') !== 'common') {
            return new HtmlString($what.' is shared from the zip, one file per row of the sheet. Each row is checked when the share runs (the employee in your access, the file in the zip, the file\'s type and size); anything not shared is listed with the reason.<br>'.e($tellLine));
        }
        try {
            $ids = WorkforceBulkWizards::selectedIds($livewire, WorkforceBulkWizards::state($get));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        if ($ids === []) {
            return 'Nobody matches: go back and change who the document is for.';
        }
        $max = DocumentShares::limit('max_rows', 2000);
        if (count($ids) > $max) {
            return "More than {$max} employees match: narrow the choice or share in several batches.";
        }
        $preview = Employee::query()->with('person')->whereKey(array_slice($ids, 0, 10))->orderBy('employee_code')->get()->map(fn (Employee $e) => e($e->auditLabel()))->all();
        $more = count($ids) > count($preview) ? '<br>… and '.(count($ids) - count($preview)).' more.' : '';

        return new HtmlString('<strong>'.count($ids).' employee(s)</strong> get '.$what.' (each checked again when the share runs):<br>'.implode('<br>', $preview).$more.'<br>'.e($tellLine));
    }
}

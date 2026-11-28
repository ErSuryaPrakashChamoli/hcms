<?php

namespace App\Filament\Pages;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\DocumentUploadBatch;
use App\Domain\Documents\Models\DocumentUploadItem;
use App\Domain\Documents\Services\DocumentBulkUploads;
use App\Support\Storage\StagedUpload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Employee documents upload (Admin → Data import → Employee documents): many employees' documents at once. Upload the
 * zips — one per employee, named "code_name", or one zip with a folder or zip per employee → check the matches: every file
 * listed under its employee with the document type its name says (change the type or dates, skip or include files) →
 * upload (queued) and follow the results. Everything goes through DocumentBulkUploads; this page only hides what the
 * person cannot use. Its slug sits under Data import, so it keeps that area's bar and rail place.
 */
class EmployeeDocumentUpload extends Page implements HasTable
{
    use InteractsWithTable;

    public const STEPS = ['Upload zips', 'Check matches', 'Upload'];

    // In the Admin section's Data import area, beside Data import and Recent uploads (ExperienceNavigation), so the area
    // bar, All modules and the search list it.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Employee documents';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'data-import/employee-documents';

    protected static ?string $title = 'Employee documents upload';

    protected string $view = 'filament.pages.employee-document-upload';

    /** The upload being checked or followed. */
    #[Url]
    public ?int $batch = null;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<int, DocumentUploadBatch|null> */
    private array $loaded = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return DocumentBulkUploads::mayUpload($user) && $user->hasPermission('employee.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->batch !== null && $this->current() === null) {
            $this->batch = null;
        }
        $this->form->fill(['existing' => 'version', 'notify' => false]);
    }

    public function getSubheading(): ?string
    {
        return $this->batch === null
            ? 'Many employees\' documents at once: a zip per employee, named by employee code. Each file goes to the document type its name says.'
            : null;
    }

    public function form(Schema $schema): Schema
    {
        $zipKb = DocumentBulkUploads::limit('zip_max_kb', 12288);

        return $schema->statePath('data')->components([
            FileUpload::make('zips')->label('Zips')->hiddenLabel()->multiple()->maxFiles(DocumentBulkUploads::limit('zips_max', 50))
                ->disk(StagedUpload::disk())->directory(StagedUpload::DIRECTORY)->visibility('private')
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip'])
                ->maxSize($zipKb)->storeFileNamesIn('zip_names')->required()
                ->placeholder('<span class="pos-di-drop-icon">'.svg('heroicon-o-arrow-up-tray')->toHtml().'</span><span class="pos-di-drop-main">Drop the employees\' zips here</span>'
                    .'<span class="pos-di-drop-hint">or <span class="filepond--label-action">browse your computer</span> · up to '.DocumentBulkUploads::limit('zips_max', 50)
                    .' zips at once, '.max(1, intdiv($zipKb, 1024)).' MB each</span>')
                ->extraAttributes(['class' => 'pos-di-dropzone'])->visible(fn () => $this->batch === null),
            Radio::make('existing')->label('When an employee already has a document of that type')->required()->extraAttributes(['class' => 'pos-di-choices'])
                ->options(array_map(fn (array $o) => $o[0], DocumentUploadBatch::EXISTING))->descriptions(array_map(fn (array $o) => $o[1], DocumentUploadBatch::EXISTING))
                ->visible(fn () => $this->batch === null),
            Toggle::make('notify')->label('Send the usual “document uploaded” notifications')
                ->helperText('Off by default: a bulk upload would otherwise send one for every file.')->visible(fn () => $this->batch === null),
        ]);
    }

    // ---- what the view shows --------------------------------------------------------------------------------------

    public function current(): ?DocumentUploadBatch
    {
        if ($this->batch === null) {
            return null;
        }
        if (! array_key_exists($this->batch, $this->loaded)) {
            $batch = DocumentUploadBatch::query()->with('creator')->find($this->batch);
            $this->loaded[$this->batch] = $batch !== null && DocumentBulkUploads::canSee(auth()->user(), $batch) ? $batch : null;
        }

        return $this->loaded[$this->batch];
    }

    /** The step the stepper shows (0-based). */
    public function step(): int
    {
        $batch = $this->current();

        return $batch === null ? 0 : ($batch->inReview() ? 1 : 2);
    }

    public function mayChange(): bool
    {
        $batch = $this->current();

        return $batch !== null && $batch->inReview() && DocumentBulkUploads::canChange(auth()->user(), $batch);
    }

    /**
     * What the check found, for the banner and the notes.
     *
     * @return array{unmatched_folders: int, no_expiry: int, new_versions: int}
     */
    public function review(): array
    {
        $batch = $this->current();
        if ($batch === null) {
            return ['unmatched_folders' => 0, 'no_expiry' => 0, 'new_versions' => 0];
        }
        $items = DocumentUploadItem::query()->where('document_upload_batch_id', $batch->id);

        return [
            // Zips or folders whose code and name are not an employee's (none of their files can be uploaded).
            'unmatched_folders' => (clone $items)->where('status', 'blocked')->whereNull('employee_id')->distinct()->count('folder'),
            'no_expiry' => (clone $items)->where('status', 'ready')->whereNull('expires_on')->whereHas('type', fn ($q) => $q->where('requires_expiry', true))->count(),
            'new_versions' => (clone $items)->where('status', 'ready')->where('reason', 'like', 'Becomes the new version%')->count(),
        ];
    }

    /** @return list<DocumentUploadBatch> this person's earlier uploads (everyone's with bulk.view_all), newest first */
    public function previous(): array
    {
        $user = auth()->user();

        return DocumentUploadBatch::query()->with('creator')->where('status', '!=', 'discarded')
            ->when(! $user->hasPermission('bulk.view_all'), fn ($q) => $q->where('created_by', $user->id))
            ->when($this->batch !== null, fn ($q) => $q->whereKeyNot($this->batch))
            ->latest('id')->limit(8)->get()->all();
    }

    public static function statusLabel(DocumentUploadBatch $batch): string
    {
        return match (true) {
            $batch->status === 'completed' && $batch->failed > 0 => 'Uploaded with failures',
            default => DocumentUploadBatch::STATUSES[$batch->status] ?? ucfirst((string) $batch->status),
        };
    }

    public function table(Table $table): Table
    {
        $open = fn (): bool => $this->mayChange();

        return $table
            ->query(fn () => DocumentUploadItem::query()->with(['employee.person', 'type'])->where('document_upload_batch_id', $this->current()?->id ?? 0))
            ->columns([
                TextColumn::make('file_name')->label('File')->weight('medium')->wrap()->searchable(['file_name', 'folder', 'employee_code'])
                    ->description(fn (DocumentUploadItem $record) => Number::fileSize((int) $record->size_bytes, maxPrecision: 1)
                        .(str_contains((string) $record->entry, '/') ? ' · '.dirname((string) $record->entry) : '')),
                TextColumn::make('type.name')->label('Document type')->wrap()->placeholder('Choose a type')
                    ->color(fn (DocumentUploadItem $record) => $record->document_type_id === null && $record->status === 'needs_type' ? 'warning' : null)
                    ->description(fn (DocumentUploadItem $record) => $record->matched_by !== null ? 'From '.mb_strtolower(DocumentUploadItem::MATCHED_BY[$record->matched_by] ?? $record->matched_by) : null),
                TextColumn::make('expires_on')->label('Expires')->date('d M Y')->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => DocumentUploadItem::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'ready', 'uploaded' => 'success', 'needs_type' => 'warning', 'blocked', 'failed' => 'danger', default => 'gray',
                    }),
                TextColumn::make('reason')->label('Note')->wrap()->placeholder('—')->color('gray'),
            ])
            ->groups([
                Group::make('folder')->label('Employee')->titlePrefixedWithLabel(false)->collapsible()
                    ->getTitleFromRecordUsing(fn (DocumentUploadItem $record) => $record->employee !== null
                        ? $record->employee->employee_code.' · '.$record->employee->display_name : (string) $record->folder)
                    ->getDescriptionFromRecordUsing(fn (DocumentUploadItem $record) => $record->employee !== null ? 'From “'.$record->folder.'”' : null),
            ])
            ->defaultGroup('folder')
            ->defaultSort('id')
            ->filters([SelectFilter::make('status')->options(DocumentUploadItem::STATUSES)])
            ->recordActions([
                Action::make('changeItem')->label('Change')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->visible(fn (DocumentUploadItem $record) => $open() && $record->isOpen())
                    ->modalHeading(fn (DocumentUploadItem $record) => $record->file_name)
                    ->modalDescription('The document type this file is uploaded as, and its dates.')
                    ->modalSubmitActionLabel('Save')
                    ->fillForm(fn (DocumentUploadItem $record) => ['document_type_id' => $record->document_type_id, 'issued_on' => $record->issued_on?->toDateString(), 'expires_on' => $record->expires_on?->toDateString()])
                    ->schema([
                        Select::make('document_type_id')->label('Document type')->required()->searchable()->live()
                            ->options(fn (DocumentUploadItem $record) => self::typeOptions(app(DocumentBulkUploads::class)->typesFor($record->employee))),
                        DatePicker::make('issued_on')->label('Issued on')->native(false),
                        DatePicker::make('expires_on')->label('Expires on')->native(false)->afterOrEqual('issued_on')
                            ->helperText(fn (Get $get) => DocumentType::query()->find($get('document_type_id'))?->requires_expiry ? 'This type has an expiry date: expiry reminders need it.' : null),
                    ])
                    ->action(fn (DocumentUploadItem $record, array $data) => $this->attempt(fn () => app(DocumentBulkUploads::class)->edit($record, auth()->user(), $data), 'File updated')),
                Action::make('skipItem')->label('Skip')->icon(Heroicon::OutlinedMinusCircle)->color('gray')
                    ->visible(fn (DocumentUploadItem $record) => $open() && in_array($record->status, ['ready', 'needs_type'], true))
                    ->action(fn (DocumentUploadItem $record) => $this->attempt(fn () => app(DocumentBulkUploads::class)->skip([$record], auth()->user()), 'File skipped')),
                Action::make('includeItem')->label('Include')->icon(Heroicon::OutlinedPlusCircle)->color('primary')
                    ->visible(fn (DocumentUploadItem $record) => $open() && $record->status === 'skipped')
                    ->action(fn (DocumentUploadItem $record) => $this->attempt(fn () => app(DocumentBulkUploads::class)->include([$record], auth()->user()), 'File included')),
            ])
            ->toolbarActions([
                BulkAction::make('setTypeSelected')->label('Set document type')->icon(Heroicon::OutlinedTag)->visible($open)
                    ->schema([Select::make('document_type_id')->label('Document type')->required()->searchable()
                        ->options(fn () => self::typeOptions(app(DocumentBulkUploads::class)->typesFor(null)))])
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records, array $data) {
                        $result = app(DocumentBulkUploads::class)->setType($records, (int) $data['document_type_id'], auth()->user());
                        $this->forget();
                        Notification::make()->title($result['done'].' file(s) changed')->body($result['refused'] > 0 ? $result['refused'].' could not take this type (the file cannot be uploaded, or the employee\'s company does not use the type).' : null)
                            ->{$result['refused'] > 0 ? 'warning' : 'success'}()->send();
                    }),
                BulkAction::make('skipSelected')->label('Skip')->icon(Heroicon::OutlinedMinusCircle)->color('gray')->visible($open)
                    ->deselectRecordsAfterCompletion()
                    ->action(fn ($records) => $this->attempt(fn () => app(DocumentBulkUploads::class)->skip($records, auth()->user()), 'Files skipped')),
                BulkAction::make('includeSelected')->label('Include')->icon(Heroicon::OutlinedPlusCircle)->visible($open)
                    ->requiresConfirmation()->modalDescription('Skipped files are taken into the upload. Files that cannot be uploaded stay out.')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn ($records) => $this->attempt(fn () => app(DocumentBulkUploads::class)->include($records, auth()->user()), 'Files included')),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No files')
            ->emptyStateDescription('Nothing matches the filter.');
    }

    // ---- actions ---------------------------------------------------------------------------------------------------

    public function uploadAndCheck(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        $paths = array_values(array_filter((array) ($state['zips'] ?? []), fn ($p) => is_string($p) && $p !== ''));
        $names = (array) ($state['zip_names'] ?? []);
        $files = [];
        $staged = [];
        try {
            foreach ($paths as $path) {
                $copy = StagedUpload::toUploadedFile($path);
                $staged[] = [$path, $copy];
                $original = $names[$path] ?? basename($path);
                $files[] = new UploadedFile($copy->getPathname(), basename(is_string($original) ? $original : $path), 'application/zip', null, true);
            }
            $batch = app(DocumentBulkUploads::class)->check(auth()->user(), $files, ['existing' => $state['existing'] ?? 'version', 'notify' => (bool) ($state['notify'] ?? false)]);
        } catch (RuntimeException $e) {
            $this->form->fill(['existing' => $state['existing'] ?? 'version', 'notify' => (bool) ($state['notify'] ?? false)]);
            Notification::make()->danger()->title('The zips could not be read')->body($e->getMessage().' Upload them again once fixed.')->persistent()->send();

            return;
        } finally {
            foreach ($staged as [$path, $copy]) {
                rescue(fn () => StagedUpload::discard($path, $copy), report: false);
            }
        }
        $this->redirect(static::getUrl(['batch' => $batch->getKey()]), navigate: true);
    }

    public function startUpload(): void
    {
        $batch = $this->current();
        abort_unless($batch !== null, 404);
        $this->attempt(fn () => app(DocumentBulkUploads::class)->start($batch, auth()->user()), 'Upload started', 'Each file is uploaded to its employee; this page shows the progress.');
    }

    public function discardUpload(): void
    {
        $batch = $this->current();
        abort_unless($batch !== null, 404);
        $this->attempt(fn () => app(DocumentBulkUploads::class)->discard($batch, auth()->user()), 'Upload discarded', 'Nothing was uploaded, and the zips were removed.');
    }

    /** Polled while the upload runs. */
    public function refreshStatus(): void
    {
        $this->forget();
    }

    public function downloadResults(): ?StreamedResponse
    {
        $batch = $this->current();
        if ($batch === null) {
            return null;
        }
        $csv = app(DocumentBulkUploads::class)->resultsCsv($batch);

        return response()->streamDownload(fn () => print ($csv), "employee-documents-upload-{$batch->id}.csv", ['Content-Type' => 'text/csv']);
    }

    public function downloadGuide(): StreamedResponse
    {
        $csv = app(DocumentBulkUploads::class)->namesGuideCsv();

        return response()->streamDownload(fn () => print ($csv), 'document-types-and-file-names.csv', ['Content-Type' => 'text/csv']);
    }

    public function downloadSample(): StreamedResponse
    {
        $zip = DocumentBulkUploads::sampleZip();

        return response()->streamDownload(fn () => print ($zip), 'sample-employee-documents.zip', ['Content-Type' => 'application/zip']);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    /** @param  callable(): mixed  $work */
    private function attempt(callable $work, string $done, ?string $body = null): void
    {
        try {
            $work();
            Notification::make()->success()->title($done)->body($body)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        }
        $this->forget();
    }

    private function forget(): void
    {
        $this->loaded = [];
    }

    /**
     * Document types for a picker, by category in the organisation's category order.
     *
     * @param  iterable<DocumentType>  $types
     * @return array<string, array<int, string>>
     */
    public static function typeOptions(iterable $types): array
    {
        $categories = (array) config('peopleos.documents.categories', []);
        $groups = [];
        foreach ($types as $type) {
            $groups[(string) ($categories[$type->category] ?? ucfirst((string) ($type->category ?: 'other')))][(int) $type->id] = (string) $type->name;
        }
        $order = array_flip(array_values($categories));
        uksort($groups, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
        foreach ($groups as &$names) {
            asort($names, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $groups;
    }
}

<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\Workbooks\DependencyPlanner;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Employment\Imports\Workbooks\WorkbookWriter;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Master uploads (Admin → Data import → Preferred imports): one Excel workbook for many kinds of data. Choose the
 * workbook → download its template and upload it (with what it is for: add, update or both) → the workbook is read and
 * every row checked in the background → review what each worksheet will add, update or skip, and the rows with errors
 * (error workbook) → approve and import (queued) → follow each worksheet's progress and results. Worksheets are ordinary
 * uploads of their own type underneath, so every rule, permission and audit record of the single uploads applies.
 */
class MasterImport extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    public const STEPS = ['Choose', 'Upload', 'Check', 'Import'];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'data-import/master-uploads';

    protected static ?string $title = 'Master uploads';

    protected string $view = 'filament.pages.master-import';

    /** The chosen workbook (WorkbookCatalogue key). */
    #[Url]
    public ?string $workbook = null;

    /** The master upload being followed. */
    #[Url]
    public ?int $upload = null;

    /** @var array<string, mixed> */
    public array $data = [];

    /** The worksheet whose rows to look at are open in the review (waiting, blocked, with errors, failed). */
    public ?string $rowsSheet = null;

    /** Bumped after a name is decided, so the review panel remounts with the fresh report. */
    public int $referencesRevision = 0;

    public static function canAccess(): bool
    {
        return WorkbookCatalogue::visible(auth()->user()) !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->upload !== null) {
            $this->workbook = WorkbookImports::key($this->current());
        } elseif ($this->workbook !== null && ! WorkbookCatalogue::allowed(auth()->user(), $this->workbook)) {
            $this->workbook = null;
        }
        $this->form->fill(['mode' => 'both']);
    }

    public function getTitle(): string
    {
        return WorkbookCatalogue::find($this->workbook)['label'] ?? 'Master uploads';
    }

    public function getSubheading(): ?string
    {
        return $this->workbook === null
            ? 'One Excel workbook for many kinds of data, checked together and imported in the order they need.'
            : 'Master upload · '.(WorkbookCatalogue::find($this->workbook)['about'] ?? '');
    }

    public function form(Schema $schema): Schema
    {
        $uploading = fn () => $this->workbook !== null && $this->upload === null;

        return $schema->statePath('data')->components([
            Radio::make('mode')->label('What is this workbook for?')->required()->extraAttributes(['class' => 'pos-di-choices'])
                ->options(array_map(fn (array $m) => $m[0], WorkbookImports::MODES))->descriptions(array_map(fn (array $m) => $m[1], WorkbookImports::MODES))
                ->visible($uploading),
            FileUpload::make('file')->label('Your workbook')->hiddenLabel()->disk(EmployeeImports::disk())->directory(fn () => EmployeeImports::directory())->visibility('private')
                ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream', 'application/zip'])
                ->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('original_name')->required()
                ->placeholder(fn () => '<span class="pos-di-drop-icon">'.svg('heroicon-o-arrow-up-tray')->toHtml().'</span><span class="pos-di-drop-main">Drop your Excel workbook here</span>'
                    .'<span class="pos-di-drop-hint">or <span class="filepond--label-action">browse your computer</span> · .xlsx</span>')
                ->extraAttributes(['class' => 'pos-di-dropzone'])->visible($uploading),
            Textarea::make('reason')->label('Reason')->helperText('Kept in the audit trail with the upload.')->required()->maxLength(500)->rows(2)
                ->visible(fn () => $this->canApprove()),
        ]);
    }

    // ---- what the view shows --------------------------------------------------------------------------------------

    /** The step the stepper shows (0-based). */
    public function step(): int
    {
        if ($this->workbook === null) {
            return 0;
        }
        if ($this->upload === null) {
            return 1;
        }
        $import = $this->current();

        return in_array($import->status, ['approved', 'importing', 'imported'], true) || ($import->status === 'failed' && $import->imported_at !== null) ? 3 : 2;
    }

    /** @return list<array<string, mixed>> the workbooks this person may use, with their worksheets */
    public function workbooks(): array
    {
        return array_map(fn (string $key) => self::card($key), WorkbookCatalogue::visible(auth()->user()));
    }

    /** @return array<string, mixed> a workbook with its worksheets in run order */
    public function describe(string $key): array
    {
        return self::card($key);
    }

    /** @return array<string, mixed> a workbook for the signed-in person: its worksheets they may upload, in run order */
    public static function card(string $key): array
    {
        $sheets = WorkbookCatalogue::sheetsFor(auth()->user(), $key);
        $plan = DependencyPlanner::plan($sheets);

        return array_merge(WorkbookCatalogue::get($key), [
            'tone' => DataImport::TONES[WorkbookCatalogue::WORKBOOKS[$key]['head']] ?? 'iris',
            'sheets' => array_map(fn (string $title) => ['title' => $title, 'about' => $sheets[$title]['process']['about'], 'after' => $plan['after'][$title], 'stage' => $plan['stages'][$title]], $plan['order']),
            'all' => count(WorkbookCatalogue::WORKBOOKS[$key]['sheets']),
            'url' => static::getUrl(['workbook' => $key]),
            'companies' => static::companiesLine(),
        ]);
    }

    /**
     * The companies this person may load data for, as the cards show them: up to three by name, otherwise how many
     * ("all 5 companies" when they may act for every company of the organisation, "3 of 5 companies" when limited).
     */
    public static function companiesLine(): string
    {
        $user = auth()->user();
        if ($user === null) {
            return '';
        }
        $limited = app(EntityApplicability::class)->companiesFor($user);
        $all = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('name')->pluck('name', 'id');
        $mine = $limited === null ? $all : $all->only($limited);
        if ($mine->isEmpty()) {
            return 'no company yet';
        }
        if ($mine->count() <= 3) {
            $names = $mine->values()->all();

            return count($names) === 1 ? $names[0] : implode(', ', array_slice($names, 0, -1)).' and '.end($names);
        }

        return $limited === null ? "all {$all->count()} companies" : "{$mine->count()} of {$all->count()} companies";
    }

    public function tone(): string
    {
        $tone = DataImport::TONES[WorkbookCatalogue::WORKBOOKS[$this->workbook]['head'] ?? ''] ?? 'iris';

        return "--pos-tone: var(--pos-hue-{$tone}); --pos-tone-bg: var(--pos-hue-{$tone}-bg);";
    }

    /** The master upload being followed (only one this person may see). */
    public function current(): EmployeeImport
    {
        $import = EmployeeImport::query()->find($this->upload);
        abort_if($import === null || ! $import->isWorkbook(), 404);
        abort_unless(auth()->user()?->can('view', $import), 403);

        return $import;
    }

    /** @return array<string, mixed> everything the check / import steps show */
    public function review(): array
    {
        $import = $this->current();
        $sheets = WorkbookImports::sheetSummaries($import);
        $user = auth()->user();

        return [
            'import' => $import,
            'checking' => WorkbookImports::checking($import),
            'sheets' => array_map(fn (array $s) => $s + ['url' => $s['child_model'] !== null ? RecentUploads::urlFor($s['child_model']) : null], $sheets),
            'blocked' => WorkbookImports::blockedSheets($import),
            'warnings' => (array) ($import->options['warnings'] ?? []),
            'waiting' => (int) ($import->options['waiting'] ?? 0),
            'review_rows' => array_sum(array_column($sheets, 'review')),
            'approval' => ImportGovernance::approvalLabel($import),
            'waiting_for' => ImportGovernance::waitingFor($import, $user),
            'can_approve' => $this->canApprove(),
            'can_start' => $import->status === 'approved' && in_array((int) $user?->getKey(), [(int) $import->uploaded_by, (int) $import->approved_by], true),
            'progress' => $this->progress($import, $sheets),
            'active' => WorkbookImports::checking($import) || $import->status === 'importing',
            'references' => $import->status === 'validated' && ! WorkbookImports::checking($import) ? ($import->options['references'] ?? null) : null,
            'outcomes' => array_count_values(array_map(fn (array $s) => $s['outcome'], $sheets)),
        ];
    }

    /**
     * The rows to look at in one worksheet: waiting for an earlier worksheet, blocked by one, with errors, or failed
     * when run — with their worksheet row, what is wrong and what they depend on. The first 50; the error workbook has all.
     *
     * @return list<array{row: string, status: string, problem: string, depends: string}>
     */
    public function sheetRows(): array
    {
        if ($this->rowsSheet === null || $this->upload === null) {
            return [];
        }
        $child = EmployeeImport::query()->where('parent_id', $this->current()->getKey())->where('sheet', $this->rowsSheet)->where('status', '!=', 'discarded')->first();
        if ($child === null) {
            return [];
        }

        return $child->rows()->where(fn ($q) => $q->whereIn('action', ['error', 'waiting'])->orWhere('status', 'failed'))
            ->orderBy('row_number')->limit(50)->get()
            ->map(fn (EmployeeImportRow $row) => [
                'row' => (string) ($row->data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1),
                'status' => WorkbookImports::rowStatus($row),
                'problem' => WorkbookImports::rowProblem($row),
                'depends' => collect((array) ($row->match['depends_on'] ?? []))->map(fn (array $h) => ($h['label'] ?? '')." {$h['key']} · {$h['sheet']} row {$h['row']}".(($h['state'] ?? '') === 'blocked' ? ' (has errors)' : ''))->implode('; '),
            ])->all();
    }

    public function showRows(string $sheet): void
    {
        $this->rowsSheet = $this->rowsSheet === $sheet ? null : $sheet;
    }

    /**
     * Name review (ReferenceReview): a person chose what a typed name means. The workbook is checked again in the
     * background so the decision counts; only this upload, and only while it is checked and not yet approved.
     */
    #[On('references-resolved')]
    public function referencesResolved(string $scope, string $key = ''): void
    {
        if ($this->upload === null || $scope !== 'employee_imports:'.$this->upload) {
            return;
        }
        $import = $this->current();
        if ($import->status !== 'validated' || WorkbookImports::checking($import)) {
            return;
        }
        try {
            app(WorkbookImports::class)->queueCheck($import);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('The workbook could not be checked again')->body($e->getMessage())->send();
        }
        $this->referencesRevision++;
    }

    /** Whether this person may approve the checked workbook now. */
    public function canApprove(): bool
    {
        if ($this->upload === null) {
            return false;
        }
        $import = $this->current();
        if ($import->status !== 'validated' || WorkbookImports::checking($import) || WorkbookImports::blockedSheets($import) !== []
            || (int) $import->review_count > 0 || (int) $import->create_count + (int) $import->update_count + (int) ($import->options['waiting'] ?? 0) === 0) {
            return false;
        }

        return ImportGovernance::mayApproveNow($import, auth()->user());
    }

    /** @return list<EmployeeImport> this person's earlier master uploads (of the chosen workbook, or all) */
    public function previous(): array
    {
        $user = auth()->user();
        $types = $this->workbook !== null ? [WorkbookCatalogue::type($this->workbook)] : array_map(fn (string $k) => WorkbookCatalogue::type($k), WorkbookCatalogue::visible($user));

        return EmployeeImport::query()->with('uploader')->whereIn('type', $types)->whereNull('parent_id')->where('status', '!=', 'discarded')
            ->when($this->upload !== null, fn ($q) => $q->whereKeyNot($this->upload))
            ->latest('id')->limit(50)->get()->filter(fn (EmployeeImport $i) => $user !== null && WorkbookImports::maySee($user, $i))->take(8)->values()->all();
    }

    public function urlFor(EmployeeImport $import): string
    {
        return static::getUrl(['upload' => $import->getKey()]);
    }

    public static function statusLabel(EmployeeImport $import): string
    {
        return match (true) {
            WorkbookImports::checking($import) => 'Checking',
            $import->status === 'validated' => 'Checked',
            $import->status === 'approved' => 'Approved',
            $import->status === 'importing' => 'Importing',
            $import->status === 'imported' => (int) $import->failure_count > 0 ? 'Imported with failures' : 'Imported',
            $import->status === 'failed' => 'Failed',
            default => ucfirst((string) $import->status),
        };
    }

    // ---- actions ---------------------------------------------------------------------------------------------------

    public function choose(string $key): void
    {
        $this->redirect(static::getUrl(['workbook' => $key]), navigate: true);
    }

    public function downloadTemplate(): StreamedResponse
    {
        return $this->templateDownload(false);
    }

    public function downloadSample(): StreamedResponse
    {
        return $this->templateDownload(true);
    }

    /** Upload the workbook; it is read and checked in the background. */
    public function uploadAndCheck(): void
    {
        abort_unless($this->workbook !== null && WorkbookCatalogue::allowed(auth()->user(), $this->workbook), 403);
        $state = $this->form->getState();
        $name = $state['original_name'] ?? null;
        $name = (string) (is_array($name) ? (reset($name) ?: basename((string) $state['file'])) : ($name ?? basename((string) $state['file'])));
        try {
            $import = ImportTypes::register(WorkbookCatalogue::type($this->workbook), (string) $state['file'], $name, auth()->user(), ['mode' => (string) ($state['mode'] ?? 'both')]);
            app(WorkbookImports::class)->queueCheck($import);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('The workbook could not be taken')->body($e->getMessage())->persistent()->send();

            return;
        }
        $this->redirect(static::getUrl(['upload' => $import->getKey()]), navigate: true);
    }

    /** Approve the checked workbook and, when this person may, start the import at once. */
    public function approve(): void
    {
        $import = $this->current();
        if (! $this->canApprove()) {
            return;
        }
        $reason = (string) ($this->form->getState()['reason'] ?? '');
        $service = app(WorkbookImports::class);
        try {
            $service->approve($import, auth()->user(), $reason);
            $service->start($import->refresh(), auth()->user());
            Notification::make()->success()->title('Import started')->body('Each worksheet runs in order; this page shows its progress.')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        }
    }

    /** Start an approved workbook (approved by a checker or a workflow). */
    public function startImport(): void
    {
        try {
            app(WorkbookImports::class)->start($this->current(), auth()->user());
            Notification::make()->success()->title('Import started')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        }
    }

    /** Drop this upload and upload a corrected workbook. */
    public function uploadAgain(): void
    {
        $import = $this->current();
        if (! in_array($import->status, ['importing', 'imported', 'discarded'], true) && ! ($import->status === 'failed' && $import->imported_at !== null)) {
            try {
                app(WorkbookImports::class)->discard($import, 'Replaced by a corrected workbook');
            } catch (RuntimeException) {
                // Already past discarding: the corrected workbook is a new upload either way.
            }
        }
        $this->redirect(static::getUrl(['workbook' => $this->workbook]), navigate: true);
    }

    public function downloadErrors(): ?StreamedResponse
    {
        $import = $this->current();
        $path = storage_path('app/workbook-tmp/errors-'.Str::ulid().'.xlsx');
        @mkdir(dirname($path), 0700, true);
        if (app(WorkbookWriter::class)->errors($import, $path) === null) {
            Notification::make()->success()->title('No rows with problems')->body('Every row was checked or imported without errors.')->send();

            return null;
        }

        return self::streamFile($path, Str::slug(pathinfo((string) $import->original_name, PATHINFO_FILENAME)).'-errors.xlsx');
    }

    /** wire:poll while the workbook is checked or imported. */
    public function refreshStatus(): void {}

    // ---- internals -------------------------------------------------------------------------------------------------

    /** @param list<array<string, mixed>> $sheets @return array{done: int, total: int, percent: int, sheet: ?string} */
    private function progress(EmployeeImport $import, array $sheets): array
    {
        $total = max(1, array_sum(array_map(fn (array $s) => (int) $s['rows'], $sheets)));
        $done = array_sum(array_map(fn (array $s) => in_array($s['child_status'], ['imported', 'failed'], true) ? (int) $s['rows'] : (int) $s['done'], $sheets));

        return ['done' => $done, 'total' => $total, 'percent' => (int) floor(100 * min($done, $total) / $total), 'sheet' => $import->options['progress']['sheet'] ?? null];
    }

    /** A workbook card's Template button (master-upload-cards). */
    public function downloadWorkbookTemplate(string $key): StreamedResponse
    {
        return self::templateResponse($key, false);
    }

    /**
     * The template (or sample) of a workbook for the signed-in person, as a download: only the worksheets they may
     * upload, with their own codes in the example rows. Shared with Data import's Preferred imports band.
     */
    public static function templateResponse(string $key, bool $sample): StreamedResponse
    {
        abort_unless(WorkbookCatalogue::allowed(auth()->user(), $key), 403);
        $path = storage_path('app/workbook-tmp/template-'.Str::ulid().'.xlsx');
        @mkdir(dirname($path), 0700, true);
        app(WorkbookWriter::class)->template($key, auth()->user(), $sample, $path);

        return self::streamFile($path, Str::slug(WorkbookCatalogue::get($key)['label']).($sample ? '-sample' : '-template').'.xlsx');
    }

    private function templateDownload(bool $sample): StreamedResponse
    {
        abort_unless($this->workbook !== null, 404);

        return self::templateResponse($this->workbook, $sample);
    }

    private static function streamFile(string $path, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}

<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportDownloads;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Organisation\Models\Company;
use App\Filament\Support\ImportActions;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use League\Csv\Reader;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * Data import (HROne's Import): one place for every upload an organisation can make, under the head it belongs to
 * (ImportCatalogue). Four steps: select the process → import intent (new or existing records, and the upload's own
 * choices) → prepare your data (the CSV format, a sample file and the guide; upload the file) → review & fix (the file
 * is staged, its columns matched and every row checked; fix the file and upload again, or approve and run it). The
 * upload goes through the same staged pipeline, permissions and approval rules as its own screen, and appears in
 * Recent uploads.
 */
class DataImport extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    public const STEPS = ['Select process', 'Import intent', 'Prepare your data', 'Review & fix'];

    /** The tint each module's header takes (theme hues; the same as the Actions panel where both have the module). */
    public const TONES = ['core' => 'iris', 'workforce' => 'teal', 'time_office' => 'blue', 'payroll' => 'green', 'performance' => 'plum',
        'engage' => 'coral', 'asset' => 'slate', 'projects' => 'gold', 'integration' => 'iris'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Data import';

    protected static ?string $title = 'Data import';

    protected static ?string $slug = 'data-import';

    protected static ?int $navigationSort = 19;

    protected string $view = 'filament.pages.data-import';

    /** The chosen process (ImportCatalogue key), kept in the address so a process can be linked to. */
    #[Url]
    public ?string $process = null;

    public int $step = 1;

    public string $search = '';

    /** The upload staged in step 4. */
    public ?int $importId = null;

    /** @var array<string, mixed> the intent chosen in step 2 */
    public array $intent = [];

    /** @var array<string, string|null> column index => field, when the columns need matching by hand */
    public array $mapping = [];

    public ?string $mappingError = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return ImportTypes::allowedTypes(auth()->user()) !== [];
    }

    public function getSubheading(): ?string
    {
        return 'Every upload you can make, by module. Pick what the file holds, download its format or a sample, and upload it: nothing changes until the file is checked and you run it.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $process = $this->current();
        if ($this->process !== null && $process === null) {
            $this->process = null;
        }
        if ($process !== null && ImportCatalogue::notApplicable($process) === null) {
            $this->step = 2;
            $this->form->fill($this->intentDefaults($process));
        }
    }

    /** The chosen process, when this person may run it (or it is one PeopleOS does not take from a file: it says why). */
    public function current(): ?array
    {
        $process = ImportCatalogue::find($this->process);

        return $process !== null && (ImportCatalogue::allowed(auth()->user(), $process) || ImportCatalogue::notApplicable($process) !== null) ? $process : null;
    }

    /**
     * Step 1: the processes this person may run, by head (x-pos.browse groups), with every process under "All modules"
     * first, or the matches first while searching.
     *
     * @return list<array<string, mixed>>
     */
    public function browseGroups(): array
    {
        $heads = ImportCatalogue::visible(auth()->user(), $this->search);
        $item = fn (array $p, bool $withHead) => ['label' => $p['label'], 'url' => static::getUrl(['process' => $p['key']]),
            'description' => ($withHead ? ImportCatalogue::HEADS[$p['head']][0].' · ' : '').(ImportCatalogue::notApplicable($p) !== null ? 'Not available as an upload' : $p['about'])];
        $groups = [];
        foreach ($heads as $head => $processes) {
            [$label, $icon, $sub] = ImportCatalogue::HEADS[$head];
            $groups[] = ['key' => $head, 'label' => $label, 'sub' => $sub, 'icon' => $icon, 'items' => array_map(fn (array $p) => $item($p, false), $processes)];
        }
        if ($groups === []) {
            return [];
        }
        $all = array_merge(...array_map(fn (array $processes) => array_map(fn (array $p) => $item($p, true), $processes), array_values($heads)));
        $first = trim($this->search) === ''
            ? ['key' => 'all', 'label' => 'All modules', 'sub' => 'Every upload you can make.', 'icon' => 'heroicon-o-squares-2x2', 'items' => $all]
            : ['key' => 'matches', 'label' => 'All matches', 'sub' => 'Every upload that matches “'.trim($this->search).'”.', 'icon' => 'heroicon-o-magnifying-glass', 'items' => $all];

        return [$first, ...$groups];
    }

    public function processCount(): int
    {
        return array_sum(array_map('count', ImportCatalogue::visible(auth()->user(), $this->search)));
    }

    public function form(Schema $schema): Schema
    {
        $type = fn () => $this->current()['type'] ?? null;
        $intentStep = fn () => $this->step === 2;
        $modes = fn () => ($p = $this->current()) ? ImportCatalogue::modes($p) : null;

        return $schema->statePath('data')->components([
            Radio::make('mode')->label('What is this file for?')->hiddenLabel()->required()->extraAttributes(['class' => 'pos-di-choices'])
                ->options(fn () => array_map(fn (array $m) => $m[0], $modes() ?? []))
                ->descriptions(fn () => array_map(fn (array $m) => $m[1], $modes() ?? []))
                ->visible(fn () => $intentStep() && $modes() !== null),
            ImportActions::monthField()->visible(fn () => $intentStep() && $type() === AttendanceSheetImports::TYPE),
            ImportActions::blankDaysField()->visible(fn () => $intentStep() && $type() === AttendanceSheetImports::TYPE),
            Select::make('company_id')->label('Company')->helperText('One upload prepares one compensation cycle for one company.')->required()->native(false)
                ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn () => $intentStep() && $type() === SalaryImports::TYPE),
            DatePicker::make('effective_from')->label('Default effective date')->helperText('Used for rows without an Effective from value.')
                ->visible(fn () => $intentStep() && $type() === SalaryImports::TYPE),
            Select::make('timezone')->label('Default timezone for timestamps')->searchable()->placeholder(config('app.timezone'))
                ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz])->all())
                ->visible(fn () => $intentStep() && $type() === PunchImports::TYPE),
            ImportActions::fileField()->label('Your CSV file')->hiddenLabel()->visible(fn () => $this->step === 3),
            Textarea::make('reason')->label('Reason')->helperText('Kept in the audit trail with the upload.')->required()->maxLength(500)->rows(2)
                ->visible(fn () => $this->step === 4 && $this->canSubmit()),
        ]);
    }

    /** The header's tint for the chosen process's module (theme hues). */
    public function tone(array $process): string
    {
        $tone = self::TONES[$process['head']] ?? 'iris';

        return "--pos-tone: var(--pos-hue-{$tone}); --pos-tone-bg: var(--pos-hue-{$tone}-bg);";
    }

    /** What the file is for, as chosen in step 2 (or fixed by the process), for the header. */
    public function intentLabel(array $process): ?string
    {
        if (isset($process['mode'])) {
            return $process['mode'] === 'new' ? 'Adds new records' : 'Updates records on file';
        }
        $modes = ImportCatalogue::modes($process);
        if ($modes === null) {
            return 'Adds records';
        }

        return $modes[$this->intent['mode'] ?? $this->data['mode'] ?? 'both'][0] ?? null;
    }

    /** @return list<string> the columns every row must have (the guide's required ones) */
    public function requiredColumns(array $format): array
    {
        return array_values(array_map(fn (array $c) => $c[0], array_filter($format['columns'], fn (array $c) => mb_strtolower(trim((string) $c[1])) === 'yes')));
    }

    /** Step 2 → 3: keep the intent. */
    public function toPrepare(): void
    {
        $process = $this->requireProcess();
        $state = $this->form->getState();
        $this->intent = array_filter([
            'mode' => $process['mode'] ?? ($state['mode'] ?? (ImportCatalogue::modes($process) === null ? null : 'both')),
            'month' => $state['month'] ?? null, 'blank_days' => $state['blank_days'] ?? null,
            'company_id' => $state['company_id'] ?? null, 'effective_from' => $state['effective_from'] ?? null, 'timezone' => $state['timezone'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $this->step = 3;
    }

    /** Step 3 → 4: stage the file, match its columns and check every row. */
    public function uploadAndCheck(): void
    {
        $process = $this->requireProcess();
        $state = $this->form->getState();
        $user = auth()->user();

        try {
            $options = $this->intent + (($process['defaults'] ?? []) !== [] ? ['defaults' => $process['defaults']] : []);
            $import = ImportTypes::register($process['type'], (string) $state['file'], ImportActions::originalName($state), $user, $options);
            $import->update(['options' => array_merge($import->options ?? [], ['process' => $process['key']])]);
            ImportTypes::service($import->type)->inspect($import);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('The file could not be read')->body($e->getMessage())->persistent()->send();

            return;
        }

        $this->importId = (int) $import->getKey();
        $this->step = 4;
        $this->matchAndCheck($import->refresh(), $import->mapping ?? []);
    }

    /** Step 4: match the columns by hand and check again. */
    public function saveMapping(): void
    {
        $import = $this->import();
        $byHeader = [];
        foreach ($import->headers ?? [] as $i => $header) {
            $byHeader[$header] = $this->mapping[(string) $i] ?? null;
        }
        $this->matchAndCheck($import, $byHeader);
    }

    /** Step 4: approve and run the checked file (or leave it for its approver). */
    public function submit(): void
    {
        $import = $this->import();
        $user = auth()->user();
        if (! $this->canSubmit()) {
            return;
        }
        $reason = (string) ($this->form->getState()['reason'] ?? '');
        $service = ImportTypes::service($import->type);
        try {
            $service->approve($import, $user, $reason);
            $service->run($import->refresh(), $user);
            Notification::make()->success()->title('Import finished')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();
        }
    }

    /** Step 4 → 3: drop this upload and upload a corrected file. */
    public function reupload(): void
    {
        $import = $this->import();
        if (! $import->isFinal() && $import->status !== 'importing') {
            try {
                ImportTypes::service($import->type)->discard($import, 'Replaced by a corrected file');
            } catch (RuntimeException) {
                // Already past discarding: the corrected file is a new upload either way.
            }
        }
        $this->importId = null;
        $this->mappingError = null;
        $this->mapping = [];
        $this->data['file'] = [];
        $this->step = 3;
    }

    public function back(): void
    {
        if ($this->step <= 2) {
            $this->redirect(static::getUrl(), navigate: true);

            return;
        }
        if ($this->step === 3) {
            $this->step = 2;
            $this->form->fill($this->intent + $this->intentDefaults($this->requireProcess()));
        }
    }

    public function startOver(): void
    {
        $this->redirect(static::getUrl(), navigate: true);
    }

    /** The process's CSV format: the header row only. */
    public function downloadFormat(): StreamedResponse
    {
        return $this->csvDownload(false);
    }

    /** The process's sample file: the header row and example rows. */
    public function downloadSample(): StreamedResponse
    {
        return $this->csvDownload(true);
    }

    /** Step 4: every staged row with its action, outcome and errors (audited, as on the upload's own screen). */
    public function downloadResults(): StreamedResponse
    {
        [$name, $contents] = app(ImportDownloads::class)->results($this->import(), auth()->user());

        return response()->streamDownload(fn () => print ($contents), $name, ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> what the format guide shows for the chosen process */
    public function formatData(): array
    {
        $process = $this->requireProcess();
        $data = ImportActions::formatData($process['type']);
        $fields = ImportCatalogue::fields($process);
        if (isset($process['columns'])) {
            $labels = array_map(fn (string $f) => $fields[$f][0] ?? $f, $process['columns']);
            $data['columns'] = array_values(array_filter($data['columns'], fn (array $c) => in_array($c[0], $labels, true)));
        }
        $notes = [];
        foreach ($process['defaults'] ?? [] as $field => $value) {
            $notes[] = 'A blank '.($fields[$field][0] ?? $field)." cell is read as “{$value}”.";
        }
        $data['title'] = $process['label'];
        $data['notes'] = [...$notes, ...$data['notes']];
        $data['modes'] = null;
        $data['sampleRows'] = iterator_to_array(Reader::fromString(ImportCatalogue::csv($process, true, $this->intent))->getRecords(), false);

        return $data;
    }

    /** @return array<string, mixed>|null step 4: the staged upload and what checking it found */
    public function review(): ?array
    {
        if ($this->importId === null) {
            return null;
        }
        $import = $this->import();
        $rows = $import->rows()->where('action', 'error')->limit(50)->get(['row_number', 'errors', 'match']);
        $done = in_array($import->status, ['imported', 'failed'], true);

        return [
            'import' => $import,
            'done' => $done,
            'checked' => in_array($import->status, ['validated', 'approved', 'importing', 'imported', 'failed'], true),
            'errors' => $rows,
            'more_errors' => max(0, $import->error_count - $rows->count()),
            'waiting' => ImportGovernance::waitingFor($import, auth()->user()),
            'can_submit' => $this->canSubmit(),
            'url' => RecentUploads::urlFor($import),
            'fields' => collect(ImportTypes::service($import->type)::fields($import))->mapWithKeys(fn ($def, $key) => [$key => $def[0].($def[1] ? ' *' : '')])->all(),
        ];
    }

    /** Whether the person can approve and run the checked file now. */
    public function canSubmit(): bool
    {
        if ($this->importId === null) {
            return false;
        }
        $import = $this->import();

        return $import->status === 'validated' && $import->review_count === 0 && $import->create_count + $import->update_count > 0
            && ImportGovernance::mayApproveNow($import, auth()->user());
    }

    /** Map the file's columns (with the intent's options) and check every row; a column problem opens the matching table. */
    private function matchAndCheck(EmployeeImport $import, array $byHeader): void
    {
        $service = ImportTypes::service($import->type);
        $mapping = array_fill_keys($import->headers ?? [], null);
        foreach ($byHeader as $header => $field) {
            if (array_key_exists($header, $mapping)) {
                $mapping[$header] = $field;
            }
        }
        try {
            $service->map($import, $mapping, $this->mapOptions($import));
            $service->validate($import->refresh());
            $this->mappingError = null;
        } catch (RuntimeException $e) {
            $this->mappingError = $e->getMessage();
            $this->mapping = collect($import->refresh()->headers ?? [])->mapWithKeys(fn ($h, $i) => [(string) $i => $mapping[$h] ?? null])->all();
        }
    }

    /** The options the upload's mapping step takes (as on its own screen). */
    private function mapOptions(EmployeeImport $import): array
    {
        $mode = $this->intent['mode'] ?? ImportMode::of($import)->value;

        return match ($import->type) {
            PunchImports::TYPE => ['timezone' => $this->intent['timezone'] ?? null],
            SalaryImports::TYPE => ['company_id' => $this->intent['company_id'] ?? null, 'effective_from' => $this->intent['effective_from'] ?? null, 'mode' => $mode],
            AttendanceSheetImports::TYPE => ['month' => $this->intent['month'] ?? null, 'blank_days' => $this->intent['blank_days'] ?? null, 'mode' => $mode],
            default => ['mode' => $mode],
        };
    }

    /** @return array<string, mixed> */
    private function intentDefaults(array $process): array
    {
        $companies = Company::query()->pluck('id');

        return array_filter([
            'mode' => ImportCatalogue::modes($process) === null ? null : ImportCatalogue::defaultMode($process),
            'month' => $process['type'] === AttendanceSheetImports::TYPE ? AttendanceSheetImports::currentMonth() : null,
            'blank_days' => $process['type'] === AttendanceSheetImports::TYPE ? 'calculate' : null,
            'company_id' => $process['type'] === SalaryImports::TYPE && $companies->count() === 1 ? (int) $companies->first() : null,
        ], fn ($v) => $v !== null);
    }

    private function requireProcess(): array
    {
        $process = $this->current();
        abort_if($process === null || ImportCatalogue::notApplicable($process) !== null, 403);

        return $process;
    }

    /** The upload of step 4: only its uploader works on it here. */
    private function import(): EmployeeImport
    {
        $import = EmployeeImport::query()->findOrFail($this->importId);
        abort_unless((int) $import->uploaded_by === (int) auth()->id() && auth()->user()->can('view', $import), 403);

        return $import;
    }

    private function csvDownload(bool $sample): StreamedResponse
    {
        $process = $this->requireProcess();
        $csv = ImportCatalogue::csv($process, $sample, $this->intent);

        return response()->streamDownload(fn () => print ($csv), ImportCatalogue::fileName($process, $sample), ['Content-Type' => 'text/csv']);
    }
}

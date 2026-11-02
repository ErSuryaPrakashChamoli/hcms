<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportDownloads;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\DataImport;
use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use League\Csv\Reader;
use RuntimeException;
use Throwable;

/** Header actions for the import pipeline; every step calls the service and reports the outcome. */
final class ImportActions
{
    /** Import type => [the service that runs it, its name in the format guide]. */
    public const TYPES = [
        'employees' => [EmployeeImports::class, 'Employee import'],
        PunchImports::TYPE => [PunchImports::class, 'Punch import'],
        SalaryImports::TYPE => [SalaryImports::class, 'Salary upload'],
        BankAccountImports::TYPE => [BankAccountImports::class, 'Bank details upload'],
        AttendanceSheetImports::TYPE => [AttendanceSheetImports::class, 'Monthly attendance upload'],
    ];

    public static function service(EmployeeImport|string $import): EmployeeImports|PunchImports|SalaryImports|BankAccountImports|AttendanceSheetImports|RowImports
    {
        $type = is_string($import) ? $import : $import->type;

        // HROne parity: one-row-one-change uploads (leave balances, roster, geo locations, overtime …).
        return app(self::TYPES[$type][0] ?? RowImportTypes::TYPES[$type][0] ?? EmployeeImports::class);
    }

    /**
     * @return array<string, array{0: string, 1: string}>|null mode => [label, description], for the imports that offer the
     *                                                         choice: the upload's own choices, or the generic insert / update of a one-row-one-change upload (ImportTypes::modes)
     */
    public static function modes(string $type): ?array
    {
        return ImportTypes::modes($type);
    }

    /** @return list<Action> */
    public static function forImport(): array
    {
        $svc = fn (EmployeeImport $record) => self::service($record);
        $fieldsFor = fn (EmployeeImport $record) => collect(($svc($record))::fields($record))->mapWithKeys(fn ($def, $key) => [$key => $def[0].($def[1] ? ' *' : '')])->all();
        $is = fn (string $type) => fn (EmployeeImport $record) => $record->type === $type;
        // HROne setup parity P2: under maker-checker or workflow approval the checker approves or discards; preparing the
        // file stays with its uploader.
        $maker = fn (EmployeeImport $record) => ! ImportGovernance::checkedByAnother((string) $record->type) || (int) $record->uploaded_by === (int) auth()->id();

        return [
            Action::make('inspect')->label('Inspect file')->icon('heroicon-m-magnifying-glass')
                ->visible(fn (EmployeeImport $record) => in_array($record->status, ['uploaded', 'inspected'], true) && $maker($record))
                ->action(fn (EmployeeImport $record) => self::run(fn () => $svc($record)->inspect($record), fn (EmployeeImport $i) => "{$i->row_count} row(s) staged; ".count($i->mapping ?? []).' column(s) mapped automatically')),

            Action::make('map')->label('Map columns')->icon('heroicon-m-arrows-right-left')
                ->visible(fn (EmployeeImport $record) => in_array($record->status, ['inspected', 'mapped', 'validated'], true) && $maker($record))
                ->fillForm(fn (EmployeeImport $record) => ['mapping' => collect($record->headers ?? [])->mapWithKeys(fn ($h, $i) => [(string) $i => $record->mapping[$h] ?? null])->all(), 'mode' => ImportMode::of($record)->value]
                    + collect($record->options ?? [])->only(['timezone', 'company_id', 'effective_from', 'month', 'blank_days'])->all())
                ->schema(fn (EmployeeImport $record) => [
                    Section::make('Columns')->description('Choose the PeopleOS field for each column. Fields marked * are required.')->columns(2)
                        ->schema(collect($record->headers ?? [])->map(fn ($header, $i) => Select::make("mapping.{$i}")->label($header)->options($fieldsFor($record))->searchable()->placeholder('Ignore column'))->values()->all()),
                    ...(($modes = self::modes($record->type)) ? [self::modeField($modes)] : []),
                    Select::make('timezone')->label('Default timezone for timestamps')->options(collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))->searchable()->visible($is(PunchImports::TYPE))->placeholder(config('app.timezone')),
                    Select::make('company_id')->label('Company')->helperText('One upload prepares one compensation cycle for one company.')
                        ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->required()->visible($is(SalaryImports::TYPE)),
                    DatePicker::make('effective_from')->label('Default effective date')->helperText('Used for rows without an Effective from value.')->visible($is(SalaryImports::TYPE)),
                    self::monthField()->visible($is(AttendanceSheetImports::TYPE)),
                    self::blankDaysField()->visible($is(AttendanceSheetImports::TYPE)),
                ])
                ->action(function (EmployeeImport $record, array $data) use ($svc) {
                    $mapping = [];
                    foreach ($record->headers ?? [] as $i => $header) {
                        $mapping[$header] = $data['mapping'][(string) $i] ?? null;
                    }
                    $options = match ($record->type) {
                        PunchImports::TYPE => ['timezone' => $data['timezone'] ?? null],
                        SalaryImports::TYPE => ['company_id' => $data['company_id'] ?? null, 'effective_from' => $data['effective_from'] ?? null, 'mode' => $data['mode'] ?? null],
                        AttendanceSheetImports::TYPE => ['month' => $data['month'] ?? null, 'blank_days' => $data['blank_days'] ?? null, 'mode' => $data['mode'] ?? null],
                        default => ['mode' => $data['mode'] ?? null],
                    };
                    self::run(fn () => $svc($record)->map($record, $mapping, $options), 'Mapping saved');
                }),

            Action::make('validate')->label('Validate rows')->icon('heroicon-m-check-badge')
                ->visible(fn (EmployeeImport $record) => in_array($record->status, ['mapped', 'validated'], true) && $maker($record))
                ->action(fn (EmployeeImport $record) => self::run(fn () => $svc($record)->validate($record), fn (EmployeeImport $i) => "{$i->valid_count} valid, {$i->error_count} with errors, {$i->review_count} to review")),

            // HROne setup parity P2: what approval waits for (another person, or the upload's workflow).
            Action::make('awaitingApproval')->label(fn (EmployeeImport $record) => ImportGovernance::waitingFor($record, auth()->user()) ?? 'Waiting for approval')
                ->icon('heroicon-m-clock')->color('gray')->disabled()
                ->visible(fn (EmployeeImport $record) => ImportGovernance::waitingFor($record, auth()->user()) !== null),

            Action::make('approve')->label('Approve import')->icon('heroicon-m-hand-thumb-up')->color('warning')
                ->visible(fn (EmployeeImport $record) => $record->status === 'validated' && ImportGovernance::mayApproveNow($record, auth()->user()))
                ->requiresConfirmation()
                ->modalDescription(fn (EmployeeImport $record) => match ($record->type) {
                    PunchImports::TYPE => "This will record {$record->create_count} punch(es); {$record->error_count} row(s) with errors and {$record->skip_count} duplicate(s) will be skipped. Attendance is recalculated from the punches.",
                    SalaryImports::TYPE => "Running it prepares a draft compensation cycle with {$record->create_count} line(s); {$record->error_count} row(s) with errors and {$record->skip_count} row(s) already in force or outside the chosen mode will be skipped. Nobody's pay changes until the cycle is submitted, reviewed, approved and executed by different people.",
                    BankAccountImports::TYPE => "This will add bank details for {$record->create_count} employee(s) and update them for {$record->update_count}; {$record->error_count} row(s) with errors and {$record->skip_count} skipped row(s) are left out. Each change is recorded on the employee's timeline.",
                    AttendanceSheetImports::TYPE => 'This will mark '.self::monthLabel($record)." attendance for {$record->create_count} employee(s) and correct it for {$record->update_count}; {$record->error_count} row(s) with errors and {$record->skip_count} skipped row(s) are left out. The marked days are then recalculated.",
                    default => "This will create {$record->create_count} and update {$record->update_count} employee(s); {$record->error_count} row(s) with errors and {$record->skip_count} skipped row(s) are left out.",
                })
                ->schema([Textarea::make(AuditReasonField::NAME)->label('Reason')->required()->maxLength(500)])
                ->action(fn (EmployeeImport $record, array $data) => self::run(fn () => $svc($record)->approve($record, auth()->user(), $data[AuditReasonField::NAME]), 'Import approved')),

            Action::make('run')->label('Run import')->icon('heroicon-m-play')->color('success')
                ->visible(fn (EmployeeImport $record) => $record->status === 'approved')
                ->requiresConfirmation()
                ->action(fn (EmployeeImport $record) => self::run(fn () => $svc($record)->run($record, auth()->user()), fn (EmployeeImport $i) => match ($i->type) {
                    SalaryImports::TYPE => "Cycle prepared with {$i->create_count} line(s); skipped {$i->skip_count}, failed {$i->failure_count}. Open the cycle to submit it for review.",
                    BankAccountImports::TYPE => "Added {$i->create_count}, updated {$i->update_count}, skipped {$i->skip_count}, failed {$i->failure_count}",
                    AttendanceSheetImports::TYPE => 'Marked '.($i->create_count + $i->update_count)." employee(s), skipped {$i->skip_count}, failed {$i->failure_count}. The marked days are being recalculated.",
                    default => "Created {$i->create_count}, updated {$i->update_count}, skipped {$i->skip_count}, failed {$i->failure_count}",
                })),

            Action::make('openCycle')->label('Open compensation cycle')->icon('heroicon-m-arrow-path-rounded-square')->color('gray')
                ->visible(fn (EmployeeImport $record) => $record->type === SalaryImports::TYPE && filled($record->options['compensation_cycle_id'] ?? null))
                ->url(fn (EmployeeImport $record) => CompensationCycleResource::getUrl('view', ['record' => $record->options['compensation_cycle_id']])),

            // HROne setup parity P2 (Recent imports): the file as uploaded and the result of every row, audited.
            self::downloadOriginal(),
            self::downloadResults(),

            Action::make('discard')->label('Discard')->icon('heroicon-m-trash')->color('danger')
                ->visible(fn (EmployeeImport $record) => ! in_array($record->status, ['importing', 'imported', 'discarded'], true))
                ->requiresConfirmation()
                ->action(fn (EmployeeImport $record) => self::run(fn () => $svc($record)->discard($record), 'Import discarded')),
        ];
    }

    /** HROne setup parity P2: download the file as uploaded (audited; only for people who may open the upload). */
    public static function downloadOriginal(): Action
    {
        return Action::make('downloadFile')->label('Download file')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (EmployeeImport $record) => auth()->user()?->can('view', $record) ?? false)
            ->action(fn (EmployeeImport $record) => self::download(fn () => app(ImportDownloads::class)->original($record, auth()->user())));
    }

    /** HROne setup parity P2: download every staged row with its action, outcome and errors (audited). */
    public static function downloadResults(): Action
    {
        return Action::make('downloadResults')->label('Download results')->icon(Heroicon::OutlinedDocumentArrowDown)->color('gray')
            ->visible(fn (EmployeeImport $record) => $record->status !== 'uploaded' && (auth()->user()?->can('view', $record) ?? false))
            ->action(fn (EmployeeImport $record) => self::download(fn () => app(ImportDownloads::class)->results($record, auth()->user())));
    }

    /** @param  callable(): array{0: string, 1: string}  $file */
    private static function download(callable $file): mixed
    {
        try {
            [$name, $contents] = $file();

            return response()->streamDownload(fn () => print ($contents), $name, ['Content-Type' => 'text/csv']);
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();

            return null;
        }
    }

    /** The "new or existing" choice an upload offers, with what each choice does (shown as cards). */
    public static function modeField(array $modes): Radio
    {
        return Radio::make('mode')->label('What is this file for?')->extraAttributes(['class' => 'pos-di-choices'])
            ->options(array_map(fn (array $mode) => $mode[0], $modes))->descriptions(array_map(fn (array $mode) => $mode[1], $modes))->required();
    }

    public static function monthField(): Select
    {
        return Select::make('month')->label('Attendance month')->options(fn () => AttendanceSheetImports::months())->default(fn () => AttendanceSheetImports::currentMonth())->required();
    }

    public static function blankDaysField(): Radio
    {
        return Radio::make('blank_days')->label('Blank days')->options(array_map(fn (array $o) => $o[0], AttendanceSheetImports::BLANK_DAYS))
            ->descriptions(array_map(fn (array $o) => $o[1], AttendanceSheetImports::BLANK_DAYS))->default('calculate')->required();
    }

    /** The CSV field of every upload: a large drop zone; the file goes to the private disk, under the tenant's import folder. */
    public static function fileField(): FileUpload
    {
        return FileUpload::make('file')->label('CSV file')->disk(EmployeeImports::disk())->directory(fn () => EmployeeImports::directory())->visibility('private')
            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
            ->placeholder(fn () => self::dropLabel())->extraAttributes(['class' => 'pos-di-dropzone'])
            ->storeFileNamesIn('original_name')->required();
    }

    /** The drop zone's text (FilePond's idle label takes HTML). */
    public static function dropLabel(): string
    {
        $mb = max(1, intdiv((int) config('peopleos.documents.max_kb', 10240), 1024));

        return '<span class="pos-di-drop-icon">'.svg('heroicon-o-arrow-up-tray')->toHtml().'</span>'
            .'<span class="pos-di-drop-main">Drop your CSV file here</span>'
            .'<span class="pos-di-drop-hint">or <span class="filepond--label-action">browse your computer</span> · CSV, up to '.$mb.' MB</span>';
    }

    /** The uploaded file's own name, as the upload field stored it. */
    public static function originalName(array $data): string
    {
        $name = $data['original_name'] ?? null;

        return (string) (is_array($name) ? (reset($name) ?: basename($data['file'])) : ($name ?? basename($data['file'])));
    }

    /** The format guide inside an upload dialog, open under the file and the choices. */
    public static function formatSection(string $type): View
    {
        return self::formatSectionFor(fn () => $type);
    }

    /**
     * The format guide for the type a dialog has chosen (closure: the type, or null while none is chosen), as an open
     * block under the fields — not a form section, so the dialog stays one page instead of becoming a step wizard.
     */
    public static function formatSectionFor(\Closure $type): View
    {
        return View::make('filament.imports.format-guide-block')->columnSpanFull()
            ->visible(fn (Get $get) => filled($type($get)))
            ->viewData(fn (Get $get) => ['format' => filled($found = $type($get)) ? self::formatData((string) $found) : []]);
    }

    /** The format guide on its own, from the list page. */
    public static function formatAction(string $type): Action
    {
        return Action::make('format')->label('File format')->icon(Heroicon::OutlinedInformationCircle)->color('gray')
            ->modalHeading((self::TYPES[$type][1] ?? RowImportTypes::TYPES[$type][1] ?? $type).': file format')->modalWidth('4xl')
            ->modalContent(fn () => view('filament.imports.csv-format', self::formatData($type)))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    /** @return array<string, mixed> what the format guide view shows, from the import service so it matches what the upload accepts */
    public static function formatData(string $type): array
    {
        $service = self::service($type);
        $format = $service->format();

        $sample = $format['sample'] ?? $service->template();

        return ['title' => self::TYPES[$type][1] ?? RowImportTypes::TYPES[$type][1] ?? $type, 'columns' => $format['columns'], 'codes' => $format['codes'] ?? null, 'codesTitle' => $format['codes_title'] ?? null, 'modes' => self::modes($type),
            'notes' => $format['notes'], 'sample' => $sample, 'sampleRows' => iterator_to_array(Reader::fromString($sample)->getRecords(), false)];
    }

    /**
     * The head of an upload's own page (filament.imports.upload-summary): what the upload is, its module's tint, where it
     * stands in the pipeline (uploaded → columns matched → rows checked → approved → run), the next step, and its counts.
     *
     * @return array<string, mixed>
     */
    public static function summary(EmployeeImport $import): array
    {
        $process = ImportCatalogue::find($import->options['process'] ?? null);
        $head = $process['head'] ?? ImportTypes::module((string) $import->type) ?? 'workforce';
        [$module, $icon] = ImportCatalogue::HEADS[$head] ?? ['Data import', 'heroicon-o-arrow-up-tray'];
        $hue = DataImport::TONES[$head] ?? 'iris';
        $status = (string) $import->status;
        $run = in_array($status, ['imported', 'failed'], true);
        $modes = ImportTypes::modes((string) $import->type);

        return [
            'tone' => "--pos-tone: var(--pos-hue-{$hue}); --pos-tone-bg: var(--pos-hue-{$hue}-bg);",
            'icon' => $icon, 'module' => $module, 'title' => $process['label'] ?? ImportTypes::label((string) $import->type),
            'file' => $import->original_name, 'uploader' => $import->uploader?->name ?? 'someone', 'uploadedAt' => $import->created_at?->format('j M Y, H:i'),
            'intent' => $modes[ImportMode::of($import)->value][0] ?? null, 'status' => ucfirst($status),
            'steps' => ['Uploaded', 'Columns matched', 'Rows checked', 'Approved', 'Run'],
            'current' => match ($status) {
                'uploaded', 'inspected' => 1, 'mapped' => 2, 'validated' => 3, 'approved', 'importing' => 4, 'imported', 'failed' => 5, default => 0
            },
            'next' => match ($status) {
                'uploaded' => 'Next: Inspect file reads the rows and matches the columns by name.',
                'inspected' => 'Next: Map columns to confirm the PeopleOS field of each column.',
                'mapped' => 'Next: Validate rows checks every row.',
                'validated' => $import->error_count > 0
                    ? "Next: correct the {$import->error_count} row(s) with errors in your file and upload it again, or approve the import to run the rest."
                    : 'Next: approve the import, then run it.',
                'approved' => 'Next: Run import writes the rows.',
                'importing' => 'The import is running.',
                default => null,
            },
            // Until the rows are checked only their number is known.
            'stats' => match (true) {
                $status === 'uploaded' => [],
                in_array($status, ['inspected', 'mapped'], true) => [['Rows', $import->row_count, '']],
                default => array_values(array_filter([
                    ['Rows', $import->row_count, ''],
                    [$run ? 'Added' : 'To add', $import->create_count, 'add'],
                    [$run ? 'Updated' : 'To update', $import->update_count, 'update'],
                    ['Skipped', $import->skip_count, ''],
                    $run ? ['Failed', $import->failure_count, 'error'] : ['With errors', $import->error_count, 'error'],
                    $import->review_count > 0 ? ['To decide', $import->review_count, 'decide'] : null,
                ])),
            },
        ];
    }

    public static function monthLabel(EmployeeImport $import): string
    {
        $month = $import->options['month'] ?? null;
        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return 'the month\'s';
        }

        // The dates the upload was made for, when it kept them (a 25th-to-24th pay cycle shows them).
        return isset($import->options['from'], $import->options['to'])
            ? AttendanceSheetImports::monthName($month, Carbon::parse($import->options['from']), Carbon::parse($import->options['to']))
            : Carbon::parse("{$month}-01")->format('F Y');
    }

    public static function run(callable $callback, callable|string $success): mixed
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();

            return $result;
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();
        }

        return null;
    }
}

<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportDownloads;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
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

    /** @return array<string, array{0: string, 1: string}>|null mode => [label, description], for the imports that offer the choice */
    public static function modes(string $type): ?array
    {
        $class = self::TYPES[$type][0] ?? RowImportTypes::TYPES[$type][0] ?? EmployeeImports::class;

        return defined("{$class}::MODES") ? $class::MODES : null;
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

    /** The "new or existing" choice an upload offers, with what each choice does. */
    public static function modeField(array $modes): Radio
    {
        return Radio::make('mode')->label('What is this file for?')
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

    /** The CSV field of every upload dialog: a file on the private disk, under the tenant's import folder. */
    public static function fileField(): FileUpload
    {
        return FileUpload::make('file')->label('CSV file')->disk(EmployeeImports::disk())->directory(fn () => EmployeeImports::directory())->visibility('private')
            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
            ->storeFileNamesIn('original_name')->required();
    }

    /** The uploaded file's own name, as the upload field stored it. */
    public static function originalName(array $data): string
    {
        $name = $data['original_name'] ?? null;

        return (string) (is_array($name) ? (reset($name) ?: basename($data['file'])) : ($name ?? basename($data['file'])));
    }

    /** The format guide inside an upload dialog, folded away under the fields. */
    public static function formatSection(string $type): Section
    {
        return Section::make('File format and example')->description('Every column, what to enter, and an example file.')->collapsible()->collapsed()
            ->schema([View::make('filament.imports.csv-format')->viewData(fn () => self::formatData($type))]);
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

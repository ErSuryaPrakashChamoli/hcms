<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Filament\Resources\AttendanceSheetImports\AttendanceSheetImportResource;
use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\PayrollUploads\PayrollUploadResource;
use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Resources\SalaryImports\SalaryImportResource;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use App\Filament\Support\ImportActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

/**
 * HROne setup parity P2 (Data import → Recent imports): every upload of the last twelve months across modules —
 * employees, bank details, punches, monthly attendance, salaries and the time-office and payroll uploads — in one list,
 * month by month: type and mode, file, uploader, upload / run start / run end times, counts, status and message,
 * who approved it and how (uploader, maker-checker, workflow), the workflow's status, where it came from and its module.
 * Each person sees the upload types they may work on (bank and salary uploads: their own and those they check), opens
 * an upload on its own screen and downloads its file and result file (audited).
 */
class RecentUploads extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Recent uploads';

    protected static ?string $title = 'Recent uploads';

    protected static ?string $slug = 'recent-uploads';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.recent-uploads';

    public static function canAccess(): bool
    {
        return ImportTypes::allowedTypes(auth()->user()) !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, string> Y-m => "October 2026", this month and the eleven before it */
    public static function months(): array
    {
        $months = [];
        $month = Carbon::now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $months[$month->format('Y-m')] = $month->format('F Y');
            $month = $month->subMonth();
        }

        return $months;
    }

    /** The uploads the signed-in person may see, from the last twelve months. */
    public static function visibleUploads(): Builder
    {
        $user = auth()->user();
        $types = ImportTypes::allowedTypes($user);

        return EmployeeImport::query()->with(['uploader', 'approver'])
            ->where('created_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->where(function (Builder $query) use ($types, $user) {
                $query->whereRaw('1 = 0');
                foreach ($types as $type) {
                    $query->orWhere(fn (Builder $q) => in_array($type, [BankAccountImports::TYPE, SalaryImports::TYPE], true)
                        ? ImportGovernance::ownOrChecked($q->where('type', $type), $type, $user)
                        : $q->where('type', $type));
                }
            });
    }

    /** The screen an upload is worked on. */
    public static function urlFor(EmployeeImport $import): ?string
    {
        $resource = match ($import->type) {
            'employees' => EmployeeImportResource::class,
            BankAccountImports::TYPE => BankAccountImportResource::class,
            SalaryImports::TYPE => SalaryImportResource::class,
            PunchImports::TYPE => PunchImportResource::class,
            AttendanceSheetImports::TYPE => AttendanceSheetImportResource::class,
            default => (RowImportTypes::TYPES[$import->type][3] ?? null) === 'payroll' ? PayrollUploadResource::class : TimeOfficeImportResource::class,
        };
        try {
            return $resource::canAccess() ? $resource::getUrl('view', ['record' => $import]) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function table(Table $table): Table
    {
        $run = fn (EmployeeImport $r) => in_array($r->status, ['imported', 'failed'], true);

        return $table
            ->query(fn (): Builder => self::visibleUploads())
            ->columns([
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
                TextColumn::make('type')->label('Upload')->formatStateUsing(fn (string $state) => ImportTypes::label($state))
                    ->description(fn (EmployeeImport $record) => ImportActions::modes((string) $record->type)[ImportMode::of($record)->value][0] ?? null),
                TextColumn::make('module')->label('Module')->state(fn (EmployeeImport $record) => ImportTypes::moduleLabel((string) $record->type))->toggleable(),
                TextColumn::make('original_name')->label('File')->searchable()->wrap(),
                TextColumn::make('uploader.name')->label('Uploaded by')->searchable(),
                TextColumn::make('run_started_at')->label('Run started')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('imported_at')->label('Run finished')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('row_count')->label('Rows')->numeric(),
                TextColumn::make('processed')->label('Processed')->state(fn (EmployeeImport $r) => $run($r) ? $r->create_count + $r->update_count + $r->skip_count + $r->failure_count : null)->placeholder('—'),
                TextColumn::make('succeeded')->label('Succeeded')->state(fn (EmployeeImport $r) => $run($r) ? $r->create_count + $r->update_count : null)->placeholder('—'),
                TextColumn::make('failure_count')->label('Failed')->state(fn (EmployeeImport $r) => $run($r) ? $r->failure_count : null)->placeholder('—'),
                TextColumn::make('error_count')->label('Errors')->toggleable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'imported' => 'success', 'approved', 'validated' => 'warning', 'failed', 'discarded' => 'danger', default => 'gray',
                })->description(fn (EmployeeImport $record) => $record->error ? str($record->error)->limit(80)->toString() : null),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')
                    ->description(fn (EmployeeImport $record) => ImportGovernance::approvalLabel($record)),
                TextColumn::make('workflow')->label('Workflow')->state(fn (EmployeeImport $record) => ImportGovernance::workflowStatus($record))->placeholder('—')->toggleable(),
                TextColumn::make('source')->label('Uploaded through')->formatStateUsing(fn (?string $state) => EmployeeImport::SOURCES[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('month')->label('Month')->options(self::months())->default(Carbon::now()->format('Y-m'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereBetween('created_at', [Carbon::parse($data['value'].'-01')->startOfMonth(), Carbon::parse($data['value'].'-01')->endOfMonth()])
                        : $query),
                SelectFilter::make('type')->label('Upload')->options(fn () => collect(ImportTypes::allowedTypes(auth()->user()))->mapWithKeys(fn (string $t) => [$t => ImportTypes::label($t)])->all()),
                SelectFilter::make('module')->label('Module')->options(ImportTypes::MODULES)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('type', array_values(array_filter(array_keys(ImportTypes::labels()), fn (string $t) => ImportTypes::module($t) === $data['value'])))
                        : $query),
                SelectFilter::make('status')->options(array_combine(EmployeeImport::STATUSES, array_map('ucfirst', EmployeeImport::STATUSES))),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->url(fn (EmployeeImport $record) => self::urlFor($record))->visible(fn (EmployeeImport $record) => self::urlFor($record) !== null),
                ImportActions::downloadOriginal(),
                ImportActions::downloadResults(),
            ])
            ->emptyStateHeading('No uploads this month')
            ->emptyStateDescription('Uploads of the types you may work on appear here: choose another month, or upload a file on its own screen.');
    }
}

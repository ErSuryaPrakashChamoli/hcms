<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Models\PaidDayAdjustment;
use App\Domain\Attendance\Services\AttendanceMonthDesk;
use App\Domain\Attendance\Services\AttendanceMonthPopulation;
use App\Domain\Attendance\Services\PaidDayAdjustments;
use App\Domain\Attendance\Services\PaidDaysRegister;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\AdjustPaidDaysOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use App\Livewire\Attendance\DayDrawer;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * HROne parity TX-4 (matrix 24 §24.5.4, HR Desk → Time Office → Paid days): paid days of a company's people for an
 * attendance month, or for the financial year up to it — present days, paid leave, unpaid leave, holidays, weekly offs,
 * absent days, late-mark penalty days, arrear days, adjustments and the paid days that result — with WO-2's employee
 * filters, saved views, a report link and an audited CSV; a per-employee breakdown (months, hours by kind of day, why paid
 * days are lower); and, with attendance.paid_day_adjustments.enabled on (owner decision TIM-2 / D-TX2, off by default),
 * "Adjust paid days" for one employee or the selected ones (preview first, reason required) and reversal.
 *
 * Opens for attendance.view, inside the viewer's access scope; adjusting needs attendance.override (TX-2), and the bulk
 * form also bulk.run.
 */
class PaidDays extends RegisterTablePage
{
    use HasRegisterTools;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Paid days';

    protected static ?string $title = 'Paid days';

    protected static ?string $slug = 'paid-days';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.paid-days';

    public const REGISTER = 'paid_days';

    #[Url]
    public ?string $company = null;

    #[Url]
    public ?string $month = null;

    /** month = the attendance month; year = the financial year up to it. */
    #[Url]
    public string $period = 'month';

    /** The employee whose breakdown is open. */
    #[Url]
    public ?string $detail = null;

    /** @var array<int, array<string, mixed>> this request's figures, per employee */
    private array $figureMemo = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $windowMemo = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('attendance.view') ?? false;
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public static function presetViews(): array
    {
        return [
            'adjusted' => ['label' => 'Paid days adjusted', 'filters' => ['adjusted' => ['isActive' => true]]],
            'penalties' => ['label' => 'With penalty days', 'filters' => ['penalties' => ['isActive' => true]]],
            'arrears' => ['label' => 'With arrear days', 'filters' => ['arrears' => ['isActive' => true]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['paid_days_last_month', 'paid_days_this_year'];
    }

    public function getSubheading(): ?string
    {
        return 'Paid days of the month before payroll: present days, paid leave, holidays and weekly offs, less what is not paid'
            .($this->adjustmentsOn() ? '; adjust them for an employee or the selected ones.' : '.');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companies();
        if (! array_key_exists((int) $this->company, $companies)) {
            $this->company = $companies === [] ? null : (string) array_key_first($companies);
        }
        $this->period = $this->period === 'year' ? 'year' : 'month';
        $this->normaliseMonth();
    }

    /** @return array<int, string> */
    public function companies(): array
    {
        return Company::query()->orderBy('name')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        return $this->companyId() === null ? [] : app(AttendanceMonthDesk::class)->months($this->companyId());
    }

    public function companyId(): ?int
    {
        return filled($this->company) ? (int) $this->company : null;
    }

    /** @return array{0: int, 1: int} */
    public function yearMonth(): array
    {
        return [(int) substr((string) $this->month, 0, 4), (int) substr((string) $this->month, 5, 2)];
    }

    public function yearToDate(): bool
    {
        return $this->period === 'year';
    }

    public function adjustmentsOn(): bool
    {
        return app(PaidDayAdjustments::class)->enabled();
    }

    public function canAdjust(): bool
    {
        return $this->adjustmentsOn() && (auth()->user()?->hasPermission(PaidDayAdjustments::PERMISSION) ?? false);
    }

    public function updatedCompany(): void
    {
        if (! array_key_exists((int) $this->company, $this->companies())) {
            $this->company = (string) array_key_first($this->companies());
        }
        $this->normaliseMonth();
        $this->afterChange();
    }

    public function updatedMonth(): void
    {
        $this->normaliseMonth();
        $this->afterChange();
    }

    public function updatedPeriod(): void
    {
        $this->period = $this->period === 'year' ? 'year' : 'month';
        $this->afterChange();
    }

    /** @return list<array{year: int, month: int, key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}> */
    public function windows(): array
    {
        if ($this->companyId() === null || ! filled($this->month)) {
            return [];
        }
        [$year, $month] = $this->yearMonth();

        return $this->windowMemo ??= app(PaidDaysRegister::class)->windows($this->companyId(), $year, $month, $this->yearToDate());
    }

    /** "Oct 2026 · 1 Oct – 31 Oct", or "FY 2026–27 · Apr 2026 – Oct 2026". */
    public function periodLabel(): string
    {
        $windows = $this->windows();
        if ($windows === []) {
            return '';
        }
        $last = end($windows);
        if (! $this->yearToDate()) {
            return $last['label'].' · '.$last['start']->format('j M').' – '.$last['end']->format('j M Y');
        }
        [$year, $month] = $this->yearMonth();

        return PaidDaysRegister::yearLabel($year, $month).' · '.$windows[0]['label'].' – '.$last['label'];
    }

    // --- The register ------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        $number = fn (string $key) => fn (Employee $record) => $this->figure($record, $key);

        return $table
            ->query(fn (): Builder => $this->population())
            ->modifyQueryUsing(fn (Builder $query) => $query->with('person'))
            ->heading(fn () => $this->periodLabel())
            ->description('Paid days = present days + paid leave + holidays + weekly offs'.($this->adjustmentsOn() ? ', after any adjustment,' : '').' less late-mark penalty days. Arrear days are corrections to days already locked, paid in a later month.')
            ->columns([
                TextColumn::make('employee_code')->label('Employee code')->sortable(),
                TextColumn::make('person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search()),
                TextColumn::make('present')->label('Present')->state($number('present'))->numeric(2),
                TextColumn::make('paid_leave')->label('Paid leave')->state($number('paid_leave'))->numeric(2),
                TextColumn::make('unpaid_leave')->label('Unpaid leave')->state($number('unpaid_leave'))->numeric(2),
                TextColumn::make('holiday')->label('Holidays')->state($number('holiday'))->numeric(2),
                TextColumn::make('weekly_off')->label('Weekly offs')->state($number('weekly_off'))->numeric(2),
                TextColumn::make('absent')->label('Absent')->state($number('absent'))->numeric(2),
                TextColumn::make('half_days')->label('Half days')->state($number('half_days'))->numeric(0)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('penalty_days')->label('Penalty days')->state($number('penalty_days'))->numeric(2),
                TextColumn::make('arrear_days')->label('Arrear days')->state($number('arrear_days'))->numeric(2),
                TextColumn::make('adjustment')->label('Adjustments')->state($number('adjustment'))->numeric(2)
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'success' : null))->visible(fn () => $this->adjustmentsOn()),
                TextColumn::make('paid_days_processed')->label('Paid days as processed')->state($number('paid_days_processed'))->numeric(2)
                    ->toggleable(isToggledHiddenByDefault: true)->visible(fn () => $this->adjustmentsOn()),
                TextColumn::make('paid_days')->label('Paid days')->state($number('paid_days'))->numeric(2)->weight('bold'),
                TextColumn::make('days')->label('Days processed')->state($number('days'))->numeric(0)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('working_hours')->label('Hours on working days')->state($number('working_hours'))->numeric(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('weekly_off_hours')->label('Hours on weekly offs')->state($number('weekly_off_hours'))->numeric(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('holiday_hours')->label('Hours on holidays')->state($number('holiday_hours'))->numeric(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('overtime_hours')->label('Overtime hours (approved)')->state($number('overtime_hours'))->numeric(2)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                EmployeeFilters::codes(),
                EmployeeFilters::organisation(),
                EmployeeFilters::workforce(),
                EmployeeFilters::excludePendingResignation(),
                Filter::make('adjusted')->label('Paid days adjusted')->toggle()->visible(fn () => $this->adjustmentsOn())
                    ->query(fn (Builder $query) => $query->whereIn('employees.id', $this->adjustedIds())),
                Filter::make('penalties')->label('With late-mark penalty days')->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('employees.id', $this->withinWindows(AttendancePenalty::query()->where('status', 'applied')->where('days', '>', 0)))),
                Filter::make('arrears')->label('With arrear days')->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('employees.id', $this->withinWindows(AttendanceArrear::query()->whereIn('status', ['pending', 'held', 'settled'])))),
            ])
            ->recordActions([
                Action::make('breakdown')->label('Breakdown')->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->action(fn (Employee $record) => $this->detail = (string) $record->id),
                $this->adjustAction(),
            ])
            ->toolbarActions([$this->adjustSelectedAction()])
            ->defaultSort('employee_code')
            ->paginated([25, 50, 100]);
    }

    /** The register's employees: the people of the company in the month(s), inside the viewer's access scope. */
    private function population(): Builder
    {
        $windows = $this->windows();
        if ($windows === []) {
            return Employee::query()->whereRaw('1 = 0');
        }

        return app(AttendanceMonthPopulation::class)->query((int) $this->companyId(), $windows[0]['start'], end($windows)['end']);
    }

    /** One register figure for an employee (computed for the whole page at once). */
    public function figure(Employee $employee, string $key): float
    {
        $id = (int) $employee->id;
        if (! array_key_exists($id, $this->figureMemo)) {
            $records = $this->getTableRecords();
            $ids = collect($records instanceof Paginator ? $records->items() : $records)->map(fn ($r) => (int) $r->getKey())->push($id)->unique()->values()->all();
            $this->figureMemo += app(PaidDaysRegister::class)->figures($ids, (int) $this->companyId(), $this->windows());
        }

        return (float) ($this->figureMemo[$id]['total'][$key] ?? 0);
    }

    /** Employees with a counting adjustment in the month(s). */
    private function adjustedIds(): Builder
    {
        $windows = $this->windows();

        return PaidDayAdjustment::query()->select('employee_id')->where('company_id', (int) $this->companyId())->where('status', 'active')
            ->where(fn ($q) => collect($windows)->each(fn (array $w) => $q->orWhere(fn ($m) => $m->where('year', $w['year'])->where('month', $w['month']))))
            ->when($windows === [], fn ($q) => $q->whereRaw('1 = 0'));
    }

    private function withinWindows(Builder $query): Builder
    {
        $windows = $this->windows();
        if ($windows === []) {
            return $query->select('employee_id')->whereRaw('1 = 0');
        }

        return $query->select('employee_id')->whereDate('date', '>=', $windows[0]['start']->toDateString())->whereDate('date', '<=', end($windows)['end']->toDateString());
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('downloadCsv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn () => $this->downloadCsv()),
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        abort_unless(static::canAccess() && $this->companyId() !== null, 403);
        [$year, $month] = $this->yearMonth();
        $employees = $this->getFilteredSortedTableQuery()->with('person')->limit(BulkRuns::maxRecords())->get();
        $csv = app(PaidDaysRegister::class)->csv(auth()->user(), $employees, (int) $this->companyId(), $year, $month, $this->yearToDate());
        $name = 'paid-days-'.($this->yearToDate() ? 'year-to-' : '').$this->month.'.csv';

        return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv']);
    }

    // --- Breakdown ---------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the open employee's breakdown (null when none, or out of the viewer's reach) */
    public function breakdown(): ?array
    {
        $employee = $this->detailed();
        if ($employee === null) {
            return null;
        }
        [$year, $month] = $this->yearMonth();

        return app(PaidDaysRegister::class)->breakdown($employee, (int) $this->companyId(), $year, $month, $this->yearToDate())
            + ['refusal' => $this->canAdjust() ? app(PaidDayAdjustments::class)->refusal($employee, (int) $this->companyId(), $year, $month, auth()->user()) : null];
    }

    public function closeDetail(): void
    {
        $this->detail = null;
    }

    /** HROne parity TX-7 (§24.5.4 #5 "Change"): a breakdown row's day opens the HR day drawer (attendance.view). */
    public function canOpenDays(): bool
    {
        return DayDrawer::available();
    }

    /** HROne parity TX-7: a change in the day drawer redraws the register and the breakdown. */
    #[On('pos-attendance-day-changed')]
    public function dayChanged(): void
    {
        $this->figureMemo = [];
    }

    private function detailed(): ?Employee
    {
        if (! filled($this->detail) || $this->windows() === []) {
            return null;
        }
        $employee = $this->population()->with('person')->whereKey((int) $this->detail)->first();

        return $employee !== null && app(AccessScopes::class)->allows(auth()->user(), $employee) ? $employee : null;
    }

    // --- Adjust paid days (TIM-2) -----------------------------------------------------------------------------------

    private function adjustAction(): Action
    {
        return Action::make('adjust')->label('Adjust paid days')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('warning')
            ->visible(fn () => $this->canAdjust() && ! $this->yearToDate())
            ->modalHeading(fn (Employee $record) => 'Adjust paid days · '.$record->employee_code.' · '.$this->monthLabel())
            ->modalSubmitActionLabel('Save adjustment')
            ->fillForm(fn (Employee $record) => $this->formDefaults($record))
            ->steps(fn (Employee $record) => [
                Step::make('Adjustment')->description('Set the counts or reduce chosen days')->schema(AdjustPaidDaysOperation::fields()),
                Step::make('Preview')->description('What the month becomes, and why')->schema([
                    Text::make(fn (Get $get) => $this->previewHtml(collect([$record]), $this->formInput($get))),
                    Textarea::make('reason')->label('Reason')->required()->maxLength(255),
                ]),
            ])
            ->action(function (Employee $record, array $data) {
                try {
                    [$year, $month] = $this->yearMonth();
                    $adjustment = app(PaidDayAdjustments::class)->save($record, (int) $this->companyId(), $year, $month, AdjustPaidDaysOperation::input($data), (string) ($data['reason'] ?? ''), auth()->user());
                    Notification::make()->success()->title('Paid days adjusted')
                        ->body($record->employee_code.' · '.$adjustment->label().': '.PaidDayAdjustment::number((float) $adjustment->paid_days_before).' → '.PaidDayAdjustment::number((float) $adjustment->paid_days_after))->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Paid days not adjusted')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    private function adjustSelectedAction(): BulkAction
    {
        $operation = app(BulkOperations::class)->find('paid_days.adjust');

        return BulkAction::make('adjustSelected')->label('Adjust paid days')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('warning')
            ->visible(fn () => $this->canAdjust() && ! $this->yearToDate() && $operation !== null && app(BulkOperations::class)->allows(auth()->user(), $operation))
            ->deselectRecordsAfterCompletion()
            ->modalHeading(fn (BulkAction $action) => 'Adjust paid days · '.$this->monthLabel().' · '.count($action->getSelectedRecords()).' employee(s)')
            ->modalSubmitActionLabel('Start')
            ->steps(function (BulkAction $action) {
                $records = collect($action->getSelectedRecords());
                $typed = BulkRuns::needsTypedCount($records->count());

                return [
                    Step::make('Adjustment')->description('The same adjustment for every employee selected')->schema(AdjustPaidDaysOperation::fields()),
                    Step::make('Preview')->description('Each employee\'s month before and after')->schema([
                        Text::make(fn (Get $get) => $this->previewHtml($records, $this->formInput($get))),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(255),
                        TextInput::make('confirm_count')->label('Type '.$records->count().' to confirm')->visible($typed)->required($typed)
                            ->in([(string) $records->count()])->validationMessages(['in' => 'Type exactly '.$records->count().'.']),
                    ]),
                ];
            })
            ->action(function (BulkAction $action, array $data) {
                [$year, $month] = $this->yearMonth();
                $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
                unset($data['confirm_count']);
                try {
                    $run = app(BulkRuns::class)->start(auth()->user(), self::REGISTER, 'paid_days.adjust', collect($action->getSelectedRecords())->map(fn ($r) => $r->getKey())->all(),
                        ['company_id' => $this->companyId(), 'month' => sprintf('%04d-%02d', $year, $month)] + $data, 'selected', $confirmed);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Bulk run not started')->body($e->getMessage())->send();

                    return;
                }
                $run->refresh();
                Notification::make()->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))
                    ->title('Adjust paid days: '.($run->isFinished() ? $run->summary() : 'queued for '.$run->total.' employee(s)'))
                    ->body($run->isFinished() && $run->refused + $run->failed > 0 ? 'Some employees were not adjusted; the run lists each one with the reason.' : null)
                    ->actions([Action::make('open')->label('See each employee')->url(BulkRunResource::getUrl('view', ['record' => $run]))])->send();
                $this->afterChange();
            });
    }

    public function reverseAdjustmentAction(): Action
    {
        return Action::make('reverseAdjustment')->label('Reverse')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')->size('sm')
            ->visible(fn () => $this->canAdjust())
            ->modalHeading('Reverse the paid-day adjustment')
            ->modalDescription('The month\'s paid days go back to what attendance processing gives. The adjustment is kept, marked reversed.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(function (array $data, array $arguments) {
                try {
                    $adjustment = PaidDayAdjustment::query()->whereKey((int) ($arguments['adjustment'] ?? 0))->first();
                    if ($adjustment === null) {
                        throw new RuntimeException('This adjustment is not in your access.');
                    }
                    app(PaidDayAdjustments::class)->reverse($adjustment, auth()->user(), (string) $data['reason']);
                    Notification::make()->success()->title('Adjustment reversed')->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not reversed')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /**
     * The preview of an adjustment for employees (the first 50 shown): each one's month before → after, or why it would be
     * refused. Built from PaidDayAdjustments::preview, so it is exactly what saving does.
     *
     * @param  Collection<int, Employee>  $employees
     */
    public function previewHtml(Collection $employees, array $input): HtmlString
    {
        if ($this->companyId() === null) {
            return new HtmlString('');
        }
        [$year, $month] = $this->yearMonth();
        $service = app(PaidDayAdjustments::class);
        $rows = [];
        $rounding = null;
        foreach ($employees->take(50) as $employee) {
            try {
                $preview = $service->preview($employee, (int) $this->companyId(), $year, $month, $input, auth()->user());
            } catch (Throwable $e) {
                $rows[] = '<li>'.e($employee->employee_code).': '.e($e->getMessage()).'</li>';

                continue;
            }
            $rounding ??= $preview['rounding_label'];
            if ($preview['effect'] === null || $preview['refusal'] !== null) {
                $rows[] = '<li data-preview="refused">'.e($preview['employee']).': <span class="text-danger-600">'.e((string) $preview['refusal']).'</span></li>';

                continue;
            }
            $lines = collect($preview['effect']['lines'])->map(fn (array $l) => e($l['label']).' '.PaidDayAdjustment::number($l['count']).' → '.PaidDayAdjustment::number($l['after']).' ('.e($l['note']).')')->implode('; ');
            $change = $preview['effect']['change'];
            $rows[] = '<li data-preview="ok"><strong>'.e($preview['employee']).'</strong>: paid days '.PaidDayAdjustment::number($preview['effect']['before']).' → '
                .PaidDayAdjustment::number($preview['effect']['after']).' ('.($change > 0 ? '+' : '').PaidDayAdjustment::number($change).')'
                .($lines !== '' ? '<br><span class="text-xs text-gray-500">'.$lines.'</span>' : '')
                .($preview['replaces'] ? '<br><span class="text-xs text-warning-600">Replaces the adjustment saved '.e($preview['replaces']->created_at?->format('j M Y')).' ('.e($preview['replaces']->describe()).').</span>' : '')
                .'</li>';
        }
        $more = $employees->count() > 50 ? '<p class="text-xs text-gray-500">… and '.($employees->count() - 50).' more, each handled on its own.</p>' : '';

        return new HtmlString('<div class="space-y-2 text-sm" data-paid-days-preview><ul class="list-disc space-y-1 ps-5">'.implode('', $rows).'</ul>'.$more
            .($rounding !== null ? '<p class="text-xs text-gray-500">Partial reductions are rounded: '.e(mb_strtolower($rounding)).' (attendance policy).</p>' : '').'</div>');
    }

    /** @return array<string, mixed> the adjustment fields of the form being filled */
    private function formInput(Get $get): array
    {
        return AdjustPaidDaysOperation::input(['mode' => $get('mode'), 'counts' => (array) ($get('counts') ?? []), 'reductions' => (array) ($get('reductions') ?? [])]);
    }

    /** @return array<string, mixed> the one-employee form starts from the month's processed counts */
    private function formDefaults(Employee $employee): array
    {
        $windows = $this->windows();
        $defaults = ['mode' => 'reduce', 'counts' => [], 'reductions' => []];
        if ($windows === []) {
            return $defaults;
        }
        $window = end($windows);
        $counts = app(PaidDayAdjustments::class)->counts($employee, $window['start'], $window['end']);
        foreach (array_keys(PaidDayAdjustment::CATEGORIES) as $key) {
            $defaults['reductions'][$key] = ['how' => 'none'];
        }
        $defaults['counts'] = array_intersect_key($counts, PaidDayAdjustment::CATEGORIES);

        return $defaults;
    }

    // --- Helpers ----------------------------------------------------------------------------------------------------

    public function monthLabel(): string
    {
        [$year, $month] = $this->yearMonth();

        return $year > 0 && $month > 0 ? Carbon::create($year, $month, 1)->format('M Y') : '';
    }

    private function normaliseMonth(): void
    {
        if ($this->companyId() === null) {
            $this->month = null;

            return;
        }
        if (! array_key_exists((string) $this->month, $this->monthOptions())) {
            [$year, $month] = app(AttendanceMonthDesk::class)->currentMonth($this->companyId());
            $this->month = sprintf('%04d-%02d', $year, $month);
        }
    }

    private function afterChange(): void
    {
        $this->figureMemo = [];
        $this->windowMemo = null;
        $this->resetTable();
    }
}

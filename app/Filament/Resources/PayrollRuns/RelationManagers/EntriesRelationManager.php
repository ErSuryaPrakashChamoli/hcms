<?php

namespace App\Filament\Resources\PayrollRuns\RelationManagers;

use App\Domain\Attendance\Contracts\PaidDayAdjustmentReader;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryHold;
use App\Domain\Payroll\Services\AttendancePaidDayBasis;
use App\Domain\Payroll\Services\PayrollRunCockpit;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Employees';

    /** @var array<int, array{mode: string, account_id: ?int, account: ?string, hold: ?string}>|null HROne parity PO-4: this request's payment facts per employee */
    private ?array $paymentFacts = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('payroll.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'lines', 'reviewer:id,name']))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
                TextColumn::make('paid_days')->label('Paid days')->numeric(1),
                TextColumn::make('lop_days')->label('LOP')->numeric(1),
                TextColumn::make('gross')->numeric(2),
                TextColumn::make('total_deductions')->label('Deductions')->numeric(2),
                TextColumn::make('net_pay')->label('Net')->numeric(2)->weight('bold'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'ok' => 'success', 'warning' => 'warning', default => 'danger'
                }),
                TextColumn::make('exceptions')->label('Issues')->state(fn (PayrollEntry $record) => collect($record->exceptions ?? [])->pluck('type')->map(fn ($t) => config("peopleos.payroll.exception_types.{$t}", $t))->implode(', '))->placeholder('—')->wrap(),
                // HROne parity PO-4 (§25.6.4 #12, §25.6.5 #3): how each salary is paid, its hold and the optional reviewer tick.
                TextColumn::make('pay_mode')->label('Pay mode')->state(fn (PayrollEntry $record) => EmployeePaymentMode::MODES[$this->paymentOf($record)['mode'] ?? 'online_transfer'] ?? null)->toggleable(),
                TextColumn::make('paying_account')->label('Paying account')->state(fn (PayrollEntry $record) => $this->paymentOf($record)['account'] ?? null)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hold')->label('Hold')->badge()->color(fn (?string $state) => $state === SalaryHold::STATUSES['held'] ? 'warning' : 'gray')
                    ->state(fn (PayrollEntry $record) => isset($this->paymentOf($record)['hold']) ? (SalaryHold::STATUSES[$this->paymentOf($record)['hold']] ?? null) : null)->placeholder('—')->toggleable(),
                TextColumn::make('reviewed_at')->label('Checked')->state(fn (PayrollEntry $record) => $record->reviewed_at !== null ? 'By '.($record->reviewer?->name ?? '—').' · '.$record->reviewed_at->format('j M') : null)
                    ->placeholder('—')->toggleable(),
            ])
            ->defaultSort('employee_id')
            ->filters([
                SelectFilter::make('status')->options(['ok' => 'OK', 'warning' => 'Warning', 'exception' => 'Exception']),
                // HROne parity PO-1 (matrix 25 §25.1 #3, #4): pay group on the run's period end, the WO-2 employee filters and
                // "Exclude employees with pending resignation".
                PayrollRegisters::structure('payroll.view', fn () => $this->getOwnerRecord()->period?->end_date),
                EmployeeFilters::codes('employee'),
                EmployeeFilters::organisation('employee'),
                EmployeeFilters::excludePendingResignation('employee'),
                // HROne parity PO-4 (§25.6.4 #12, §25.6.5 #2): pay mode, paying account and hold of the pay month (as the bank file
                // reads them), and the reviewer tick.
                SelectFilter::make('pay_mode')->label('Pay mode')->options(EmployeePaymentMode::MODES)
                    ->query(fn (Builder $query, array $data) => $this->whereEmployees($query, 'mode', $data['value'] ?? null)),
                SelectFilter::make('paying_account')->label('Paying account')->options(fn () => app(PayrollRunCockpit::class)->payingAccounts($this->getOwnerRecord()) + ['none' => 'None (not a bank transfer, or no paying account)'])
                    ->query(fn (Builder $query, array $data) => $this->whereEmployees($query, 'account', $data['value'] ?? null)),
                SelectFilter::make('hold')->label('Hold')->options(['held' => 'Held', 'released' => 'Released', 'none' => 'No hold'])
                    ->query(fn (Builder $query, array $data) => $this->whereEmployees($query, 'hold', $data['value'] ?? null)),
                SelectFilter::make('checked')->label('Checked')->options(['yes' => 'Checked', 'no' => 'Not checked'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'yes' => $query->whereNotNull('payroll_entries.reviewed_by'),
                        'no' => $query->whereNull('payroll_entries.reviewed_by'),
                        default => $query,
                    }),
            ])
            // HROne parity PO-4: the step table of "Process salary" as CSV (the rows the filters show, or the ones selected).
            ->headerActions([
                Action::make('downloadEntries')->label('Download CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->action(fn () => $this->downloadEntries()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('checkSelected')->label('Mark as checked')->icon('heroicon-o-check-circle')
                        ->visible(fn () => $this->canReview())
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $this->applyReview($records->modelKeys(), true)),
                    BulkAction::make('uncheckSelected')->label('Clear the check')->icon('heroicon-o-x-circle')->color('gray')
                        ->visible(fn () => $this->canReview())
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $this->applyReview($records->modelKeys(), false)),
                    BulkAction::make('downloadSelected')->label('Download CSV (selected)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                        ->action(fn (Collection $records) => $this->downloadEntries($records->pluck('employee_id')->map(fn ($id) => (int) $id)->all())),
                ])->label('Selected'),
            ])
            ->recordActions([
                // HROne parity PO-4 (§25.6.5 #3): the optional reviewer tick; never blocks validation, approval or finalize.
                Action::make('check')->label(fn (PayrollEntry $record) => $record->reviewed_at === null ? 'Mark as checked' : 'Clear the check')
                    ->icon(fn (PayrollEntry $record) => $record->reviewed_at === null ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')->color('gray')
                    ->visible(fn () => $this->canReview())
                    ->action(fn (PayrollEntry $record) => $this->applyReview([$record->getKey()], $record->reviewed_at === null)),
                Action::make('detail')->label('Breakdown')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        Section::make('Summary')->columns(4)->schema([
                            TextEntry::make('days_in_period')->label('Days'),
                            TextEntry::make('paid_days')->label('Paid days'),
                            TextEntry::make('lop_days')->label('LOP days'),
                            TextEntry::make('net_pay')->label('Net pay')->numeric(2),
                        ]),
                        // HROne parity TX-4 (TIM-2 / D-TX2): both kinds of paid-day adjustment the entry used, read from its basis;
                        // shown only while attendance paid-day adjustments are in use or the entry recorded one.
                        Section::make('Paid-day adjustments')->columns(2)->schema([
                            TextEntry::make('lop_adjusted_in_payroll')->label('Loss of pay adjusted in payroll')
                                ->state(fn (PayrollEntry $record) => self::days((float) ($record->inputs['manual_lop'] ?? 0)) ?? 'None'),
                            TextEntry::make('paid_days_adjusted_in_attendance')->label('Paid days adjusted in attendance')
                                ->state(fn (PayrollEntry $record) => self::attendanceAdjustment($record->inputs['attendance_paid_day_adjustment'] ?? null) ?? 'None'),
                            TextEntry::make('paid_day_overlap')->hiddenLabel()->columnSpanFull()->color('warning')
                                ->state(fn (PayrollEntry $record) => collect($record->exceptions ?? [])->firstWhere('type', AttendancePaidDayBasis::EXCEPTION)['message'] ?? null)
                                ->visible(fn (PayrollEntry $record) => collect($record->exceptions ?? [])->contains('type', AttendancePaidDayBasis::EXCEPTION)),
                        ])->visible(fn (PayrollEntry $record) => isset($record->inputs['attendance_paid_day_adjustment'])
                            || (app(PaidDayAdjustmentReader::class)->inUse() && (float) ($record->inputs['manual_lop'] ?? 0) != 0.0)),
                        RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                            TextEntry::make('name'),
                            TextEntry::make('type')->badge()->color(fn (string $state) => match ($state) {
                                'earning', 'reimbursement' => 'success', 'deduction' => 'danger', default => 'info'
                            }),
                            TextEntry::make('amount')->numeric(2),
                            TextEntry::make('basis')->state(fn ($record) => collect($record->basis ?? [])->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? $v : json_encode($v)))->implode(' · '))->placeholder('—')->wrap(),
                        ]),
                        Section::make('Exceptions')->schema([
                            TextEntry::make('exceptions')->hiddenLabel()->state(fn (PayrollEntry $record) => collect($record->exceptions ?? [])->map(fn ($e) => $e['message'])->all())->listWithLineBreaks()->placeholder('None'),
                        ])->visible(fn (PayrollEntry $record) => ! empty($record->exceptions)),
                    ]),
            ]);
    }

    /** @return array{mode: string, account_id: ?int, account: ?string, hold: ?string}|null */
    private function paymentOf(PayrollEntry $entry): ?array
    {
        $this->paymentFacts ??= app(PayrollRunCockpit::class)->payment($this->getOwnerRecord());

        return $this->paymentFacts[(int) $entry->employee_id] ?? null;
    }

    private function whereEmployees(Builder $query, string $filter, mixed $value): Builder
    {
        if (! is_string($value) || $value === '') {
            return $query;
        }

        return $query->whereIn('payroll_entries.employee_id', app(PayrollRunCockpit::class)->employeesWhere($this->getOwnerRecord(), $filter, $value));
    }

    private function canReview(): bool
    {
        /** @var PayrollRun $run */
        $run = $this->getOwnerRecord();
        $user = auth()->user();

        return ! $run->isLocked() && $run->status !== 'draft' && $user !== null && ($user->hasPermission('payroll.calculate') || $user->hasPermission('payroll.approve'));
    }

    /** @param  list<int|string>  $entryIds */
    private function applyReview(array $entryIds, bool $checked): void
    {
        try {
            $result = app(PayrollRunCockpit::class)->review($this->getOwnerRecord(), array_map('intval', $entryIds), auth()->user(), $checked);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();

            return;
        }
        $title = ($checked ? 'Checked: ' : 'Check cleared: ').$result['done'].($result['unchanged'] > 0 ? ' (already so: '.$result['unchanged'].')' : '');
        $notification = Notification::make()->title($title)->{$result['refused'] === [] ? 'success' : 'warning'}();
        if ($result['refused'] !== []) {
            $notification->body(count($result['refused']).' not changed: '.collect($result['refused'])->unique()->implode(' '));
        }
        $notification->send();
    }

    /** @param  list<int>|null  $employeeIds  the selected employees; null = the rows the table's filters and search show */
    private function downloadEntries(?array $employeeIds = null): StreamedResponse
    {
        /** @var PayrollRun $run */
        $run = $this->getOwnerRecord();
        abort_unless(auth()->user()?->can('payroll.view') ?? false, 403);
        $selection = $employeeIds === null ? 'table' : 'selected';
        $employeeIds ??= $this->getFilteredSortedTableQuery()->pluck('payroll_entries.employee_id')->map(fn ($id) => (int) $id)->all();
        $cockpit = app(PayrollRunCockpit::class);
        $csv = $cockpit->csv($run, 'entries', auth()->user(), $employeeIds, $selection);

        return response()->streamDownload(fn () => print ($csv), $cockpit->fileName($run, 'entries'), ['Content-Type' => 'text/csv']);
    }

    private static function days(float $days): ?string
    {
        return round($days, 2) == 0.0 ? null : rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.').' day(s)';
    }

    /** "Oct 2026: 22 → 20 paid days (−2) · Reduce: Weekly offs: 25% · Reason" from the entry's basis. */
    private static function attendanceAdjustment(mixed $adjustment): ?string
    {
        if (! is_array($adjustment)) {
            return null;
        }
        $number = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $change = (float) ($adjustment['change'] ?? 0);

        return ($adjustment['label'] ?? '').': '.$number((float) ($adjustment['paid_days_before'] ?? 0)).' → '.$number((float) ($adjustment['paid_days_after'] ?? 0))
            .' paid days ('.($change > 0 ? '+' : '').$number($change).') · '.($adjustment['described'] ?? '').' · '.($adjustment['reason'] ?? '');
    }
}

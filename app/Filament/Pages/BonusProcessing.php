<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\BonusRun;
use App\Domain\Payroll\Models\BonusRunLine;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\BonusRuns;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity PO-22 (matrix 25 §25.9.3 #1–#4, #7; HROne Payroll → "Process bonus", Bonus dashboard): the statutory bonus
 * run of a company for an accounting year (`?company=`, `?year=2025-26`), with HROne's two tabs (`?tab=`):
 *
 * - Non processed — every employee with finalized payroll in the year: pay group, months, bonus days, eligibility and
 *   why, wages (basic + DA), pay rate (with HR's override), wages for bonus, statutory bonus, bonus already paid with
 *   salary, bonus still due, ex gratia; "Pay rate", "Ex gratia", "Include" / "Leave out"; "Process bonus" sends lines for
 *   approval with the payroll month that pays them; a second person approves them or sends them back.
 * - Processed — approved lines: from / to month, pay rate, bonus days, bonus and ex gratia amounts, pay month, paid in
 *   which payroll; "Unprocess" for those not yet paid.
 *
 * Nothing is calculated, processed or approved unless a VERIFIED BONUS rule version applies to every month of the year:
 * until then the year shows "Awaiting verification of the BONUS rule" (BonusRuns::ruleState). Opens for payroll.view
 * holders who calculate (prepare, change, process, unprocess) or approve payroll.
 */
class BonusProcessing extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Process bonus';

    protected static ?string $title = 'Process bonus';

    protected static ?string $slug = 'process-bonus';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.bonus-processing';

    public const TABS = ['non_processed' => 'Non processed bonus', 'processed' => 'Processed bonus'];

    #[Url(as: 'tab')]
    public string $bonusTab = 'non_processed';

    #[Url]
    public ?string $year = null;

    #[Url]
    public ?string $company = null;

    /** @var array<string, mixed>|null per-request memo of the rule state */
    private ?array $state = null;

    private ?BonusRun $run = null;

    private bool $runLoaded = false;

    /** @var array<string, string>|null salary structure names by code (memo) */
    private ?array $structureNames = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermission('payroll.view') && ($user->hasPermission('payroll.calculate') || $user->hasPermission('payroll.approve'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->year === null || ! array_key_exists($this->year, $this->yearOptions())) {
            $this->year = BonusRuns::defaultYear();
        }
        $companies = $this->companyOptions();
        if ($this->company === null || ! array_key_exists($this->company, $companies)) {
            $this->company = array_key_first($companies);
        }
    }

    public function getSubheading(): ?string
    {
        return 'Statutory bonus for an accounting year from finalized payroll, under the verified BONUS rule: who is eligible, the wages for bonus, the bonus at your percentage and any ex gratia — sent for approval and paid with a payroll month.';
    }

    public function updatedYear(): void
    {
        $this->forgetMemo();
        $this->resetTable();
    }

    public function updatedCompany(): void
    {
        $this->forgetMemo();
        $this->resetTable();
    }

    /** @return array<string, string> */
    public function yearOptions(): array
    {
        $options = BonusRuns::yearOptions();
        // A year already prepared stays reachable.
        foreach (BonusRun::query()->orderByDesc('financial_year')->pluck('financial_year') as $fy) {
            $options[$fy] ??= 'FY '.$fy;
        }
        krsort($options);

        return $options;
    }

    /** @return array<string, string> */
    public function companyOptions(): array
    {
        return Company::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])->all();
    }

    public function tab(): string
    {
        return array_key_exists($this->bonusTab, self::TABS) ? $this->bonusTab : 'non_processed';
    }

    /** Each tab is its own address (`?tab=`), so its table and filters are built afresh. */
    public function tabUrl(string $tab): string
    {
        return static::getUrl(array_filter(['tab' => $tab === 'non_processed' ? null : $tab, 'year' => $this->year, 'company' => $this->company]));
    }

    /** @return array{ready: bool, rules: array<string, ComplianceRule>, rule: ?ComplianceRule, problems: list<string>} */
    public function ruleState(): array
    {
        return $this->state ??= app(BonusRuns::class)->ruleState((string) $this->year);
    }

    public function run(): ?BonusRun
    {
        if (! $this->runLoaded) {
            $this->run = $this->company === null ? null : app(BonusRuns::class)->find((int) $this->company, (string) $this->year);
            $this->runLoaded = true;
        }

        return $this->run;
    }

    /**
     * What the year looks like: the rule (or why it waits), the employer's rate, the payment deadline and the run's counts.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $state = $this->ruleState();
        $rule = $state['rule'];
        $run = $this->run();
        $lines = $run === null ? collect() : BonusRunLine::query()->where('bonus_run_id', $run->id)->get(['id', 'status', 'included', 'bonus_amount', 'ex_gratia', 'eligibility']);
        $rate = null;
        if ($state['ready']) {
            try {
                [$value, $source] = app(BonusRuns::class)->rate($state['rules']);
                $rate = BonusRuns::percent($value).($source === 'rule_minimum' ? ' (the rule\'s minimum; set your percentage in Setup centre → Payroll)' : '');
            } catch (RuntimeException $e) {
                $rate = $e->getMessage();
            }
        }
        [, $end] = BonusRuns::year((string) $this->year);

        return [
            'ready' => $state['ready'],
            'problems' => $state['problems'],
            'rules' => collect($state['rules'])->map(fn (ComplianceRule $r) => $r->label())->unique()->values()->all(),
            'rate' => $rate,
            'range' => $rule ? BonusRuns::percent((float) $rule->param('minimum_rate')).' – '.BonusRuns::percent((float) $rule->param('maximum_rate')) : null,
            'minimum_amount' => $rule?->param('minimum_amount'),
            'minimum_days' => $rule?->param('minimum_days'),
            'eligibility_ceiling' => $rule?->param('eligibility_ceiling'),
            'calculation_ceiling' => $rule?->param('calculation_ceiling'),
            'deadline' => $run?->payment_deadline?->format('d M Y') ?? ($rule && is_numeric($rule->param('payment_within_months')) ? $end->copy()->startOfDay()->addMonthsNoOverflow((int) $rule->param('payment_within_months'))->format('d M Y') : null),
            'open_year' => $end->isFuture(),
            'run' => $run === null ? null : ['prepared' => $run->prepared_at?->format('d M Y H:i'), 'by' => $run->preparer?->name, 'recalculated' => $run->recalculated_at?->format('d M Y H:i'), 'rate' => $run->percentLabel()],
            'counts' => [
                'employees' => $lines->count(),
                'included' => $lines->where('included', true)->count(),
                'not_processed' => $lines->where('status', 'pending')->count(),
                'waiting' => $lines->where('status', 'submitted')->count(),
                'processed' => $lines->where('status', 'processed')->count(),
                'paid' => $lines->where('status', 'paid')->count(),
            ],
            'totals' => ['bonus' => round((float) $lines->sum('bonus_amount'), 2), 'ex_gratia' => round((float) $lines->sum('ex_gratia'), 2)],
        ];
    }

    /**
     * The bonus line's detail (HROne's bonus detail: percentage, tax treatment, pay days, pay month, total paid, minimum
     * wages …), as label => value.
     *
     * @return array<string, string>
     */
    public function detailRows(BonusRunLine $line): array
    {
        $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
        $basis = (array) $line->basis;
        $component = SalaryComponent::query()->where('code', 'BONUS')->first();

        return [
            'Employee' => trim(($line->employee?->employee_code ?? '').' '.($line->employee?->person?->full_name ?? '')),
            'Pay group' => $this->payGroupName($line->pay_group) ?? '—',
            'Eligibility' => (BonusRunLine::ELIGIBILITY[$line->eligibility] ?? $line->eligibility).' — '.$line->reason,
            'BONUS rule' => (string) ($basis['rule'] ?? '—'),
            'Bonus percentage' => BonusRuns::percent((float) $line->bonus_rate),
            'Tax treatment' => ($component?->taxable ?? true) ? 'Taxable (pay component Bonus)' : 'Not taxable (pay component Bonus)',
            'Months' => ($line->from_month ?? '—').' to '.($line->to_month ?? '—').' ('.$line->months.')',
            'Bonus pay days' => number_format((float) $line->bonus_days, 2),
            'Wages earned (basic + DA)' => $money($line->wages),
            'Pay rate' => $line->pay_rate === null ? '—' : $money($line->pay_rate).($line->pay_rate_override !== null ? ' (changed: '.$line->override_reason.')' : ' (calculation ceiling)'),
            'Minimum wages' => 'Not held by PeopleOS (no verified minimum wage): change the pay rate when it is higher than the calculation ceiling',
            'Wages for bonus' => $money($line->bonus_wages),
            'Minimum bonus' => $money($basis['minimum'] ?? null),
            'Maximum bonus' => $money($basis['maximum'] ?? null),
            'Statutory bonus' => $money($line->statutory_bonus),
            'Already paid with salary' => $money($line->already_paid),
            'Bonus to pay' => $money($line->bonus_amount).($line->included ? '' : ' (left out'.($line->include_reason ? ': '.$line->include_reason : '').')'),
            'Ex gratia' => $money($line->ex_gratia).($line->ex_gratia_reason ? ' ('.$line->ex_gratia_reason.')' : ''),
            'Total' => $money($line->total()),
            'Bonus pay month' => $line->pay_month ? Carbon::parse($line->pay_month.'-01')->format('M Y') : '—',
            'Status' => BonusRunLine::STATUSES[$line->status] ?? $line->status,
            'Total paid' => $line->status === 'paid' ? $money((float) $line->paid_bonus + (float) $line->paid_ex_gratia).' in payroll '.($line->paidRun?->period?->label() ?? '') : '—',
        ];
    }

    public function table(Table $table): Table
    {
        $processed = $this->tab() === 'processed';
        $money = fn (string $name, string $label) => TextColumn::make($name)->label($label)->numeric(2)->alignEnd();

        $columns = [
            TextColumn::make('employee.employee_code')->label('Employee code')->sortable(),
            TextColumn::make('employee.person.display_name')->label('Employee name')->searchable(query: EmployeeFilters::search('employee')),
            TextColumn::make('pay_group')->label('Pay group')->placeholder('—')->toggleable()->formatStateUsing(fn (?string $state) => $this->payGroupName($state)),
        ];
        if ($processed) {
            $columns = [...$columns,
                TextColumn::make('from_month')->label('From month')->formatStateUsing(fn (?string $state) => $state ? Carbon::parse($state.'-01')->format('M Y') : '—'),
                TextColumn::make('to_month')->label('To month')->formatStateUsing(fn (?string $state) => $state ? Carbon::parse($state.'-01')->format('M Y') : '—'),
                $money('pay_rate', 'Pay rate'),
                TextColumn::make('bonus_days')->label('Bonus days')->numeric(2),
                $money('bonus_amount', 'Bonus amount'),
                $money('ex_gratia', 'Ex gratia amount'),
                TextColumn::make('pay_month')->label('Pay month')->formatStateUsing(fn (?string $state) => $state ? Carbon::parse($state.'-01')->format('M Y') : '—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => BonusRunLine::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'paid' ? 'success' : 'info')
                    ->description(fn (BonusRunLine $r) => $r->status === 'paid' ? 'Paid with salary, payroll '.($r->paidRun?->period?->label() ?? '') : 'Paid by the payroll of its month'),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ];
        } else {
            $columns = [...$columns,
                TextColumn::make('months')->label('Months')->description(fn (BonusRunLine $r) => $r->from_month ? Carbon::parse($r->from_month.'-01')->format('M Y').' – '.Carbon::parse($r->to_month.'-01')->format('M Y') : null),
                TextColumn::make('bonus_days')->label('Bonus days')->numeric(2),
                TextColumn::make('eligibility')->badge()->formatStateUsing(fn (string $state) => BonusRunLine::ELIGIBILITY[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'eligible' => 'success', 'review' => 'warning', default => 'gray',
                    })
                    ->tooltip(fn (BonusRunLine $r) => $r->reason),
                $money('wages', 'Wages (basic + DA)')->toggleable(),
                $money('pay_rate', 'Pay rate')->placeholder('—')->description(fn (BonusRunLine $r) => $r->pay_rate_override !== null ? 'Changed' : null),
                $money('bonus_wages', 'Wages for bonus')->placeholder('—'),
                $money('statutory_bonus', 'Statutory bonus'),
                $money('already_paid', 'Paid with salary')->toggleable(),
                $money('bonus_amount', 'Bonus to pay')->description(fn (BonusRunLine $r) => $r->included || ! in_array($r->eligibility, ['eligible', 'review'], true) ? null : 'Left out'),
                $money('ex_gratia', 'Ex gratia'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => BonusRunLine::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'submitted' ? 'warning' : 'gray')
                    ->description(fn (BonusRunLine $r) => match (true) {
                        $r->status === 'submitted' => 'With payroll '.Carbon::parse($r->pay_month.'-01')->format('M Y'),
                        $r->return_note !== null => 'Sent back: '.$r->return_note,
                        default => null,
                    }),
                IconColumn::make('included')->label('Included')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ];
        }
        [, $end] = BonusRuns::year((string) $this->year);

        return $table
            ->query(fn (): Builder => BonusRunLine::query()->with(['employee.person', 'paidRun.period', 'approver'])
                ->where('bonus_run_id', $this->run()?->id ?? 0)
                ->whereIn('status', $processed ? BonusRunLine::PROCESSED : BonusRunLine::NOT_PROCESSED))
            ->columns($columns)
            ->filters([
                ...($processed
                    ? [SelectFilter::make('status')->options(array_intersect_key(BonusRunLine::STATUSES, array_flip(BonusRunLine::PROCESSED)))]
                    : [SelectFilter::make('eligibility')->options(BonusRunLine::ELIGIBILITY), SelectFilter::make('status')->options(array_intersect_key(BonusRunLine::STATUSES, array_flip(BonusRunLine::NOT_PROCESSED)))]),
                PayrollRegisters::structure('payroll.view', fn () => $end->lt(today()) ? $end : today()),
                EmployeeFilters::codes('employee'),
                EmployeeFilters::organisation('employee'),
            ])
            ->defaultSort('id')
            ->emptyStateHeading($processed ? 'No bonus processed yet' : ($this->run() === null ? 'Bonus not prepared for this year' : 'Every bonus of the year is processed'))
            ->emptyStateDescription($this->ruleState()['ready']
                ? ($processed ? 'Approved bonus appears here until it is paid, and after.' : 'Prepare bonus to list every employee with finalized payroll in the year.')
                : 'Awaiting verification of the BONUS rule: bonus is not calculated for this year.')
            ->recordActions($processed ? [$this->detailAction(), $this->unprocessAction()] : [$this->detailAction(), $this->payRateAction(), $this->exGratiaAction(), $this->includeAction(true), $this->includeAction(false)])
            ->toolbarActions($processed ? [$this->unprocessBulk()] : [$this->processBulk(), $this->approveBulk(), $this->sendBackBulk()]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->prepareBonusAction(), $this->processBonusAction(), $this->approveBonusAction(), $this->sendBackBonusAction()];
    }

    // ---- header actions ------------------------------------------------------------------------------------------

    /** "Prepare bonus" (recalculate once prepared): opens from `?action=prepareBonus`. */
    public function prepareBonusAction(): Action
    {
        return Action::make('prepareBonus')->label(fn () => $this->run() === null ? 'Prepare bonus' : 'Recalculate')->icon(Heroicon::OutlinedCalculator)
            ->visible(fn () => auth()->user()?->hasPermission('payroll.calculate'))
            ->fillForm(fn () => ['company' => $this->company, 'year' => $this->year])
            ->schema([
                Select::make('company')->options(fn () => $this->companyOptions())->required(),
                Select::make('year')->label('Accounting year')->options(fn () => $this->yearOptions())->required(),
            ])
            ->modalDescription('Every employee with finalized payroll in the year, measured under the verified BONUS rule version of each month. Bonus already sent for approval, processed or paid is left as it is; your pay-rate, ex gratia and include decisions are kept.')
            ->action(function (array $data) {
                $company = Company::query()->find($data['company']);
                if ($company === null) {
                    Notification::make()->danger()->title('Choose a company')->send();

                    return;
                }
                $this->attempt(function () use ($company, $data) {
                    $run = app(BonusRuns::class)->prepare($company, (string) $data['year'], auth()->user());
                    $this->company = (string) $company->id;
                    $this->year = $run->financial_year;
                    $this->forgetMemo();

                    return 'Bonus prepared for FY '.$run->financial_year;
                });
            });
    }

    /** "Process bonus": every included line with something to pay, sent for approval with a payroll month (`?action=processBonus`). */
    public function processBonusAction(): Action
    {
        return Action::make('processBonus')->label('Process bonus')->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => auth()->user()?->hasPermission('payroll.calculate') && $this->run() !== null)
            ->schema(fn () => $this->payMonthSchema())
            ->modalDescription(fn () => 'Sends every bonus not yet processed that has something to pay ('.number_format($this->toProcessTotal(), 2).') for approval. A second person approves it; it is then paid with the chosen payroll month.')
            ->action(fn (array $data) => $this->submit(null, (string) $data['pay_month']));
    }

    public function approveBonusAction(): Action
    {
        return Action::make('approveBonus')->label('Approve bonus')->icon(Heroicon::OutlinedCheckBadge)->color('success')
            ->visible(fn () => auth()->user()?->hasPermission('payroll.approve') && $this->run() !== null && BonusRunLine::query()->where('bonus_run_id', $this->run()->id)->where('status', 'submitted')->exists())
            ->schema([Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500)])
            ->modalDescription(fn () => $this->waitingDescription())
            ->action(fn (array $data) => $this->attempt(function () use ($data) {
                $result = app(BonusRuns::class)->approve($this->run(), null, auth()->user(), $data['note'] ?? null);

                return $this->summarise("{$result['approved']} bonus line(s) approved", $result['skipped']);
            }));
    }

    public function sendBackBonusAction(): Action
    {
        return Action::make('sendBackBonus')->label('Send back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
            ->visible(fn () => auth()->user()?->hasPermission('payroll.approve') && $this->run() !== null && BonusRunLine::query()->where('bonus_run_id', $this->run()->id)->where('status', 'submitted')->exists())
            ->schema([Textarea::make('note')->label('Why')->required()->rows(2)->maxLength(500)])
            ->modalDescription(fn () => $this->waitingDescription())
            ->action(fn (array $data) => $this->attempt(fn () => app(BonusRuns::class)->sendBack($this->run(), null, auth()->user(), (string) $data['note']).' bonus line(s) sent back'));
    }

    // ---- record actions ------------------------------------------------------------------------------------------

    private function detailAction(): Action
    {
        return Action::make('detail')->label('Detail')->icon(Heroicon::OutlinedEye)->color('gray')
            ->modalHeading('Bonus detail')
            ->modalContent(fn (BonusRunLine $record) => view('filament.pages.bonus-processing-detail', ['rows' => $this->detailRows($record)]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    private function payRateAction(): Action
    {
        return Action::make('payRate')->label('Pay rate')->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (BonusRunLine $record) => $this->canChange($record) && in_array($record->eligibility, ['eligible', 'review'], true))
            ->fillForm(fn (BonusRunLine $record) => ['pay_rate' => $record->pay_rate_override !== null ? (float) $record->pay_rate_override : null])
            ->schema(fn (BonusRunLine $record) => [
                TextInput::make('pay_rate')->label('Pay rate (a month)')->numeric()->minValue(0)
                    ->helperText('The monthly wage the bonus is calculated as if: the applicable minimum wage when it is higher than the calculation ceiling of '.number_format((float) $record->pay_rate, 2).'. Blank: the ceiling.'),
                Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500),
            ])
            ->action(fn (BonusRunLine $record, array $data) => $this->attempt(function () use ($record, $data) {
                app(BonusRuns::class)->overridePayRate($record, filled($data['pay_rate'] ?? null) ? (float) $data['pay_rate'] : null, (string) $data['reason'], auth()->user());

                return 'Pay rate changed';
            }));
    }

    private function exGratiaAction(): Action
    {
        return Action::make('exGratia')->label('Ex gratia')->icon(Heroicon::OutlinedGift)
            ->visible(fn (BonusRunLine $record) => $this->canChange($record))
            ->fillForm(fn (BonusRunLine $record) => ['amount' => (float) $record->ex_gratia, 'reason' => $record->ex_gratia_reason])
            ->schema([
                TextInput::make('amount')->label('Ex gratia amount')->numeric()->minValue(0)->required()->helperText('Paid with the bonus as its own earning, never counted as statutory bonus. 0 clears it.'),
                Textarea::make('reason')->label('Why (needed for an amount)')->rows(2)->maxLength(500),
            ])
            ->action(fn (BonusRunLine $record, array $data) => $this->attempt(function () use ($record, $data) {
                app(BonusRuns::class)->setExGratia($record, (float) $data['amount'], $data['reason'] ?? null, auth()->user());

                return 'Ex gratia saved';
            }));
    }

    private function includeAction(bool $include): Action
    {
        return Action::make($include ? 'include' : 'leaveOut')->label($include ? 'Include' : 'Leave out')->color($include ? 'success' : 'gray')
            ->icon($include ? Heroicon::OutlinedPlusCircle : Heroicon::OutlinedMinusCircle)
            ->visible(fn (BonusRunLine $record) => $this->canChange($record) && in_array($record->eligibility, ['eligible', 'review'], true) && $record->included !== $include)
            ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)])
            ->modalDescription($include ? 'Pay this employee\'s statutory bonus (for example after deciding eligibility for the months below the ceiling).' : 'Leave this employee\'s statutory bonus out of the run (for example a disqualification). Ex gratia stays possible.')
            ->action(fn (BonusRunLine $record, array $data) => $this->attempt(function () use ($record, $data, $include) {
                app(BonusRuns::class)->setIncluded($record, $include, (string) $data['reason'], auth()->user());

                return $include ? 'Bonus included' : 'Bonus left out';
            }));
    }

    private function unprocessAction(): Action
    {
        return Action::make('unprocess')->label('Unprocess')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->visible(fn (BonusRunLine $record) => auth()->user()?->hasPermission('payroll.calculate') && $record->status === 'processed' && $record->paid_payroll_run_id === null)
            ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)])
            ->modalDescription('Takes the bonus out of its payroll month and back to "Non processed". Paid bonus is never unprocessed.')
            ->action(fn (BonusRunLine $record, array $data) => $this->unprocess([$record->id], (string) $data['reason']));
    }

    // ---- bulk actions --------------------------------------------------------------------------------------------

    private function processBulk(): BulkAction
    {
        return BulkAction::make('processSelected')->label('Process selected')->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => auth()->user()?->hasPermission('payroll.calculate') && $this->run() !== null)
            ->schema(fn () => $this->payMonthSchema())
            ->action(fn (Collection $records, array $data) => $this->submit($records->modelKeys(), (string) $data['pay_month']))
            ->deselectRecordsAfterCompletion();
    }

    private function approveBulk(): BulkAction
    {
        return BulkAction::make('approveSelected')->label('Approve selected')->icon(Heroicon::OutlinedCheckBadge)->color('success')
            ->visible(fn () => auth()->user()?->hasPermission('payroll.approve') && $this->run() !== null)
            ->schema([Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500)])
            ->action(fn (Collection $records, array $data) => $this->attempt(function () use ($records, $data) {
                $result = app(BonusRuns::class)->approve($this->run(), $records->modelKeys(), auth()->user(), $data['note'] ?? null);

                return $this->summarise("{$result['approved']} bonus line(s) approved", $result['skipped']);
            }))
            ->deselectRecordsAfterCompletion();
    }

    private function sendBackBulk(): BulkAction
    {
        return BulkAction::make('sendBackSelected')->label('Send back selected')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
            ->visible(fn () => auth()->user()?->hasPermission('payroll.approve') && $this->run() !== null)
            ->schema([Textarea::make('note')->label('Why')->required()->rows(2)->maxLength(500)])
            ->action(fn (Collection $records, array $data) => $this->attempt(fn () => app(BonusRuns::class)->sendBack($this->run(), $records->modelKeys(), auth()->user(), (string) $data['note']).' bonus line(s) sent back'))
            ->deselectRecordsAfterCompletion();
    }

    private function unprocessBulk(): BulkAction
    {
        return BulkAction::make('unprocessSelected')->label('Unprocess selected')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->visible(fn () => auth()->user()?->hasPermission('payroll.calculate') && $this->run() !== null)
            ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)])
            ->action(fn (Collection $records, array $data) => $this->unprocess($records->modelKeys(), (string) $data['reason']))
            ->deselectRecordsAfterCompletion();
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    /** @return list<Field> */
    private function payMonthSchema(): array
    {
        $deadline = $this->run()?->payment_deadline;

        return [
            Select::make('pay_month')->label('Pay with the payroll of')->options(PayrollRegisters::monthOptions(1, 12))->default(today()->format('Y-m'))->required()
                ->helperText($deadline ? 'The BONUS rule asks for payment by '.$deadline->format('d M Y').'.' : null),
        ];
    }

    /** @param  list<int>|null  $ids */
    private function submit(?array $ids, string $payMonth): void
    {
        $this->attempt(function () use ($ids, $payMonth) {
            $result = app(BonusRuns::class)->submit($this->run(), $ids, $payMonth, auth()->user());

            return $this->summarise("{$result['submitted']} bonus line(s) sent for approval".($result['late'] ? ' — after the payment deadline of the BONUS rule' : ''), $result['skipped']);
        });
    }

    /** @param  list<int>  $ids */
    private function unprocess(array $ids, string $reason): void
    {
        $this->attempt(function () use ($ids, $reason) {
            $result = app(BonusRuns::class)->unprocess($this->run(), $ids, $reason, auth()->user());
            $title = "{$result['unprocessed']} bonus line(s) unprocessed";
            if ($result['recalculate'] !== []) {
                $title .= '. Recalculate '.implode(', ', $result['recalculate']).': it still carries the bonus.';
            }

            return $this->summarise($title, $result['skipped']);
        });
    }

    private function canChange(BonusRunLine $record): bool
    {
        return $record->status === 'pending' && (bool) auth()->user()?->hasPermission('payroll.calculate') && $this->ruleState()['ready'];
    }

    private function toProcessTotal(): float
    {
        $run = $this->run();

        return $run === null ? 0.0 : round((float) BonusRunLine::query()->where('bonus_run_id', $run->id)->where('status', 'pending')->get(['bonus_amount', 'ex_gratia'])->sum(fn (BonusRunLine $l) => $l->total()), 2);
    }

    private function waitingDescription(): string
    {
        $waiting = BonusRunLine::query()->where('bonus_run_id', $this->run()?->id ?? 0)->where('status', 'submitted')->get(['bonus_amount', 'ex_gratia']);

        return $waiting->count().' bonus line(s) waiting for approval, '.number_format((float) $waiting->sum(fn (BonusRunLine $l) => $l->total()), 2).' in all. You cannot approve bonus you prepared or sent, or your own.';
    }

    /** @param  list<string>  $skipped */
    private function summarise(string $title, array $skipped): string
    {
        return $skipped === [] ? $title : $title.'. Not included: '.implode(' ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? ' (and '.(count($skipped) - 5).' more)' : '');
    }

    /** Run a service call; a refusal becomes a danger notification with its reason. */
    private function attempt(callable $call): void
    {
        try {
            $title = $call();
            Notification::make()->success()->title((string) $title)->send();
        } catch (RuntimeException|AuthorizationException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
        }
        $this->forgetMemo();
    }

    /** The salary structure's name for a pay group code (the line keeps the code, read through CompensationOutput). */
    public function payGroupName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $this->structureNames ??= SalaryStructure::query()->pluck('name', 'code')->map(fn ($n) => (string) $n)->all();

        return $this->structureNames[$code] ?? $code;
    }

    private function forgetMemo(): void
    {
        $this->state = null;
        $this->run = null;
        $this->runLoaded = false;
    }
}

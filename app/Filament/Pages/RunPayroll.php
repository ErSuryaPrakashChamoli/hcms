<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Attendance\Services\AttendanceMonthDesk;
use App\Domain\Attendance\Services\AttendanceMonthPopulation;
use App\Domain\Attendance\Services\AttendanceMonthRuns;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRunChecklist;
use App\Domain\Payroll\Services\PayrollRunReview;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\PayrollActions;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * Streamline S2 (owner 2026-11-05; HROne's "Process salary"): one guided run of a company's pay month, step by step —
 * pay month · attendance · pre-payroll checks · run payroll · review and approve · close payroll · publish payslips ·
 * pay. Attendance comes first and every check is ticked before the run starts (ST-3, ST-4, enforced again by the run's
 * Calculate action wherever it is pressed); the month closes, then payslips are published (ST-2); the latest closed
 * month can be reopened (ST-1). Every action on the run is the run page's own (PayrollActions::forRun), bound to this
 * month's run, so the rules and messages are the same on both screens.
 */
class RunPayroll extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Run payroll';

    protected static ?string $title = 'Run payroll';

    protected static ?string $slug = 'run-payroll';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.run-payroll';

    /** step key => [label, what happens in it] */
    public const STEPS = [
        'month' => ['Pay month', 'Choose the company and the pay month to run.'],
        'attendance' => ['Attendance', 'The month\'s attendance comes first: every day processed, and the month closed where attendance month close is in use.'],
        'checks' => ['Pre-payroll checks', 'Look over what changed this month and tick it before the run.'],
        'calculate' => ['Run payroll', 'Calculate every employee of the month and look at the result.'],
        'approve' => ['Review and approve', 'Validate the result; someone other than the person who ran it approves it.'],
        'close' => ['Close payroll', 'Lock the month: attendance and the month\'s inputs are settled and the payslips prepared.'],
        'publish' => ['Publish payslips', 'Employees see their payslips once they are published. The month can still be reopened to change it.'],
        'pay' => ['Pay', 'Download the bank file and the journal voucher, then mark the month as paid.'],
    ];

    #[Url]
    public ?string $company = null;

    #[Url]
    public ?string $month = null;

    #[Url]
    public ?string $step = null;

    /** Set by wire:init: the heavy reads (attendance, the checklist) wait for the page to load. */
    public bool $ready = false;

    private ?PayrollRun $runMemo = null;

    private bool $runRead = false;

    private ?array $attendanceMemo = null;

    private ?array $itemsMemo = null;

    private ?array $reviewMemo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermission('payroll.view')
            && ($user->hasPermission('payroll.calculate') || $user->hasPermission('payroll.approve') || $user->hasPermission('payroll.finalize'));
    }

    public function getSubheading(): ?string
    {
        return 'One pay month at a time: attendance, the pre-payroll checks, calculating, approval, closing, payslips and payment.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companies();
        if (! array_key_exists((int) $this->company, $companies)) {
            $this->company = $companies === [] ? null : (string) array_key_first($companies);
        }
        if ($this->companyId() !== null && ! array_key_exists((string) $this->month, $this->monthOptions())) {
            $this->month = $this->defaultMonth();
        }
    }

    public function load(): void
    {
        $this->ready = true;
        if (! array_key_exists((string) $this->step, self::STEPS)) {
            $this->step = $this->firstOpenStep();
        }
    }

    /** @return array<int, string> */
    public function companies(): array
    {
        return Company::query()->orderBy('name')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /** @return array<string, string> Y-m => "Oct 2026 · 1 Oct – 31 Oct" */
    public function monthOptions(): array
    {
        return $this->companyId() === null ? [] : app(AttendanceMonthDesk::class)->months($this->companyId(), 12, 1);
    }

    public function companyId(): ?int
    {
        return filled($this->company) ? (int) $this->company : null;
    }

    /** @return array{0: int, 1: int} */
    public function period(): array
    {
        return [(int) substr((string) $this->month, 0, 4), (int) substr((string) $this->month, 5, 2)];
    }

    public function monthLabel(): string
    {
        [$year, $month] = $this->period();

        return $year > 0 && $month > 0 ? CarbonImmutable::create($year, $month, 1)->format('M Y') : '';
    }

    public function updatedCompany(): void
    {
        if (! array_key_exists((int) $this->company, $this->companies())) {
            $this->company = (string) array_key_first($this->companies());
        }
        $this->month = $this->defaultMonth();
        $this->afterChange();
    }

    public function updatedMonth(): void
    {
        if (! array_key_exists((string) $this->month, $this->monthOptions())) {
            $this->month = $this->defaultMonth();
        }
        $this->afterChange();
    }

    public function goTo(string $step): void
    {
        if (array_key_exists($step, self::STEPS)) {
            $this->step = $step;
        }
    }

    public function next(): void
    {
        $keys = array_keys(self::STEPS);
        $i = array_search($this->step, $keys, true);
        $this->step = $keys[min(count($keys) - 1, (int) $i + 1)];
    }

    public function previous(): void
    {
        $keys = array_keys(self::STEPS);
        $i = array_search($this->step, $keys, true);
        $this->step = $keys[max(0, (int) $i - 1)];
    }

    /** Tick a pre-payroll check as checked, as it stands now. */
    public function tick(string $key): void
    {
        abort_unless(auth()->user()?->can('payroll.calculate'), 403);
        try {
            [$year, $month] = $this->period();
            app(PayrollRunChecklist::class)->tick((int) $this->companyId(), $year, $month, $key, auth()->user());
            Notification::make()->success()->title(PayrollRunChecklist::ITEMS[$key][0].' checked')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Cannot tick this check')->body($e->getMessage())->persistent()->send();
        }
        $this->itemsMemo = null;
    }

    // --- What the steps show ------------------------------------------------------------------------------------------

    public function run(): ?PayrollRun
    {
        if (! $this->runRead) {
            $this->runRead = true;
            [$year, $month] = $this->period();
            $period = $this->companyId() === null ? null : PayrollPeriod::query()->where('company_id', $this->companyId())->where('year', $year)->where('month', $month)->first();
            $this->runMemo = $period?->runs()->with(['period', 'company'])->latest('id')->first();
        }

        return $this->runMemo;
    }

    /** @return array<string, mixed>|null */
    public function attendance(): ?array
    {
        if (! $this->ready || $this->companyId() === null) {
            return null;
        }
        [$year, $month] = $this->period();
        try {
            return $this->attendanceMemo ??= app(PayrollRunChecklist::class)->attendance($this->companyId(), $year, $month);
        } catch (RuntimeException $e) {
            return $this->attendanceMemo = ['error' => $e->getMessage()];
        }
    }

    /** @return list<array<string, mixed>>|null */
    public function items(): ?array
    {
        if (! $this->ready || $this->companyId() === null) {
            return null;
        }
        [$year, $month] = $this->period();

        return $this->itemsMemo ??= app(PayrollRunChecklist::class)->items($this->companyId(), $year, $month);
    }

    /** @return list<string> why the run cannot start yet (none once attendance is done and every check is ticked) */
    public function blockers(): array
    {
        if (! $this->ready || $this->companyId() === null) {
            return [];
        }
        [$year, $month] = $this->period();

        return app(PayrollRunChecklist::class)->blockers($this->companyId(), $year, $month, $this->items());
    }

    /**
     * Each step with its state: done, attention (something needs a look before the run), current or waiting.
     *
     * @return list<array{key: string, label: string, help: string, state: string}>
     */
    public function steps(): array
    {
        $states = $this->stepStates();

        return array_map(fn (string $key) => ['key' => $key, 'label' => self::STEPS[$key][0], 'help' => self::STEPS[$key][1], 'state' => $states[$key]], array_keys(self::STEPS));
    }

    public function attendanceRequired(): bool
    {
        return app(PayrollRunChecklist::class)->attendanceFirst();
    }

    public function checklistRequired(): bool
    {
        return app(PayrollRunChecklist::class)->checklistRequired();
    }

    public function runUrl(): ?string
    {
        return ($run = $this->run()) !== null && PayrollRunResource::canView($run) ? PayrollRunResource::getUrl('view', ['record' => $run]) : null;
    }

    public function attendanceUrl(): ?string
    {
        return RunAttendance::canAccess() ? RunAttendance::getUrl(['company' => $this->company, 'month' => $this->month]) : null;
    }

    /** Where each check is resolved: [button label, the screen's class]. Only screens the person may open are offered. */
    private const RESOLVE = [
        'new_joiners' => ['Open employees', 'App\\Filament\\Resources\\Employees\\EmployeeResource'],
        'exits' => ['Open exits', 'App\\Filament\\Resources\\ExitCases\\ExitCaseResource'],
        'org_changes' => ['Open employees', 'App\\Filament\\Resources\\Employees\\EmployeeResource'],
        'final_settlements' => ['Open exits', 'App\\Filament\\Resources\\ExitCases\\ExitCaseResource'],
        'uploads' => ['Open payroll uploads', 'App\\Filament\\Resources\\PayrollUploads\\PayrollUploadResource'],
        'one_time_inputs' => ['Open adjustments', 'App\\Filament\\Resources\\PayrollAdjustments\\PayrollAdjustmentResource'],
        'salary_revisions' => ['Open salary revisions', 'App\\Filament\\Resources\\CompensationChanges\\CompensationChangeResource'],
        'structure_missing' => ['Assign salary structures', 'App\\Filament\\Pages\\SalaryStructureRegister'],
        'pay_checks' => ['Open salary structures', 'App\\Filament\\Pages\\SalaryStructureRegister'],
        'leave' => ['Open leave requests', 'App\\Filament\\Resources\\LeaveRequests\\LeaveRequestResource'],
        'arrears' => ['Open attendance arrears', 'App\\Filament\\Resources\\AttendanceArrears\\AttendanceArrearResource'],
        'statutory' => ['Open statutory profiles', 'App\\Filament\\Resources\\EstablishmentStatutoryProfiles\\EstablishmentStatutoryProfileResource'],
        'tax_regime' => ['Open tax declarations', 'App\\Filament\\Resources\\TaxDeclarations\\TaxDeclarationResource'],
        'bank' => ['Open employees', 'App\\Filament\\Resources\\Employees\\EmployeeResource'],
        'loans' => ['Open loans', 'App\\Filament\\Resources\\Loans\\LoanResource'],
        'claims' => ['Open claims', 'App\\Filament\\Resources\\ReimbursementClaims\\ReimbursementClaimResource'],
        'holds' => ['Open salary holds', 'App\\Filament\\Resources\\SalaryHolds\\SalaryHoldResource'],
        'declarations' => ['Open tax declarations', 'App\\Filament\\Resources\\TaxDeclarations\\TaxDeclarationResource'],
        'encashments' => ['Open leave encashments', 'App\\Filament\\Resources\\LeaveEncashments\\LeaveEncashmentResource'],
        'last_month' => ['Open payroll runs', 'App\\Filament\\Resources\\PayrollRuns\\PayrollRunResource'],
    ];

    /** @return array{label: string, url: string}|null the screen where a check is resolved, when the person may open it */
    public function resolveLink(string $key): ?array
    {
        [$label, $class] = self::RESOLVE[$key] ?? [null, null];
        if ($class === null || ! class_exists($class) || ! (bool) rescue(fn () => $class::canAccess(), false, false)) {
            return null;
        }

        return ['label' => $label, 'url' => is_subclass_of($class, \Filament\Resources\Resource::class) ? $class::getUrl('index') : $class::getUrl()];
    }

    /** @return array<string, mixed>|null the calculated month's review (totals, change from last month, unusual changes) */
    public function review(): ?array
    {
        $run = $this->run();
        if (! $this->ready || $run === null || $run->calculated_at === null) {
            return null;
        }

        return $this->reviewMemo ??= app(PayrollRunReview::class)->summary($run);
    }

    // --- Actions ------------------------------------------------------------------------------------------------------

    public function startRunAction(): Action
    {
        return Action::make('startRun')->label(fn () => 'Start '.$this->monthLabel())->icon(Heroicon::OutlinedPlayCircle)
            ->authorize(fn () => auth()->user()->can('payroll.calculate'))
            ->visible(fn () => $this->run() === null)
            ->action(function () {
                try {
                    [$year, $month] = $this->period();
                    $run = app(PayrollRuns::class)->open(Company::query()->findOrFail($this->companyId()), $year, $month, auth()->user());
                    Notification::make()->success()->title('Payroll for '.$run->period->label().' started')->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot start the month')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public function processAttendanceAction(): Action
    {
        return Action::make('processAttendance')->label('Process attendance')->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->authorize(fn () => auth()->user()->hasPermission('attendance.process'))
            ->requiresConfirmation()->modalHeading(fn () => 'Process attendance · '.$this->monthLabel())
            ->modalDescription('Calculates every day of the month so far for everyone in it, in the background. You are told in the app and by e-mail when it is done. To pick people or groups, use Run attendance.')
            ->action(function () {
                try {
                    [$year, $month] = $this->period();
                    [$start, $end] = app(AttendanceMonthCloses::class)->window((int) $this->companyId(), $year, $month);
                    $employees = app(AttendanceMonthPopulation::class)->filtered((int) $this->companyId(), $start, $end, []);
                    $run = app(AttendanceMonthRuns::class)->start((int) $this->companyId(), $year, $month, $employees, auth()->user(), []);
                    Notification::make()->success()->title('Attendance is being processed')->body("{$run->total} employee(s) queued for {$run->label()}. You will be told when it is done.")->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot process attendance')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public function closeAttendanceAction(): Action
    {
        return Action::make('closeAttendance')->label('Close attendance month')->icon(Heroicon::OutlinedLockClosed)->color('success')
            ->authorize(fn () => auth()->user()->hasPermission('attendance.process'))
            ->visible(fn () => app(AttendanceMonthCloses::class)->enabled())
            ->requiresConfirmation()->modalHeading(fn () => 'Close attendance · '.$this->monthLabel())
            ->modalDescription('Requests, corrections, overrides and uploads on the month\'s days stop, and the days keep their values. Payroll then reads a closed month. It can be reopened from Run attendance until payroll closes.')
            ->schema([Textarea::make('note')->label('Note (optional)')->maxLength(255)])
            ->action(function (array $data) {
                try {
                    [$year, $month] = $this->period();
                    $row = app(AttendanceMonthCloses::class)->close((int) $this->companyId(), $year, $month, auth()->user(), $data['note'] ?? null);
                    Notification::make()->success()->title("{$row->label()} attendance closed")->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot close the month')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public function calculateAction(): Action
    {
        return $this->runAction('calculate');
    }

    public function calculateInBackgroundAction(): Action
    {
        return $this->runAction('calculateInBackground');
    }

    public function validateAction(): Action
    {
        return $this->runAction('validate');
    }

    public function approveAction(): Action
    {
        return $this->runAction('approve');
    }

    public function finalizeAction(): Action
    {
        return $this->runAction('finalize');
    }

    public function publishPayslipsAction(): Action
    {
        return $this->runAction('publishPayslips');
    }

    public function reopenMonthAction(): Action
    {
        return $this->runAction('reopenMonth');
    }

    public function reopenAction(): Action
    {
        return $this->runAction('reopen');
    }

    public function bankFileAction(): Action
    {
        return $this->runAction('bankFile');
    }

    public function journalVoucherAction(): Action
    {
        return $this->runAction('journalVoucher');
    }

    public function markPaidAction(): Action
    {
        return $this->runAction('markPaid');
    }

    public function paymentDateAction(): Action
    {
        return $this->runAction('paymentDate');
    }

    /** The run page's own action, bound to this month's run (it is only rendered while the month has a run). */
    private function runAction(string $name): Action
    {
        $action = collect(PayrollActions::forRun())->first(fn (Action $a) => $a->getName() === $name);

        return $action->record(fn () => $this->run())->after(fn () => $this->afterChange());
    }

    // --- Internals ----------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    private function stepStates(): array
    {
        $run = $this->run();
        $status = $run?->status;
        $reached = fn (array $statuses) => in_array($status, $statuses, true);
        $attendance = $this->attendance();
        $items = $this->items();
        $checksDone = $items !== null && collect($this->blockers())->reject(fn (string $r) => str_starts_with($r, 'Attendance first'))->isEmpty();
        $states = [
            'month' => $this->companyId() !== null ? 'done' : 'current',
            'attendance' => $attendance === null ? 'waiting' : (($attendance['done'] ?? false) || ! $this->attendanceRequired() ? 'done' : 'attention'),
            'checks' => $items === null ? 'waiting' : ($checksDone ? 'done' : 'attention'),
            'calculate' => $reached(['calculated', 'validated', 'approved', 'finalized', 'paid']) ? 'done' : 'waiting',
            'approve' => $reached(['approved', 'finalized', 'paid']) ? 'done' : 'waiting',
            'close' => $reached(['finalized', 'paid']) ? 'done' : 'waiting',
            'publish' => $run?->payslips_published_at !== null ? 'done' : 'waiting',
            'pay' => $reached(['paid']) ? 'done' : 'waiting',
        ];
        if (array_key_exists((string) $this->step, $states) && $states[$this->step] !== 'done') {
            $states[$this->step] = 'current';
        }

        return $states;
    }

    private function firstOpenStep(): string
    {
        foreach ($this->stepStates() as $key => $state) {
            if ($key !== 'month' && $state !== 'done') {
                return $key;
            }
        }

        return 'pay';
    }

    /** The month to show: the one being run, else the month after the latest closed one, else last month. */
    private function defaultMonth(): ?string
    {
        $companyId = $this->companyId();
        if ($companyId === null) {
            return null;
        }
        $options = $this->monthOptions();
        $open = PayrollRun::query()->where('company_id', $companyId)->whereIn('status', [...PayrollRun::EDITABLE, 'approved'])->with('period')->latest('id')->first();
        $closed = PayrollPeriod::query()->where('company_id', $companyId)->where('status', 'closed')->orderByDesc('year')->orderByDesc('month')->first();
        $candidates = array_filter([
            $open?->period ? sprintf('%04d-%02d', $open->period->year, $open->period->month) : null,
            $closed ? CarbonImmutable::create($closed->year, $closed->month, 1)->addMonthNoOverflow()->format('Y-m') : null,
            array_keys($options)[2] ?? null, // the options run from next month back: [0] next, [1] this, [2] last month
            array_key_first($options),
        ]);
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $options)) {
                return $candidate;
            }
        }

        return null;
    }

    private function afterChange(): void
    {
        $this->runRead = false;
        $this->runMemo = null;
        $this->attendanceMemo = null;
        $this->itemsMemo = null;
        $this->reviewMemo = null;
        if ($this->ready) {
            $this->step = $this->firstOpenStep();
        }
    }
}

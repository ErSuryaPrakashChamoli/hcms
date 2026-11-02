<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Services\AdvanceRecoveries;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\OwnRecords;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Support\AdvanceRecoveryActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * HROne parity XO-6 (matrix 26 §26.3.1 #8, §26.3.4 #7 — "View ledger", the Advance Outstanding ledger): every entry of the
 * employee's expense advances in date order — dispensed, settled against a report, refunded (with how), written off,
 * recovered — with the outstanding before and after each, read-only from the advance ledger. Opened from the Advance recovery
 * desk, Dispense advances ("Employee's outstanding") and the advance page. Shows the employee's recoveries scheduled through
 * payroll, which finance (`expense.pay`, in scope, never their own) may change or cancel until a payroll run carries the
 * advance, and offers the desk's two recoveries.
 *
 * Who may open it: the employee for their own advances; `expense.view` / `approve` / `pay` holders for an employee in their
 * access scope (the expense module's, CA-9).
 */
class AdvanceLedger extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $title = 'Advance ledger';

    protected static ?string $slug = 'advance-ledger';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.advance-ledger';

    #[Url]
    public ?string $employee = null;

    private ?Employee $resolved = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && collect(['expense.claim', 'expense.view', 'expense.approve', 'expense.pay'])->contains(fn (string $p) => $user->hasPermission($p));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->subject() === null, 404);
    }

    /** The employee whose ledger this is, when the signed-in person may read it (null otherwise). */
    public function subject(): ?Employee
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $user = auth()->user();
        $employee = is_numeric($this->employee) ? Employee::query()->with('person')->find((int) $this->employee) : null;
        if ($employee === null || $user === null) {
            return null;
        }
        $own = app(OwnRecords::class)->isOwn($user, $employee->id) && $user->hasPermission('expense.claim');
        $oversees = collect(['expense.view', 'expense.approve', 'expense.pay'])->contains(fn (string $p) => $user->hasPermission($p))
            && app(AccessScopes::class)->allowsEmployeeId($user, (int) $employee->id, 'expense');

        return $this->resolved = ($own || $oversees) ? $employee : null;
    }

    public function getTitle(): string
    {
        $employee = $this->subject();

        return 'Advance ledger'.($employee !== null ? ' · '.$employee->employee_code.' · '.($employee->person?->display_name ?? '') : '');
    }

    public function getSubheading(): ?string
    {
        return 'Every movement of the employee\'s expense advances in date order, with the outstanding balance before and after it.';
    }

    /** @return list<array<string, mixed>> */
    public function ledgerRows(): array
    {
        return $this->rows ??= app(AdvanceRecoveries::class)->ledger($this->subject());
    }

    /** @return array{outstanding: float, scheduled: float, recovered: float} */
    public function summary(): array
    {
        $employee = $this->subject();
        $outstanding = (float) ExpenseAdvance::query()->where('employee_id', $employee->id)->where('status', 'disbursed')->sum('outstanding');
        $scheduled = (float) $this->schedules()->where('status', 'scheduled')->sum(fn (ExpenseAdvanceRecoverySchedule $s) => (float) $s->amount);
        $recovered = (float) collect($this->ledgerRows())->filter(fn (array $r) => in_array($r['kind'], ['Recovered', 'Refunded'], true))->sum(fn (array $r) => -$r['change']);

        return ['outstanding' => round($outstanding, 2), 'scheduled' => round($scheduled, 2), 'recovered' => round($recovered, 2)];
    }

    /** @return Collection<int, ExpenseAdvanceRecoverySchedule> the employee's scheduled recoveries, open first */
    public function schedules(): Collection
    {
        return ExpenseAdvanceRecoverySchedule::query()->where('employee_id', $this->subject()->id)->with(['advance:id,number', 'scheduler:id,name'])
            ->orderByRaw("case when status = 'scheduled' then 0 else 1 end")->orderBy('recover_in')->orderBy('id')->get();
    }

    public function isMissed(ExpenseAdvanceRecoverySchedule $schedule): bool
    {
        return app(AdvanceRecoveries::class)->missed($schedule);
    }

    public function advanceUrl(int $advanceId): ?string
    {
        $advance = ExpenseAdvance::query()->find($advanceId);

        return $advance !== null && auth()->user()?->can('view', $advance) ? ExpenseAdvanceResource::getUrl('view', ['record' => $advance]) : null;
    }

    public function mayRecover(): bool
    {
        return AdvanceRecoveryActions::mayRecover($this->subject());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recoverPayroll')->label('Recover through payroll')->icon('heroicon-m-calendar-days')->color('gray')
                ->visible(fn () => $this->mayRecover() && app(AdvanceRecoveries::class)->unscheduledFor($this->subject()) > 0)
                ->steps(fn () => AdvanceRecoveryActions::payrollSteps($this->subject()))
                ->action(function (array $data) {
                    AdvanceRecoveryActions::runPayroll($this->subject(), $data);
                    $this->rows = null;
                }),
            Action::make('recoverOutside')->label('Record recovery outside payroll')->icon('heroicon-m-banknotes')
                ->visible(fn () => $this->mayRecover() && app(AdvanceRecoveries::class)->refundableFor($this->subject()) > 0)
                ->steps(fn () => AdvanceRecoveryActions::outsideSteps($this->subject()))
                ->action(function (array $data) {
                    AdvanceRecoveryActions::runOutside($this->subject(), $data);
                    $this->rows = null;
                }),
            Action::make('desk')->label('Advance recovery')->icon('heroicon-m-arrow-left')->color('gray')
                ->visible(fn () => AdvanceRecovery::canAccess())->url(fn () => AdvanceRecovery::getUrl()),
        ];
    }

    /** "Change" on an open scheduled recovery: another open month and, if wished, another amount. */
    public function rescheduleAction(): Action
    {
        return Action::make('reschedule')->label('Change')->icon('heroicon-m-arrow-path')->color('gray')->size('sm')
            ->modalHeading('Change the scheduled recovery')
            ->modalDescription('Not possible once a payroll run is recovering the advance: that payroll recovers it.')
            ->visible(fn (array $arguments) => $this->mayRecover() && $this->openSchedule($arguments) !== null)
            ->fillForm(fn (array $arguments) => ($s = $this->openSchedule($arguments)) !== null ? ['month' => $s->month(), 'amount' => (float) $s->amount] : [])
            ->schema(fn (array $arguments) => ($s = $this->openSchedule($arguments)) !== null ? AdvanceRecoveryActions::rescheduleSchema($s) : [])
            ->action(function (array $arguments, array $data) {
                if (($schedule = $this->openSchedule($arguments)) !== null) {
                    AdvanceRecoveryActions::runReschedule($schedule, $data);
                }
            });
    }

    /** "Cancel" on an open scheduled recovery: the advance goes back to its automatic recovery (if its policy has one). */
    public function cancelScheduleAction(): Action
    {
        return Action::make('cancelSchedule')->label('Cancel')->icon('heroicon-m-x-circle')->color('danger')->size('sm')
            ->modalHeading('Cancel the scheduled recovery')
            ->modalDescription('The advance goes back to its policy\'s automatic recovery, if it has one. Not possible once a payroll run is recovering it.')
            ->visible(fn (array $arguments) => $this->mayRecover() && $this->openSchedule($arguments) !== null)
            ->schema([Textarea::make('reason')->label('Why (optional)')->rows(2)->maxLength(500)])
            ->action(function (array $arguments, array $data) {
                if (($schedule = $this->openSchedule($arguments)) !== null) {
                    AdvanceRecoveryActions::runCancel($schedule, $data);
                }
            });
    }

    private function openSchedule(array $arguments): ?ExpenseAdvanceRecoverySchedule
    {
        $id = $arguments['schedule'] ?? null;

        return is_numeric($id) ? ExpenseAdvanceRecoverySchedule::query()->where('employee_id', $this->subject()?->id)->where('status', 'scheduled')->find((int) $id) : null;
    }
}

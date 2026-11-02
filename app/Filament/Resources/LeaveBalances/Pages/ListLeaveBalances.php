<?php

namespace App\Filament\Resources\LeaveBalances\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveMonthMovements;
use App\Domain\Leave\Services\LeaveYear;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListLeaveBalances extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = LeaveBalanceResource::class;

    public static function registerKey(): string
    {
        return 'leave_balances';
    }

    public static function registerReports(): array
    {
        return ['leave_balance', 'leave_balance_by_type', 'negative_leave_balance', 'high_leave_balance'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the leave_balances dataset */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'leave_type_id' => ['leave_type', fn (string $id) => (string) (LeaveType::query()->whereKey((int) $id)->value('name') ?? '')],
            'period_year' => ['year', null],
        ]);
    }

    /** HROne parity MS-12: the signed-in person's own employee record (the month view never shows anyone else's). */
    public function ownEmployee(): ?Employee
    {
        return auth()->check() ? app(CurrentEmployee::class)->of(auth()->user()) : null;
    }

    /** The leave year of the month view: the list's Year filter, else the current leave year. */
    public function monthViewYear(): int
    {
        $year = data_get($this->tableFilters, 'period_year.value');

        return is_numeric($year) ? (int) $year : app(LeaveYear::class)->periodFor(now());
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity TX-1: views, report and the balance upload for the HR side; an employee's own balances are unchanged.
            ...(TimeOfficeRegisters::hrSide('leave.view') ? [...$this->registerToolActions(), TimeOfficeRegisters::uploads(['leave_balances'])] : []),
            // HROne parity MS-12: the person's own leave year month by month (added / subtracted per leave type, from the ledger).
            Action::make('monthByMonth')->label('My month by month')->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->visible(fn () => $this->ownEmployee() !== null)
                ->modalHeading(fn () => 'My leave month by month · '.$this->monthViewYear())
                ->modalWidth(Width::FiveExtraLarge)
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.leave.month-view', [
                    'table' => ($me = $this->ownEmployee()) ? app(LeaveMonthMovements::class)->for($me, $this->monthViewYear()) : null,
                ])),
            Action::make('accrue')->label('Run accrual now')->icon(Heroicon::OutlinedArrowPath)
                ->authorize(fn () => auth()->user()->can('leave.manage'))
                ->requiresConfirmation()
                ->modalDescription('Posts every accrual due up to today for all employed employees. Safe to repeat.')
                ->action(function () {
                    $entries = 0;
                    Employee::query()->with('person')->employed()->each(function (Employee $e) use (&$entries) {
                        $entries += app(LeaveAccrual::class)->accrue($e);
                    });
                    Notification::make()->success()->title("{$entries} accrual entr".($entries === 1 ? 'y' : 'ies').' posted')->send();
                }),
        ];
    }
}

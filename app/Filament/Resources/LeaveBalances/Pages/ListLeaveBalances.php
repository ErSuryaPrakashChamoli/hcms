<?php

namespace App\Filament\Resources\LeaveBalances\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity TX-1: views, report and the balance upload for the HR side; an employee's own balances are unchanged.
            ...(TimeOfficeRegisters::hrSide('leave.view') ? [...$this->registerToolActions(), TimeOfficeRegisters::uploads(['leave_balances'])] : []),
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

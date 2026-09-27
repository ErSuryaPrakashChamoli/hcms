<?php

namespace App\Filament\Resources\LeaveBalances\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLeaveBalances extends ListRecords
{
    protected static string $resource = LeaveBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
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

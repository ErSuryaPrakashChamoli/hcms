<?php

namespace App\Filament\Resources\LeaveEncashments\Pages;

use App\Domain\Leave\Models\LeaveType;
use App\Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Support\LeaveEncashmentWizards;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ListLeaveEncashments extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = LeaveEncashmentResource::class;

    public static function registerKey(): string
    {
        return 'leave_encashments';
    }

    /** HROne parity TX-1: HROne's encashment requests and encashment cancellations registers as views. */
    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Waiting for a decision', 'filters' => ['status' => ['value' => 'pending']]],
            'approved' => ['label' => 'Approved, not yet paid out', 'filters' => ['status' => ['value' => 'approved']]],
            'cancellations' => ['label' => 'Encashment cancellations', 'filters' => ['status' => ['value' => 'cancelled']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['leave_encashments'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the leave_encashments dataset */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'status' => ['status', null],
            'leave_type_id' => ['leave_type', fn (string $id) => (string) (LeaveType::query()->whereKey((int) $id)->value('name') ?? '')],
        ], ['request_dates' => 'requested_on', 'decision_dates' => 'reviewed_on']);
    }

    /**
     * HROne parity PO-8: "Encash for an employee" (one employee, on their behalf) and "Encash leaves" (the HR batch screen,
     * opened on its wizard), next to the register tools.
     */
    protected function getHeaderActions(): array
    {
        return [
            LeaveEncashmentWizards::forEmployee(),
            Action::make('encashLeavesBatch')->label('Encash leaves')->icon(Heroicon::OutlinedBanknotes)->color('gray')
                ->visible(fn () => LeaveEncashmentBatchResource::canAccess() && (auth()->user()?->hasPermission('leave.manage') ?? false))
                ->url(fn () => LeaveEncashmentBatchResource::getUrl('index', ['action' => 'encashLeaves'])),
            ...$this->registerToolActions(),
        ];
    }
}

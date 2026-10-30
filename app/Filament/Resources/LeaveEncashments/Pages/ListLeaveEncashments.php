<?php

namespace App\Filament\Resources\LeaveEncashments\Pages;

use App\Domain\Leave\Models\LeaveType;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;

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

    protected function getHeaderActions(): array
    {
        return $this->registerToolActions();
    }
}

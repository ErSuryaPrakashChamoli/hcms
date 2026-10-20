<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

class ListLeaveRequests extends PeopleListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyOnBehalf')
                ->label('Apply on behalf')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn () => auth()->user()->can('leave.manage'))
                ->schema(fn () => [
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required()->live(),
                    ...LeaveActions::applyForm(fn () => Employee::query()->find($this->mountedActions[0]?->getFormData()['employee_id'] ?? 0) ?? new Employee),
                ])
                ->action(fn (array $data) => LeaveActions::apply(Employee::query()->findOrFail($data['employee_id']), $data)),
        ];
    }
}

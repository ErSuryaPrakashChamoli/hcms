<?php

namespace App\Filament\Resources\EmployeeContracts\Pages;

use App\Filament\Resources\EmployeeContracts\EmployeeContractResource;
use App\Filament\Support\Pages\PeopleEditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployeeContract extends PeopleEditRecord
{
    use SavesThroughEmployeeContracts;

    protected static string $resource = EmployeeContractResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['employee_id']);

        return $this->saveContract($record->employee, $data, $record);
    }
}

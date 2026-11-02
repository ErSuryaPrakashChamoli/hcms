<?php

namespace App\Filament\Resources\EmployeeContracts\Pages;

use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\EmployeeContracts\EmployeeContractResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployeeContract extends PeopleCreateRecord
{
    use SavesThroughEmployeeContracts;

    protected static string $resource = EmployeeContractResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveContract(Employee::query()->findOrFail($data['employee_id']), $data, null);
    }
}

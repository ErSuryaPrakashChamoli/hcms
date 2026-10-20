<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\SavesCustomFields;
use Filament\Actions\ViewAction;
use Illuminate\Database\Eloquent\Model;

class EditEmployee extends PeopleEditRecord
{
    use SavesCustomFields;

    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = AuditReasonField::extract($data);
        $this->extractCustomFields($data);

        $record->withAuditReason($reason)->update($data);
        $record->person->withAuditReason($reason);
        $this->persistCustomFields($record, $reason);

        return $record;
    }
}

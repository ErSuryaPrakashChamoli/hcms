<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\SavesCustomFields;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployee extends EditRecord
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

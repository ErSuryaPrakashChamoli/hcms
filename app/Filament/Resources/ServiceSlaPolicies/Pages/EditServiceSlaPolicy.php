<?php

namespace App\Filament\Resources\ServiceSlaPolicies\Pages;

use App\Filament\Resources\ServiceSlaPolicies\ServiceSlaPolicyResource;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditServiceSlaPolicy extends PeopleEditRecord
{
    protected static string $resource = ServiceSlaPolicyResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $record->update($data);
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }

        return $record;
    }
}

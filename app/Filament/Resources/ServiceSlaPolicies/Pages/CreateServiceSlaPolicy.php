<?php

namespace App\Filament\Resources\ServiceSlaPolicies\Pages;

use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Filament\Resources\ServiceSlaPolicies\ServiceSlaPolicyResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateServiceSlaPolicy extends PeopleCreateRecord
{
    protected static string $resource = ServiceSlaPolicyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return ServiceSlaPolicy::query()->create($data);
        } catch (RuntimeException $e) {
            // The model refuses unknown priorities or service hours without a day / with the end before the start.
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }
}

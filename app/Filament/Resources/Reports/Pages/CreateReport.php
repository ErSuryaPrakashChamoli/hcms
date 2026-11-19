<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateReport extends PeopleCreateRecord
{
    protected static string $resource = ReportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['owner_id' => auth()->id()];
    }
}

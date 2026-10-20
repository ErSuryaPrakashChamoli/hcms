<?php

namespace App\Filament\Resources\TalentPools\Pages;

use App\Filament\Resources\TalentPools\TalentPoolResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageTalentPools extends PeopleManageRecords
{
    protected static string $resource = TalentPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

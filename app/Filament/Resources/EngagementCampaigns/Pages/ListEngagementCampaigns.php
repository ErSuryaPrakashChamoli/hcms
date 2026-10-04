<?php

namespace App\Filament\Resources\EngagementCampaigns\Pages;

use App\Filament\Resources\EngagementCampaigns\EngagementCampaignResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListEngagementCampaigns extends PeopleListRecords
{
    protected static string $resource = EngagementCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

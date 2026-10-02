<?php

namespace App\Filament\Resources\EngagementCampaigns\Pages;

use App\Filament\Resources\EngagementCampaigns\EngagementCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEngagementCampaigns extends ListRecords
{
    protected static string $resource = EngagementCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

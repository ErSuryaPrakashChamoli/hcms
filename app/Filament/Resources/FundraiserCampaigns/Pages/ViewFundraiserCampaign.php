<?php

namespace App\Filament\Resources\FundraiserCampaigns\Pages;

use App\Filament\Resources\FundraiserCampaigns\FundraiserCampaignResource;
use App\Filament\Support\FundraiserActions;
use App\Filament\Support\Pages\PeopleViewRecord;

/** HROne parity GO-18: one fundraiser — collected against target, the pledges, and what may be done with it. */
class ViewFundraiserCampaign extends PeopleViewRecord
{
    protected static string $resource = FundraiserCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FundraiserActions::donate(),
            FundraiserActions::document(),
            FundraiserActions::edit(),
            FundraiserActions::open(),
            FundraiserActions::close(),
            FundraiserActions::downloadPledges(),
            FundraiserActions::cancel(),
        ];
    }
}

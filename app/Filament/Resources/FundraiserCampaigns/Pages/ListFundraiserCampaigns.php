<?php

namespace App\Filament\Resources\FundraiserCampaigns\Pages;

use App\Domain\Engagement\Services\Fundraisers;
use App\Filament\Resources\FundraiserCampaigns\FundraiserCampaignResource;
use App\Filament\Support\FundraiserActions;
use App\Filament\Support\Pages\PeopleListRecords;

/**
 * HROne parity GO-18: the fundraisers register (HR) and the fundraisers open to an employee. "Start a fundraiser" opens
 * directly with ?action=startFundraiser (the Actions launcher links it).
 */
class ListFundraiserCampaigns extends PeopleListRecords
{
    protected static string $resource = FundraiserCampaignResource::class;

    public function getSubheading(): ?string
    {
        $fundraisers = app(Fundraisers::class);
        $note = $fundraisers->manages(auth()->user())
            ? ($fundraisers->payrollAllowed() ? 'Pledges may be deducted from salary where a fundraiser collects through payroll (with consent per pledge).' : 'Pledges are paid outside payroll (Setup → Engage → Fundraisers).')
            : 'Pledge an amount you choose to a cause your organisation supports.';

        return trim($this->moduleContext()['sentence'].' '.$note);
    }

    protected function getHeaderActions(): array
    {
        return [FundraiserActions::start()];
    }
}

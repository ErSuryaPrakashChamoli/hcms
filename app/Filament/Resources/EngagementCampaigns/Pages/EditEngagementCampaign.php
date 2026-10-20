<?php

namespace App\Filament\Resources\EngagementCampaigns\Pages;

use App\Filament\Resources\EngagementCampaigns\EngagementCampaignResource;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditEngagementCampaign extends PeopleEditRecord
{
    protected static string $resource = EngagementCampaignResource::class;

    /** Only a draft campaign's details change; its lifecycle runs through the list actions. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record->status !== 'draft') {
            ServiceDeskActions::refuse('Only a draft campaign is edited.');

            throw new Halt;
        }
        $record->update(array_intersect_key($data, array_flip(['name', 'purpose', 'starts_on', 'ends_on', 'audience_id'])));

        return $record;
    }
}

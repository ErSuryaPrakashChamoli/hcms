<?php

namespace App\Filament\Resources\EngagementCampaigns\Pages;

use App\Domain\Engagement\Services\Campaigns;
use App\Filament\Resources\EngagementCampaigns\EngagementCampaignResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class CreateEngagementCampaign extends PeopleCreateRecord
{
    protected static string $resource = EngagementCampaignResource::class;

    /** One idempotency key per form: a double submit returns the same campaign. */
    public string $idempotencyKey = '';

    public function mount(): void
    {
        parent::mount();
        $this->idempotencyKey = (string) Str::uuid();
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(Campaigns::class)->create($data, auth()->user(), $this->idempotencyKey);
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

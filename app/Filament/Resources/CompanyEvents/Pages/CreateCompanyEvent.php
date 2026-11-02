<?php

namespace App\Filament\Resources\CompanyEvents\Pages;

use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity GO-15: "Add event" (`/admin/events/create`), the event wizard: Basics · Schedule · Place & material ·
 * Audience & feedback · Review. "Publish when created" (on by default) publishes it at once; a publication the service
 * refuses (e.g. an audience that reaches nobody) leaves the event as a draft and says why.
 */
class CreateCompanyEvent extends PeopleCreateRecord
{
    protected static string $resource = CompanyEventResource::class;

    protected static ?string $title = 'Add event';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CompanyEvents::class)->create(['audience_criteria' => AudienceCriteriaSchema::clean($data['audience_criteria'] ?? [])] + $data, auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function afterCreate(): void
    {
        if (! ($this->data['publish_now'] ?? false)) {
            return;
        }
        /** @var CompanyEvent $event */
        $event = $this->getRecord();
        try {
            app(CompanyEvents::class)->publish($event, auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->warning()->title('Saved as a draft, not published')->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return ($this->data['publish_now'] ?? false) ? 'Event added' : 'Event saved as a draft';
    }

    protected function getRedirectUrl(): string
    {
        return CompanyEventResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}

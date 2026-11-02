<?php

namespace App\Filament\Resources\CompanyEvents\Pages;

use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** HROne parity GO-15: a draft changes in full; a published event keeps its schedule and audience (CompanyEvents::update). */
class EditCompanyEvent extends PeopleEditRecord
{
    protected static string $resource = CompanyEventResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CompanyEvent $event */
        $event = $this->getRecord();

        return CompanyEventResource::formData($event, $data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            /** @var CompanyEvent $record */
            return app(CompanyEvents::class)->update($record, ['audience_criteria' => AudienceCriteriaSchema::clean($data['audience_criteria'] ?? [])] + $data, auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return CompanyEventResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}

<?php

namespace App\Filament\Resources\Policies\Pages;

use App\Domain\Configuration\Services\Policies;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

/** New policies are drafted in the policy wizard; this route opens it (kept so existing links keep working). */
class CreatePolicy extends PeopleCreateRecord
{
    protected static string $resource = PolicyResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->redirect(PolicyWizard::getUrl(array_filter(['type' => request()->query('type')])));
    }

    protected function afterCreate(): void
    {
        app(Policies::class)->draft($this->getRecord(), []);
    }

    protected function getRedirectUrl(): string
    {
        return PolicyResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

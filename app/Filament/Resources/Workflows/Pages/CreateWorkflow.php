<?php

namespace App\Filament\Resources\Workflows\Pages;

use App\Domain\Workflow\Services\Workflows;
use App\Filament\Resources\Workflows\WorkflowResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateWorkflow extends PeopleCreateRecord
{
    protected static string $resource = WorkflowResource::class;

    protected function afterCreate(): void
    {
        app(Workflows::class)->draft($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return WorkflowResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

<?php

namespace App\Filament\Support\Pages\Concerns;

use App\Filament\RelationManagers\Masters\MasterTabs;

/**
 * Master relationships R4: on an organisation or workforce master's edit page, the form becomes the "General" tab
 * beside Companies · Links · Company values · Usage · History (each only where the master has it). Other edit pages
 * are unchanged.
 */
trait ShowsMasterTabs
{
    protected function getAllRelationManagers(): array
    {
        $managers = parent::getAllRelationManagers();

        return MasterTabs::isMaster($this->getRecord()) ? [...MasterTabs::MANAGERS, ...$managers] : $managers;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return MasterTabs::isMaster($this->getRecord()) || parent::hasCombinedRelationManagerTabsWithContent();
    }

    public function getContentTabLabel(): ?string
    {
        return MasterTabs::isMaster($this->getRecord()) ? 'General' : parent::getContentTabLabel();
    }
}

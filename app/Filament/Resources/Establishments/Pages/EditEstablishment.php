<?php

namespace App\Filament\Resources\Establishments\Pages;

use App\Filament\Resources\Establishments\EstablishmentResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;

/**
 * Remediation I.5 (2026-11-25): the master's own page, like every other master's: the form as General beside the tabs
 * its type has (ShowsMasterTabs: Usage, History, and for a shared type Companies and Links). Saved through the same
 * audited, approval-aware path as the other masters (GovernedEdit). Creating stays a dialog on the list.
 */
class EditEstablishment extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = EstablishmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // Establishments are closed, never deleted: no delete action.
    }
}

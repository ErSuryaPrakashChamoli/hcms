<?php

namespace App\Filament\RelationManagers\Masters;

use App\Domain\Configuration\Applicability\ConfigurationType;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Support\Masters\GuardsMasterDeletion;
use Illuminate\Database\Eloquent\Model;

/**
 * Master relationships R4: which organisation and workforce masters get the contextual tabs, and which tabs.
 * General (the form) · Companies · Links · Company values · Usage · History (the audit trail, as before).
 */
final class MasterTabs
{
    /** @var list<class-string> in the order they show after General */
    public const MANAGERS = [MasterCompaniesTab::class, MasterLinksTab::class, MasterCompanyValuesTab::class, MasterUsageTab::class];

    public static function isMaster(?Model $record): bool
    {
        return $record !== null && $record->exists && in_array(GuardsMasterDeletion::class, class_uses_recursive($record), true);
    }

    public static function type(Model $record): ?ConfigurationType
    {
        return app(ConfigurationTypes::class)->forModel($record);
    }
}

<?php

namespace App\Filament\RelationManagers\Masters;

use App\Support\Masters\MasterUsage;
use Illuminate\Database\Eloquent\Model;

/** Master relationships R4: what uses the master (counts only, never other records' names), and what that allows. */
final class MasterUsageTab extends MasterTab
{
    protected static ?string $title = 'Usage';

    protected static string $contentView = 'filament.masters.usage';

    public static function appliesTo(Model $record): bool
    {
        return true;
    }

    protected function tabData(): array
    {
        $uses = app(MasterUsage::class)->of($this->getOwnerRecord());

        return ['uses' => $uses, 'active' => MasterUsage::isActive($this->getOwnerRecord())];
    }
}

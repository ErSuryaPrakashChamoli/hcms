<?php

namespace App\Filament\Support;

use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use Filament\Resources\Pages\ViewRecord;

/** View page for a statutory return: lifecycle actions, and every view recorded as STATUTORY_OUTPUT_ACCESSED. */
abstract class ViewStatutoryReturnPage extends ViewRecord
{
    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(StatutoryReturns::class)->recordAccess($this->getRecord(), StatutoryReturnActions::user(), 'ui', 'view');
    }

    protected function getHeaderActions(): array
    {
        return StatutoryReturnActions::forReturn();
    }
}

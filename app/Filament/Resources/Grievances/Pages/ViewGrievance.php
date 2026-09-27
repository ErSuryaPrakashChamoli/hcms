<?php

namespace App\Filament\Resources\Grievances\Pages;

use App\Domain\Grievance\Services\Grievances;
use App\Filament\Resources\Grievances\GrievanceResource;
use App\Filament\Support\GrievanceActions;
use Filament\Resources\Pages\ViewRecord;

class ViewGrievance extends ViewRecord
{
    protected static string $resource = GrievanceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(Grievances::class)->recordAccess($this->getRecord(), auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return GrievanceActions::forCase();
    }
}

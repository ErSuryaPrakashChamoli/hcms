<?php

namespace App\Filament\Resources\PerformanceCycles\Pages;

use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\PerformanceActions;
use Filament\Resources\Pages\EditRecord;

class EditPerformanceCycle extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = PerformanceCycleResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceActions::forCycle();
    }
}

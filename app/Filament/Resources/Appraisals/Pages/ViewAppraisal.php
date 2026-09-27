<?php

namespace App\Filament\Resources\Appraisals\Pages;

use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Support\PerformanceActions;
use Filament\Resources\Pages\ViewRecord;

class ViewAppraisal extends ViewRecord
{
    protected static string $resource = AppraisalResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceActions::forAppraisal();
    }
}

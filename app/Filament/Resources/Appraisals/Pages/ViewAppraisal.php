<?php

namespace App\Filament\Resources\Appraisals\Pages;

use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\PerformanceActions;

class ViewAppraisal extends PeopleViewRecord
{
    protected static string $resource = AppraisalResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceActions::forAppraisal();
    }
}

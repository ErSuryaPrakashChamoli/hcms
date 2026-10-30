<?php

namespace App\Filament\Resources\KpiRatingFormulas\Pages;

use App\Filament\Resources\KpiRatingFormulas\KpiRatingFormulaResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageKpiRatingFormulas extends PeopleManageRecords
{
    protected static string $resource = KpiRatingFormulaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

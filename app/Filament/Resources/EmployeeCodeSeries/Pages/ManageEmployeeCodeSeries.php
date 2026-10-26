<?php

namespace App\Filament\Resources\EmployeeCodeSeries\Pages;

use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Filament\Resources\EmployeeCodeSeries\EmployeeCodeSeriesResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageEmployeeCodeSeries extends PeopleManageRecords
{
    protected static string $resource = EmployeeCodeSeriesResource::class;

    public function getSubheading(): ?string
    {
        $how = 'New joiners get the next code of their company’s series; a company without one uses the default series.';
        if (! EmployeeCodeSeries::query()->exists()) {
            $how .= ' No series is set up yet, so codes continue as '.app(EmployeeCodeGenerator::class)->preview().' and onward.';
        }

        return $how;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New series')];
    }
}

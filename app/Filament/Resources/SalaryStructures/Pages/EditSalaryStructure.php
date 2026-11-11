<?php

namespace App\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Pages\SalaryStructureBuilder;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\Action;

/** A structure's name, status, versions (approve / send back / archive) and history; its components change in the builder. */
class EditSalaryStructure extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = SalaryStructureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('change')->label('Change components')->icon('heroicon-m-adjustments-horizontal')
                ->url(fn () => SalaryStructureBuilder::getUrl(['structure' => $this->getRecord()->getKey()]))->visible(fn () => SalaryStructureBuilder::canAccess()),
            ...parent::getHeaderActions(),
        ];
    }
}

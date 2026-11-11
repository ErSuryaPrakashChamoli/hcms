<?php

namespace App\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Pages\SalaryStructureBuilder;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

/** New structures are set up in the salary structure builder; this route opens it (kept so existing links keep working). */
class CreateSalaryStructure extends PeopleCreateRecord
{
    protected static string $resource = SalaryStructureResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->redirect(SalaryStructureBuilder::getUrl());
    }
}

<?php

namespace App\Filament\Resources\CareerPaths\Pages;

use App\Filament\Resources\CareerPaths\CareerPathResource;
use App\Filament\Support\GovernedEdit;
use Filament\Resources\Pages\EditRecord;

class EditCareerPath extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = CareerPathResource::class;
}

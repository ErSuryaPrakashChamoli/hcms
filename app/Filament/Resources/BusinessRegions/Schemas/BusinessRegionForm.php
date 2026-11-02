<?php

namespace App\Filament\Resources\BusinessRegions\Schemas;

use App\Filament\Resources\Regions\Schemas\RegionForm;
use Filament\Schemas\Schema;

/** Data import DI-1: a business region has the same details as a region. */
class BusinessRegionForm
{
    public static function configure(Schema $schema): Schema
    {
        return RegionForm::configure($schema);
    }
}

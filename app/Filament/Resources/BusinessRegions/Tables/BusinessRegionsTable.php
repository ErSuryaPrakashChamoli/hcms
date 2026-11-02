<?php

namespace App\Filament\Resources\BusinessRegions\Tables;

use App\Filament\Resources\Regions\Tables\RegionsTable;
use Filament\Tables\Table;

/** Data import DI-1: a business region lists like a region. */
class BusinessRegionsTable
{
    public static function configure(Table $table): Table
    {
        return RegionsTable::configure($table);
    }
}

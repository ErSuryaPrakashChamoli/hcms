<?php

namespace App\Filament\Resources\TenantFeatures\Pages;

use App\Filament\Resources\TenantFeatures\TenantFeatureResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTenantFeatures extends ManageRecords
{
    protected static string $resource = TenantFeatureResource::class;
}

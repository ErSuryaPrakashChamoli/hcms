<?php

namespace App\Filament\Resources\TenantFeatures\Pages;

use App\Filament\Resources\TenantFeatures\TenantFeatureResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageTenantFeatures extends PeopleManageRecords
{
    protected static string $resource = TenantFeatureResource::class;
}

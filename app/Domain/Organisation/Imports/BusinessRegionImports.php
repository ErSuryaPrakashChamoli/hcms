<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Models\BusinessRegion;

/** Data import DI-1 (Core → Business regions): business regions by code. Branches name theirs in the Branches upload. */
final class BusinessRegionImports extends RegionImports
{
    public const TYPE = 'business_regions';

    public function label(): string
    {
        return 'Business regions upload';
    }

    protected function model(): string
    {
        return BusinessRegion::class;
    }

    protected function what(): string
    {
        return 'business region';
    }

    protected function aliases(): array
    {
        return ['business_region_code' => 'code', 'business_region_name' => 'name', 'business_region' => 'name'];
    }

    protected function examples(): array
    {
        return [['APAC', 'Asia Pacific', 'Clients and delivery in Asia Pacific', 'active'], ['EMEA', 'Europe, Middle East and Africa', 'Clients and delivery in EMEA', 'active']];
    }
}

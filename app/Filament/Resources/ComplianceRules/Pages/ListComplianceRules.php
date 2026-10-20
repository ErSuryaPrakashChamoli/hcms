<?php

namespace App\Filament\Resources\ComplianceRules\Pages;

use App\Filament\Resources\ComplianceRules\ComplianceRuleResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListComplianceRules extends PeopleListRecords
{
    protected static string $resource = ComplianceRuleResource::class;
}

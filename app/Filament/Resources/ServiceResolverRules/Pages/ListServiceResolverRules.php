<?php

namespace App\Filament\Resources\ServiceResolverRules\Pages;

use App\Filament\Resources\ServiceResolverRules\ServiceResolverRuleResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListServiceResolverRules extends PeopleListRecords
{
    protected static string $resource = ServiceResolverRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add rule')];
    }
}

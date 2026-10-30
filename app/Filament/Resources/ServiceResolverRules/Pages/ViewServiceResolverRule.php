<?php

namespace App\Filament\Resources\ServiceResolverRules\Pages;

use App\Filament\Resources\ServiceResolverRules\ServiceResolverRuleResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\EditAction;

/** HROne's "Review" step: the rule's details and the SLA (service days, working hours, due times) it applies. */
class ViewServiceResolverRule extends PeopleViewRecord
{
    protected static string $resource = ServiceResolverRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}

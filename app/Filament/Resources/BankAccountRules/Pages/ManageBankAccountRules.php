<?php

namespace App\Filament\Resources\BankAccountRules\Pages;

use App\Filament\Resources\BankAccountRules\BankAccountRuleResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageBankAccountRules extends PeopleManageRecords
{
    protected static string $resource = BankAccountRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a rule')];
    }
}

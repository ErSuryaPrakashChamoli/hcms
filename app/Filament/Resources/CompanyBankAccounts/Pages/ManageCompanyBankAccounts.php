<?php

namespace App\Filament\Resources\CompanyBankAccounts\Pages;

use App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageCompanyBankAccounts extends PeopleManageRecords
{
    protected static string $resource = CompanyBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a paying account')];
    }
}

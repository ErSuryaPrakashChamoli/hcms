<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Domain\Enterprise\Services\CurrencyRates;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageExchangeRates extends PeopleManageRecords
{
    protected static string $resource = ExchangeRateResource::class;

    public function getSubheading(): ?string
    {
        return 'Base currency: '.app(CurrencyRates::class)->baseCurrency();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

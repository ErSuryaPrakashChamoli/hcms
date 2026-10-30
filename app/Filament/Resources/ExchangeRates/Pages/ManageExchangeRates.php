<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Domain\Enterprise\Services\CurrencyRates;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ManageExchangeRates extends PeopleManageRecords
{
    protected static string $resource = ExchangeRateResource::class;

    public function getSubheading(): ?string
    {
        return 'Base currency: '.app(CurrencyRates::class)->baseCurrency();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // HROne setup parity EX-A (Expense → Currency): one effective date, many currencies, into the base currency.
            Action::make('severalRates')->label('Add several rates')->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->visible(fn () => ExchangeRateResource::canAccess())
                ->modalDescription(fn () => 'Rates into the base currency ('.app(CurrencyRates::class)->baseCurrency().') from one date. A rate already set for that currency and date is replaced.')
                ->schema([
                    DatePicker::make('effective_on')->label('Effective date')->native(false)->required()->default(now()),
                    Repeater::make('rates')->label('Exchange rate details')->columns(2)->minItems(1)->defaultItems(1)->addActionLabel('Add a currency')->schema([
                        TextInput::make('currency')->label('Exchange currency')->length(3)->required()->alpha(),
                        TextInput::make('rate')->label('Rate (1 unit = rate in base currency)')->numeric()->minValue(0.00000001)->required(),
                    ]),
                ])
                ->action(function (array $data) {
                    abort_unless(ExchangeRateResource::canAccess(), 403);
                    $rates = app(CurrencyRates::class);
                    $base = $rates->baseCurrency();
                    $rows = collect($data['rates'] ?? [])->map(fn (array $r) => ['currency' => strtoupper(trim((string) $r['currency'])), 'rate' => (float) $r['rate']])
                        ->reject(fn (array $r) => $r['currency'] === $base);
                    DB::transaction(fn () => $rows->each(fn (array $r) => $rates->set($r['currency'], $base, $r['rate'], $data['effective_on'])));
                    Notification::make()->success()->title($rows->count().' rate(s) saved from '.Carbon::parse($data['effective_on'])->format('j M Y'))->send();
                }),
        ];
    }
}

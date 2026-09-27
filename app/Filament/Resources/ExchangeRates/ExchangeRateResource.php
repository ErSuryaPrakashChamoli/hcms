<?php

namespace App\Filament\Resources\ExchangeRates;

use App\Domain\Enterprise\Models\ExchangeRate;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Filament\Resources\ExchangeRates\Pages\ManageExchangeRates;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Exchange rates for consolidated reporting (§96). Base currency = the tenant currency. */
class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Enterprise';

    protected static ?string $navigationLabel = 'Exchange rates';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('currency.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            TextInput::make('from_currency')->label('From')->length(3)->required()->default(fn () => app(CurrencyRates::class)->baseCurrency() === 'INR' ? 'USD' : 'INR'),
            TextInput::make('to_currency')->label('To')->length(3)->required()->default(fn () => app(CurrencyRates::class)->baseCurrency()),
            TextInput::make('rate')->numeric()->required()->minValue(0.00000001)->helperText('1 unit of "from" = rate units of "to"'),
            DatePicker::make('effective_on')->native(false)->required()->default(now()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_currency')->label('From'),
                TextColumn::make('to_currency')->label('To'),
                TextColumn::make('rate')->numeric(6),
                TextColumn::make('effective_on')->date()->sortable(),
                TextColumn::make('source')->badge()->color('gray'),
            ])
            ->defaultSort('effective_on', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExchangeRates::route('/')];
    }
}

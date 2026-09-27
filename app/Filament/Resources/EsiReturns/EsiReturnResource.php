<?php

namespace App\Filament\Resources\EsiReturns;

use App\Filament\Resources\EsiReturns\Pages\ListEsiReturns;
use App\Filament\Resources\EsiReturns\Pages\ViewEsiReturn;
use App\Filament\Support\StatutoryReturnResourceBase;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Part T: ESI monthly contribution returns per establishment. */
class EsiReturnResource extends StatutoryReturnResourceBase
{
    protected static string $returnType = 'ESI';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'ESI returns';

    protected static ?string $modelLabel = 'ESI return';

    protected static ?string $slug = 'compliance/esi-returns';

    protected static ?int $navigationSort = 31;

    public static function getPages(): array
    {
        return ['index' => ListEsiReturns::route('/'), 'view' => ViewEsiReturn::route('/{record}')];
    }
}

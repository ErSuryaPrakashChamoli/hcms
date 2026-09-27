<?php

namespace App\Filament\Resources\LwfReturns;

use App\Filament\Resources\LwfReturns\Pages\ListLwfReturns;
use App\Filament\Resources\LwfReturns\Pages\ViewLwfReturn;
use App\Filament\Support\StatutoryReturnResourceBase;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Part T: LWF returns per establishment, state and month. */
class LwfReturnResource extends StatutoryReturnResourceBase
{
    protected static string $returnType = 'LWF';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?string $navigationLabel = 'LWF returns';

    protected static ?string $modelLabel = 'LWF return';

    protected static ?string $slug = 'compliance/lwf-returns';

    protected static ?int $navigationSort = 33;

    public static function getPages(): array
    {
        return ['index' => ListLwfReturns::route('/'), 'view' => ViewLwfReturn::route('/{record}')];
    }
}

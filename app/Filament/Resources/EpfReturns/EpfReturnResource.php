<?php

namespace App\Filament\Resources\EpfReturns;

use App\Filament\Resources\EpfReturns\Pages\ListEpfReturns;
use App\Filament\Resources\EpfReturns\Pages\ViewEpfReturn;
use App\Filament\Support\StatutoryReturnResourceBase;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Part T: EPF / ECR returns per establishment and wage month. */
class EpfReturnResource extends StatutoryReturnResourceBase
{
    protected static string $returnType = 'EPF';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'EPF returns (ECR)';

    protected static ?string $modelLabel = 'EPF return';

    protected static ?string $slug = 'compliance/epf-returns';

    protected static ?int $navigationSort = 30;

    public static function getPages(): array
    {
        return ['index' => ListEpfReturns::route('/'), 'view' => ViewEpfReturn::route('/{record}')];
    }
}

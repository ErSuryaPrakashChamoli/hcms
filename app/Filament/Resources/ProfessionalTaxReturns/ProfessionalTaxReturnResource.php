<?php

namespace App\Filament\Resources\ProfessionalTaxReturns;

use App\Filament\Resources\ProfessionalTaxReturns\Pages\ListProfessionalTaxReturns;
use App\Filament\Resources\ProfessionalTaxReturns\Pages\ViewProfessionalTaxReturn;
use App\Filament\Support\StatutoryReturnResourceBase;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Part T: Professional tax returns per establishment, state and month. */
class ProfessionalTaxReturnResource extends StatutoryReturnResourceBase
{
    protected static string $returnType = 'PT';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Professional tax returns';

    protected static ?string $modelLabel = 'professional tax return';

    protected static ?string $slug = 'compliance/professional-tax-returns';

    protected static ?int $navigationSort = 32;

    public static function getPages(): array
    {
        return ['index' => ListProfessionalTaxReturns::route('/'), 'view' => ViewProfessionalTaxReturn::route('/{record}')];
    }
}

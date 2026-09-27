<?php

namespace App\Filament\Resources\TdsReturns;

use App\Filament\Resources\TdsReturns\Pages\ListTdsReturns;
use App\Filament\Resources\TdsReturns\Pages\ViewTdsReturn;
use App\Filament\Support\StatutoryReturnResourceBase;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Part T: TDS quarterly statements — Form No. 138 (earlier Form 24Q) — per legal entity (TAN). */
class TdsReturnResource extends StatutoryReturnResourceBase
{
    protected static string $returnType = 'TDS';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'TDS statements (Form 138)';

    protected static ?string $modelLabel = 'TDS statement';

    protected static ?string $slug = 'compliance/tds-returns';

    protected static ?int $navigationSort = 34;

    public static function getPages(): array
    {
        return ['index' => ListTdsReturns::route('/'), 'view' => ViewTdsReturn::route('/{record}')];
    }
}

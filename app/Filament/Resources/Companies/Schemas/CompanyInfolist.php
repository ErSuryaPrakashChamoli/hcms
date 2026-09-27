<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('legal_name')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('country_code'),
                        TextEntry::make('timezone')->placeholder('—'),
                        TextEntry::make('currency')->placeholder('—'),
                        TextEntry::make('effective_from')->date()->placeholder('Open'),
                        TextEntry::make('effective_to')->date()->placeholder('Open'),
                        TextEntry::make('updated_at')->dateTime()->label('Last updated'),
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\PlacementField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        Select::make('company_id')->relationship('company', 'name')->searchable()->preload(),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code.'),
                        Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    ]),
                // Master relationships R4: placing a new unit in the organisation structure is optional (Organisation Designer later).
                Section::make('Links')
                    ->description('Optional: place it now or later in the Organisation Designer.')
                    ->schema([PlacementField::make('team')]),
                Section::make('Effective dates')
                    ->description('Leave blank for open-ended. History is never overwritten.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ]),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}

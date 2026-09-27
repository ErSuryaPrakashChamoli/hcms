<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Enums\LocationType;
use App\Domain\Organisation\Models\Location;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        Select::make('company_id')->relationship('company', 'name')->searchable()->preload()->required(),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code.'),
                        Select::make('type')->options(LocationType::class)->default(LocationType::Office)->required(),
                        TextInput::make('address_line_1')->maxLength(255),
                        TextInput::make('address_line_2')->maxLength(255),
                        TextInput::make('city')->maxLength(255),
                        TextInput::make('state_code')->maxLength(16),
                        TextInput::make('postal_code')->maxLength(16),
                        TextInput::make('country_code')->required()->default('IN')->length(2),
                        TextInput::make('timezone')->placeholder('Asia/Kolkata')->maxLength(64),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    ]),
                Section::make('Effective dates')
                    ->description('Leave blank for open-ended. History is never overwritten.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ]),
                CustomFieldsSchema::formSection(Location::class),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}

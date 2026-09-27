<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(32)
                            ->alphaDash()
                            ->helperText('Short unique code, e.g. ABC-TECH.'),
                        TextInput::make('legal_name')->maxLength(255)->columnSpanFull(),
                        Select::make('status')
                            ->options(ActiveStatus::class)
                            ->default(ActiveStatus::Active)
                            ->required(),
                    ]),
                Section::make('Locale')
                    ->columns(3)
                    ->schema([
                        TextInput::make('country_code')->required()->default('IN')->length(2),
                        TextInput::make('timezone')->placeholder('Asia/Kolkata')->maxLength(64),
                        TextInput::make('currency')->placeholder('INR')->length(3),
                    ]),
                Section::make('Effective dates')
                    ->description('Leave blank for open-ended. Historical records are never overwritten.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ]),
                CustomFieldsSchema::formSection(Company::class),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}

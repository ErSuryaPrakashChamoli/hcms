<?php

namespace App\Filament\Resources\Regions\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Data import DI-1: the form of a branch grouping (region or business region). */
class RegionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(120),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code, used by uploads.')
                            ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                        Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    ]),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}

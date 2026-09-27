<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?string $navigationLabel = 'Document types';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash(),
            Select::make('category')->options(config('peopleos.documents.categories'))->required(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Toggle::make('requires_expiry')->label('Has an expiry date'),
            Toggle::make('mandatory_for_onboarding')->label('Mandatory for onboarding'),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.documents.categories.{$state}", $state)),
                IconColumn::make('requires_expiry')->boolean()->label('Expiry'),
                IconColumn::make('mandatory_for_onboarding')->boolean()->label('Onboarding'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(function (DocumentType $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocumentTypes::route('/')];
    }
}

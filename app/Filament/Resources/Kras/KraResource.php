<?php

namespace App\Filament\Resources\Kras;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Performance\Models\Kra;
use App\Filament\Resources\Kras\Pages\ManageKras;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** KRA library with suggested KPIs (§35). */
class KraResource extends Resource
{
    protected static ?string $model = Kra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'KRA library';

    protected static ?int $navigationSort = 62;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('category')->maxLength(64),
            TextInput::make('default_weight')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            Repeater::make('kpis')->label('Suggested KPIs')->columnSpanFull()->columns(3)->schema([
                TextInput::make('name')->required(),
                TextInput::make('measure')->placeholder('e.g. % on-time delivery'),
                TextInput::make('target')->placeholder('e.g. 95'),
            ])->default([]),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('category')->placeholder('—'),
                TextColumn::make('default_weight')->label('Weight')->suffix('%'),
                TextColumn::make('kpis')->label('KPIs')->state(fn (Kra $record) => count($record->kpis ?? [])),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make()->using(function (Kra $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageKras::route('/')];
    }
}

<?php

namespace App\Filament\Resources\RatingScales;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Performance\Models\RatingScale;
use App\Filament\Resources\RatingScales\Pages\ManageRatingScales;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
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

/** Rating scales (§35). */
class RatingScaleResource extends Resource
{
    protected static ?string $model = RatingScale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Rating scales';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Repeater::make('levels')->columnSpanFull()->columns(3)->minItems(2)->reorderable(false)
                ->schema([
                    TextInput::make('value')->numeric()->required(),
                    TextInput::make('label')->required()->maxLength(64),
                    TextInput::make('description')->maxLength(255),
                ])
                ->default(config('peopleos.performance.defaults.rating_scale.levels')),
            Toggle::make('is_default')->label('Default scale'),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('levels')->label('Levels')->state(fn (RatingScale $record) => collect($record->levels)->map(fn ($l) => rtrim(rtrim(number_format($l['value'], 1, '.', ''), '0'), '.').' '.$l['label'])->implode(' · '))->wrap(),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make()->using(function (RatingScale $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRatingScales::route('/')];
    }
}

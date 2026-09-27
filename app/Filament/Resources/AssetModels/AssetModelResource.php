<?php

namespace App\Filament\Resources\AssetModels;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\AssetModels\Pages\ManageAssetModels;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AssetModelResource extends Resource
{
    protected static ?string $model = AssetModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Assets';

    protected static ?string $navigationLabel = 'Models';

    protected static ?int $navigationSort = 51;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('asset.manage') || auth()->user()?->can('asset.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('category');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('asset_category_id')->label('Category')->required()->options(fn () => AssetCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('manufacturer')->maxLength(255),
            TextInput::make('name')->label('Model')->required()->maxLength(255),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            KeyValue::make('specifications')->columnSpanFull()->keyLabel('Spec')->valueLabel('Value'),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')->label('Category')->sortable(),
                TextColumn::make('manufacturer')->searchable()->placeholder('—'),
                TextColumn::make('name')->label('Model')->searchable(),
                TextColumn::make('specifications')->state(fn (AssetModel $record) => collect($record->specifications ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode(', '))->limit(60)->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([SelectFilter::make('asset_category_id')->label('Category')->relationship('category', 'name')])
            ->recordActions([
                EditAction::make()->using(function (AssetModel $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAssetModels::route('/')];
    }
}

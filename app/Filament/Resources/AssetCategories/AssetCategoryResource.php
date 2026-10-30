<?php

namespace App\Filament\Resources\AssetCategories;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Identity\Models\Role;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\AssetCategories\Pages\ManageAssetCategories;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AssetCategoryResource extends Resource
{
    protected static ?string $model = AssetCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Assets';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('asset.manage') || auth()->user()?->can('asset.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Category')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                Toggle::make('requires_serial')->label('Serial number required'),
                Toggle::make('is_it_asset')->label('IT asset (IT clearance at exit)'),
                TextInput::make('default_life_months')->label('Useful life')->numeric()->minValue(1)->suffix('months'),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            // HROne setup parity AS-A (Asset type: role, due days).
            Section::make('Request handling')->columns(2)->schema([
                Select::make('owner_role_id')->label('Role')->placeholder('—')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Owns this type: fulfils asset requests and is told when a leaving employee still holds one.'),
                TextInput::make('fulfilment_days')->label('Due days')->numeric()->minValue(0)->maxValue(365)->suffix('days')
                    ->helperText('Days the role has to fulfil a request for this type.'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                IconColumn::make('requires_serial')->label('Serial')->boolean(),
                IconColumn::make('is_it_asset')->label('IT')->boolean(),
                TextColumn::make('ownerRole.name')->label('Role')->placeholder('—')->toggleable(),
                TextColumn::make('fulfilment_days')->label('Due days')->placeholder('—')->toggleable(),
                TextColumn::make('assets_count')->counts('assets')->label('Assets'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('id')->label('Asset type')->multiple()->options(fn () => AssetCategory::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->recordActions([
                EditAction::make()->using(function (AssetCategory $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAssetCategories::route('/')];
    }
}

<?php

namespace App\Filament\Resources\AssetSubtypes;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\AssetSubtypes\Pages\ManageAssetSubtypes;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
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
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity AS-A (Asset → Asset sub type): e.g. Laptop → Premium series / Standard series. Each sub type says
 * whether its assets may share a serial number and whether employees must acknowledge receiving one.
 */
class AssetSubtypeResource extends Resource
{
    protected static ?string $model = AssetSubtype::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static string|UnitEnum|null $navigationGroup = 'Assets';

    protected static ?string $navigationLabel = 'Sub types';

    protected static ?string $modelLabel = 'asset sub type';

    protected static ?int $navigationSort = 50;

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
            Section::make('Sub type')->columns(2)->schema([
                Select::make('asset_category_id')->label('Asset type')->required()
                    ->options(fn () => AssetCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('name')->label('Asset sub type')->required()->maxLength(120),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                    ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                TextInput::make('sort_order')->numeric()->default(0),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            Section::make('Serial numbers and receipt')->columns(2)->schema([
                Toggle::make('allow_duplicate_serial')->label('Can two assets of this sub type have the same serial number?')->default(true),
                Toggle::make('acknowledgement_required')->label('Must the employee acknowledge receiving an asset of this sub type?')->default(false)
                    ->helperText('Yes: the employee gets a task on My tasks and reminders until they acknowledge it.'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')->label('Asset type')->sortable(),
                TextColumn::make('name')->label('Asset sub type')->searchable()->sortable(),
                TextColumn::make('code'),
                IconColumn::make('allow_duplicate_serial')->label('Duplicate serials')->boolean(),
                IconColumn::make('acknowledgement_required')->label('Acknowledgement')->boolean(),
                TextColumn::make('assets_count')->counts('assets')->label('Assets'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('asset_category_id')->label('Asset type')->multiple()->relationship('category', 'name'),
                SelectFilter::make('id')->label('Asset sub type')->multiple()->options(fn () => AssetSubtype::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->recordActions([
                EditAction::make()->using(function (AssetSubtype $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAssetSubtypes::route('/')];
    }
}

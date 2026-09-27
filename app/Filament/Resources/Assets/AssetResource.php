<?php

namespace App\Filament\Resources\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Assets\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Assets\RelationManagers\RepairsRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The asset register (§38). Employees with only asset.own see what is in their custody. */
class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Assets';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'asset_tag';

    public static function getGloballySearchableAttributes(): array
    {
        return ['asset_tag', 'name', 'serial_number'];
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->can('asset.view') || auth()->user()?->can('asset.manage') || auth()->user()?->can('asset.assign') ? 'Asset register' : 'My assets';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['category', 'model', 'custodian.person', 'location']);
        $user = auth()->user();
        if ($user->can('asset.view') || $user->can('asset.manage') || $user->can('asset.assign')) {
            return $query;
        }

        return $query->where('custodian_id', Employee::query()->where('user_id', $user->id)->value('id') ?? 0);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'in_stock' => 'success', 'assigned' => 'info', 'in_repair', 'in_transit' => 'warning', 'lost' => 'danger', default => 'gray'
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asset')->columns(3)->schema([
                Select::make('asset_category_id')->label('Category')->required()->live()->options(fn () => AssetCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('asset_model_id')->label('Model')->placeholder('—')->options(fn (Get $get) => AssetModel::query()->where('asset_category_id', $get('asset_category_id'))->get()->mapWithKeys(fn ($m) => [$m->id => $m->auditLabel()])->all()),
                TextInput::make('asset_tag')->label('Asset tag')->required()->maxLength(64)->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', app(TenantContext::class)->id())),
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                TextInput::make('serial_number')->maxLength(128)->required(fn (Get $get) => (bool) AssetCategory::query()->whereKey($get('asset_category_id'))->value('requires_serial')),
                Select::make('condition')->options(config('peopleos.assets.conditions'))->default('new')->required(),
                Select::make('company_id')->label('Owned by')->placeholder('—')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('location_id')->label('Location')->placeholder('—')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
            ]),
            Section::make('Procurement')->columns(4)->schema([
                DatePicker::make('purchase_date')->native(false),
                TextInput::make('purchase_cost')->numeric()->minValue(0),
                TextInput::make('vendor')->maxLength(255),
                TextInput::make('invoice_number')->maxLength(64),
                DatePicker::make('warranty_until')->native(false),
                Textarea::make('notes')->rows(2)->columnSpan(3),
            ]),
            CustomFieldsSchema::formSection(Asset::class),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Asset $record) => "{$record->name} [{$record->asset_tag}]")->columns(4)->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.assets.statuses.{$state}", $state)),
                TextEntry::make('condition')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.assets.conditions.{$state}", $state)),
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('model')->label('Model')->state(fn (Asset $record) => $record->model?->auditLabel())->placeholder('—'),
                TextEntry::make('serial_number')->placeholder('—'),
                TextEntry::make('custodian.person.full_name')->label('Custodian')->placeholder('In stock'),
                TextEntry::make('location.name')->label('Location')->placeholder('—'),
                TextEntry::make('company.name')->label('Owned by')->placeholder('—'),
                TextEntry::make('purchase_date')->date()->placeholder('—'),
                TextEntry::make('purchase_cost')->numeric(2)->placeholder('—'),
                TextEntry::make('vendor')->placeholder('—'),
                TextEntry::make('warranty_until')->date()->placeholder('—')->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
                TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
            CustomFieldsSchema::infolistSection(Asset::class),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('asset_tag')->label('Tag')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->description(fn (Asset $record) => $record->model?->auditLabel()),
                TextColumn::make('category.name')->label('Category')->sortable(),
                TextColumn::make('serial_number')->label('Serial')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.assets.statuses.{$state}", $state)),
                TextColumn::make('custodian.person.full_name')->label('Custodian')->placeholder('—'),
                TextColumn::make('location.name')->label('Location')->placeholder('—')->toggleable(),
                TextColumn::make('condition')->badge()->color('gray')->toggleable(),
                TextColumn::make('warranty_until')->date()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.assets.statuses')),
                SelectFilter::make('asset_category_id')->label('Category')->relationship('category', 'name'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()->visible(fn () => auth()->user()->can('asset.manage'))]);
    }

    public static function getRelations(): array
    {
        return [AssignmentsRelationManager::class, MovementsRelationManager::class, RepairsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'create' => CreateAsset::route('/create'),
            'view' => ViewAsset::route('/{record}'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }
}

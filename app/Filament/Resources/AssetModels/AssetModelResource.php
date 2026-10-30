<?php

namespace App\Filament\Resources\AssetModels;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Services\Assets;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\AssetModels\Pages\ManageAssetModels;
use App\Filament\Support\AuditReasonField;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
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
        return parent::getEloquentQuery()->with(['category', 'subtype']);
    }

    public static function form(Schema $schema): Schema
    {
        $software = fn (Get $get) => in_array($get('deployment_type'), ['on_premise_software', 'cloud_saas'], true)
            || AssetCategory::query()->whereKey($get('asset_category_id'))->value('code') === 'LICENCE';

        return $schema->columns(2)->components([
            Section::make('Product')->columns(2)->schema([
                Select::make('asset_category_id')->label('Category')->required()->live()->options(fn () => AssetCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                // HROne setup parity AS-A (Products): sub type, description, life, deployment type, licence basis, documents.
                Select::make('asset_subtype_id')->label('Sub type')->placeholder('—')
                    ->options(fn (Get $get) => AssetSubtype::query()->where('asset_category_id', $get('asset_category_id'))->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('manufacturer')->maxLength(255),
                TextInput::make('name')->label('Model')->required()->maxLength(255),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                Textarea::make('description')->rows(2)->maxLength(2000)->columnSpanFull(),
            ]),
            Section::make('Life and licensing')->columns(2)->schema([
                TextInput::make('life_months')->label('Product life')->numeric()->minValue(1)->maxValue(600)->suffix('months')
                    ->helperText('Blank: the category\'s useful life. Used for book value.'),
                Select::make('deployment_type')->label('Deployment type')->placeholder('—')->options(fn () => config('peopleos.assets.deployment_types'))->live(),
                Select::make('licence_basis')->label('License for')->placeholder('—')->options(fn () => config('peopleos.assets.licence_bases'))->visible($software),
            ]),
            Section::make('Specifications and documents')->columns(2)->schema([
                KeyValue::make('specifications')->columnSpanFull()->keyLabel('Spec')->valueLabel('Value'),
                FileUpload::make('documents')->label('Documents')->multiple()->maxFiles(10)->columnSpanFull()
                    ->disk(fn () => config('peopleos.documents.disk', 'local'))->directory(fn () => Assets::documentDirectory())->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->maxSize((int) config('peopleos.documents.max_kb', 10240))
                    ->helperText('Invoices, warranty cards, licence keys or manuals. Kept private; opened through short-lived links.'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')->label('Category')->sortable(),
                TextColumn::make('subtype.name')->label('Sub type')->placeholder('—')->toggleable(),
                TextColumn::make('manufacturer')->searchable()->placeholder('—'),
                TextColumn::make('name')->label('Model')->searchable(),
                TextColumn::make('specifications')->state(fn (AssetModel $record) => collect($record->specifications ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode(', '))->limit(60)->placeholder('—'),
                TextColumn::make('deployment_type')->label('Deployment')->formatStateUsing(fn (?string $state) => config("peopleos.assets.deployment_types.{$state}", $state))->placeholder('—')->toggleable(),
                TextColumn::make('life_months')->label('Life')->suffix(' months')->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('asset_category_id')->label('Category')->relationship('category', 'name'),
                SelectFilter::make('asset_subtype_id')->label('Sub type')->relationship('subtype', 'name'),
                SelectFilter::make('id')->label('Product')->multiple()->options(fn () => AssetModel::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->recordActions([
                Action::make('documents')->label('Documents')->icon(Heroicon::OutlinedPaperClip)->color('gray')
                    ->visible(fn (AssetModel $record) => ! empty($record->documents))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (AssetModel $record) => new HtmlString('<ul class="space-y-1">'.collect(array_values((array) $record->documents))
                        ->map(fn (string $path, int $i) => '<li><a class="text-primary-600 underline" href="'.e(ParityFileController::url('asset-model', $record, $i)).'">'.e(basename($path)).'</a></li>')->implode('').'</ul>')),
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

<?php

namespace App\Filament\Resources\ServiceDefinitions;

use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ServiceDefinitions\Pages\CreateServiceDefinition;
use App\Filament\Resources\ServiceDefinitions\Pages\EditServiceDefinition;
use App\Filament\Resources\ServiceDefinitions\Pages\ListServiceDefinitions;
use App\Filament\Resources\ServiceDefinitions\RelationManagers\VersionsRelationManager;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 12: the HR service catalogue. A service's content lives on its versions (Draft → Pending
 * approval → Scheduled / Active → Superseded, or Archived). A version is prepared by one person and
 * approved by another, and is never edited once submitted.
 */
class ServiceDefinitionResource extends Resource
{
    protected static ?string $model = ServiceDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Service Desk';

    protected static ?string $navigationLabel = 'Service catalogue';

    protected static ?string $modelLabel = 'service';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(fn (string $operation) => $operation === 'create'),
            Select::make('ticket_category_id')->label('Category')->options(fn () => TicketCategory::query()->orderBy('sort_order')->pluck('name', 'id')->all())->required(),
            TextInput::make('subcategory')->maxLength(64),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->visibleOn('edit'),
            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('category')->withCount('versions'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (ServiceDefinition $record) => $record->subcategory),
                TextColumn::make('code')->searchable(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('in_force')->label('Version in force')->state(fn (ServiceDefinition $record) => ($v = app(ServiceCatalogue::class)->versionOn($record)) ? 'v'.$v->version.' since '.$v->effective_from->toDateString() : 'Not available'),
                TextColumn::make('versions_count')->label('Versions'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('ticket_category_id')->label('Category')->relationship('category', 'name')])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceDefinitions::route('/'),
            'create' => CreateServiceDefinition::route('/create'),
            'edit' => EditServiceDefinition::route('/{record}/edit'),
        ];
    }
}

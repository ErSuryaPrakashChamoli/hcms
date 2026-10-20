<?php

namespace App\Filament\Resources\LearningProviders;

use App\Domain\Learning\Models\LearningProvider;
use App\Filament\Resources\LearningProviders\Pages\ManageLearningProviders;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 8: learning providers — internal L&D teams and external vendors. */
class LearningProviderResource extends Resource
{
    protected static ?string $model = LearningProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Providers';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Select::make('provider_type')->label('Type')->options(config('peopleos.learning.provider_types'))->default('external')->required(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            TextInput::make('website')->url()->maxLength(255),
            TextInput::make('contact_name')->maxLength(255),
            TextInput::make('contact_email')->email()->maxLength(255),
            TextInput::make('contact_phone')->tel()->maxLength(32),
            Textarea::make('address')->rows(2)->columnSpanFull(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    /** @return array<int, CreateAction> */
    public static function headerActions(): array
    {
        return [CreateAction::make()->visible(fn () => auth()->user()->can('learning.manage'))];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (LearningProvider $record) => $record->code),
                TextColumn::make('provider_type')->label('Type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.learning.provider_types.{$state}", $state)),
                TextColumn::make('contact_name')->label('Contact')->placeholder('—'),
                TextColumn::make('instructors_count')->counts('instructors')->label('Instructors'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('provider_type')->label('Type')->options(config('peopleos.learning.provider_types'))])
            ->recordActions([EditAction::make()->visible(fn () => auth()->user()->can('learning.manage'))])
            ->emptyStateHeading('No providers yet')->emptyStateDescription('Add your internal L&D team and any external training vendors.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageLearningProviders::route('/')];
    }
}

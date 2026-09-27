<?php

namespace App\Filament\Resources\CustomFields;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class CustomFieldResource extends Resource
{
    protected static ?string $model = CustomField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Customisation';

    protected static ?string $navigationLabel = 'Custom fields';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Definition')
                ->columns(3)
                ->schema([
                    Select::make('entity')
                        ->options(collect(config('peopleos.custom_fields.entities'))->map(fn ($e) => $e['label'])->all())
                        ->required()
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->dehydrated(),
                    TextInput::make('label')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('key', Str::snake(Str::limit($state, 40, ''))) : null),
                    TextInput::make('key')
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[a-z][a-z0-9_]*$/')
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->dehydrated()
                        ->helperText('Stable identifier used in reports and integrations.'),
                    Select::make('type')
                        ->options(config('peopleos.custom_fields.types'))
                        ->required()
                        ->live()
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->dehydrated(),
                    TextInput::make('help_text')->maxLength(255)->columnSpan(2),
                    Repeater::make('options')
                        ->schema([
                            TextInput::make('value')->required()->maxLength(64),
                            TextInput::make('label')->required()->maxLength(255),
                        ])
                        ->columns(2)
                        ->visible(fn (Get $get) => in_array($get('type'), ['dropdown', 'multiselect'], true))
                        ->columnSpanFull(),
                ]),
            Section::make('Behaviour')
                ->columns(4)
                ->schema([
                    Toggle::make('is_required')->label('Required'),
                    Toggle::make('visible_to_employee')->label('Visible to employee')->default(true),
                    Toggle::make('visible_to_manager')->label('Visible to manager')->default(true),
                    Toggle::make('visible_to_hr')->label('Visible to HR')->default(true),
                    Toggle::make('is_searchable')->label('Searchable'),
                    Toggle::make('is_reportable')->label('Reportable')->default(true),
                    TextInput::make('sort_order')->numeric()->default(0),
                    Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    DatePicker::make('effective_from')->native(false),
                    DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    KeyValue::make('validation')
                        ->label('Extra validation rules')
                        ->keyLabel('Rule')
                        ->valueLabel('Value')
                        ->helperText('Laravel rules, e.g. max → 100. Optional.')
                        ->columnSpanFull(),
                ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.custom_fields.entities.{$state}.label", $state)),
                TextColumn::make('label')->searchable()->sortable(),
                TextColumn::make('key')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.custom_fields.types.{$state}", $state)),
                IconColumn::make('is_required')->label('Required')->boolean(),
                TextColumn::make('sort_order')->sortable()->toggleable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('entity')->options(collect(config('peopleos.custom_fields.entities'))->map(fn ($e) => $e['label'])->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('entity')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomFields::route('/'),
            'create' => CreateCustomField::route('/create'),
            'edit' => EditCustomField::route('/{record}/edit'),
        ];
    }
}

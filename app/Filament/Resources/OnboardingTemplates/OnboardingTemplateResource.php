<?php

namespace App\Filament\Resources\OnboardingTemplates;

use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\OnboardingTemplates\Pages\CreateOnboardingTemplate;
use App\Filament\Resources\OnboardingTemplates\Pages\EditOnboardingTemplate;
use App\Filament\Resources\OnboardingTemplates\Pages\ListOnboardingTemplates;
use App\Filament\Resources\OnboardingTemplates\RelationManagers\ItemsRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class OnboardingTemplateResource extends Resource
{
    protected static ?string $model = OnboardingTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Onboarding templates';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('key', Str::slug($state, '_')) : null),
                TextInput::make('key')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
                TextInput::make('priority')->numeric()->default(100)->helperText('Lower wins when several templates match an employee.'),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            Section::make('Applies to')->description('Leave empty for everyone.')->collapsed()->schema([
                RuleConditionsSchema::repeater()->label('Conditions')->minItems(0)->defaultItems(0),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('key'),
                TextColumn::make('priority')->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Items'),
                TextColumn::make('conditions')->label('Applies to')->state(fn (OnboardingTemplate $record) => $record->conditions ? RuleConditionsSchema::describe($record->conditions) : 'Everyone')->wrap(),
                TextColumn::make('plans_count')->counts('plans')->label('Plans'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('priority')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnboardingTemplates::route('/'),
            'create' => CreateOnboardingTemplate::route('/create'),
            'edit' => EditOnboardingTemplate::route('/{record}/edit'),
        ];
    }
}

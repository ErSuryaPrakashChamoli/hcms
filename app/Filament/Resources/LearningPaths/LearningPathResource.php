<?php

namespace App\Filament\Resources\LearningPaths;

use App\Domain\Learning\Models\LearningPath;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\LearningPaths\Pages\CreateLearningPath;
use App\Filament\Resources\LearningPaths\Pages\EditLearningPath;
use App\Filament\Resources\LearningPaths\Pages\ListLearningPaths;
use App\Filament\Resources\LearningPaths\RelationManagers\CoursesRelationManager;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LearningPathResource extends Resource
{
    protected static ?string $model = LearningPath::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Learning paths';

    protected static ?int $navigationSort = 11;

    public static function canCreate(): bool
    {
        return auth()->user()->can('learning.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('items_count')->counts('items')->label('Courses'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([EditAction::make()->visible(fn () => auth()->user()->can('learning.manage'))]);
    }

    public static function getRelations(): array
    {
        return [CoursesRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLearningPaths::route('/'),
            'create' => CreateLearningPath::route('/create'),
            'edit' => EditLearningPath::route('/{record}/edit'),
        ];
    }
}

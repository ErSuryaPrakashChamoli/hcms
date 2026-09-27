<?php

namespace App\Filament\Resources\CareerPaths;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Performance\Models\CareerPath;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\CareerPaths\Pages\CreateCareerPath;
use App\Filament\Resources\CareerPaths\Pages\EditCareerPath;
use App\Filament\Resources\CareerPaths\Pages\ListCareerPaths;
use App\Filament\Resources\CareerPaths\RelationManagers\StepsRelationManager;
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

/** Career ladders (§36). */
class CareerPathResource extends Resource
{
    protected static ?string $model = CareerPath::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Career paths';

    protected static ?int $navigationSort = 63;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Select::make('job_family_id')->label('Job family')->options(fn () => JobFamily::query()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Any'),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('jobFamily.name')->label('Job family')->placeholder('Any'),
                TextColumn::make('ladder')->label('Ladder')->state(fn (CareerPath $record) => $record->steps()->with('designation')->get()->pluck('designation.name')->implode(' → '))->wrap(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [StepsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCareerPaths::route('/'),
            'create' => CreateCareerPath::route('/create'),
            'edit' => EditCareerPath::route('/{record}/edit'),
        ];
    }
}

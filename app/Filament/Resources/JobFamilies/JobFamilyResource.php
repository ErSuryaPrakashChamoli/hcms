<?php

namespace App\Filament\Resources\JobFamilies;

use App\Domain\Organisation\Models\JobFamily;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\JobFamilies\Pages\CreateJobFamily;
use App\Filament\Resources\JobFamilies\Pages\EditJobFamily;
use App\Filament\Resources\JobFamilies\Pages\ListJobFamilies;
use App\Filament\Resources\JobFamilies\Schemas\JobFamilyForm;
use App\Filament\Resources\JobFamilies\Tables\JobFamiliesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class JobFamilyResource extends Resource
{
    protected static ?string $model = JobFamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'job family';

    protected static ?string $pluralModelLabel = 'job families';

    public static function form(Schema $schema): Schema
    {
        return JobFamilyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobFamiliesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AuditHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobFamilies::route('/'),
            'create' => CreateJobFamily::route('/create'),
            'edit' => EditJobFamily::route('/{record}/edit'),
        ];
    }
}

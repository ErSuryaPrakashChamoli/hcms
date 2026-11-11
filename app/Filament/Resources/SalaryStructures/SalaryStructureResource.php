<?php

namespace App\Filament\Resources\SalaryStructures;

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Services\StructureBuilder;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Pages\SalaryStructureBuilder;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\SalaryStructures\Pages\CreateSalaryStructure;
use App\Filament\Resources\SalaryStructures\Pages\EditSalaryStructure;
use App\Filament\Resources\SalaryStructures\Pages\ListSalaryStructures;
use App\Filament\Resources\SalaryStructures\RelationManagers\VersionsRelationManager;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
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

/** Compensation structures (§30; Phase 11: owned by Compensation): which payroll components, in which order, with optional formula overrides. */
class SalaryStructureResource extends Resource
{
    protected static ?string $model = SalaryStructure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Salary structures';

    protected static ?int $navigationSort = 41;

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
                TextColumn::make('current_version')->label('In use')->state(fn (SalaryStructure $record) => ($v = $record->versionOn()) ? 'Since '.$v->effective_from->format('j M Y') : 'Not yet'),
                TextColumn::make('change')->label('Change')->badge()->placeholder('—')
                    ->state(fn (SalaryStructure $record) => match (app(StructureBuilder::class)->versionFor($record)?->status) {
                        'draft' => 'Draft', 'pending_approval' => 'Waiting for approval', 'scheduled' => 'Approved, starts later', default => null,
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'Waiting for approval' => 'warning', 'Approved, starts later' => 'info', default => 'gray'
                    }),
                TextColumn::make('versions_count')->counts('versions')->label('Versions'),
                TextColumn::make('assignments_count')->counts('assignments')->label('Employees'),
                TextColumn::make('status')->badge(),
            ])
            ->recordUrl(fn (SalaryStructure $record) => SalaryStructureBuilder::canAccess() ? SalaryStructureBuilder::getUrl(['structure' => $record->getKey()]) : static::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Action::make('build')->label('Change')->icon('heroicon-m-adjustments-horizontal')->visible(fn () => SalaryStructureBuilder::canAccess())
                    ->url(fn (SalaryStructure $record) => SalaryStructureBuilder::getUrl(['structure' => $record->getKey()])),
                EditAction::make()->label(fn (SalaryStructure $record) => SalaryStructureVersion::query()->where('salary_structure_id', $record->getKey())->where('status', 'pending_approval')->exists() ? 'Approve' : 'Versions')
                    ->icon('heroicon-m-clock')->color('gray'),
            ]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalaryStructures::route('/'),
            'create' => CreateSalaryStructure::route('/create'),
            'edit' => EditSalaryStructure::route('/{record}/edit'),
        ];
    }
}

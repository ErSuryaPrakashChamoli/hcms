<?php

namespace App\Filament\Resources\BgvCases;

use App\Domain\Bgv\Models\BgvCase;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\BgvCases\Pages\ListBgvCases;
use App\Filament\Resources\BgvCases\Pages\ViewBgvCase;
use App\Filament\Resources\BgvCases\RelationManagers\ChecksRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BgvCaseResource extends Resource
{
    protected static ?string $model = BgvCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Background checks';

    protected static ?string $modelLabel = 'BGV case';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'initiator']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Case')->columns(4)->schema([
                TextEntry::make('employee.person.display_name')->label('Employee'),
                TextEntry::make('employee.employee_code')->label('ID'),
                TextEntry::make('provider')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.bgv.providers.{$state}.label", $state)),
                TextEntry::make('external_reference')->placeholder('—'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => BgvCase::STATUSES[$state] ?? $state),
                TextEntry::make('overall_result')->badge()->formatStateUsing(fn (string $state) => BgvCase::RESULTS[$state] ?? $state),
                TextEntry::make('consent_given_at')->dateTime()->placeholder('—'),
                TextEntry::make('consentDocument.title')->label('Consent document')->placeholder('—'),
                TextEntry::make('initiator.name')->label('Initiated by')->placeholder('—'),
                TextEntry::make('initiated_at')->dateTime(),
                TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name']),
                TextColumn::make('provider')->badge()->color('gray'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => BgvCase::STATUSES[$state] ?? $state),
                TextColumn::make('overall_result')->label('Result')->badge()->color(fn (string $state) => match ($state) {
                    'clear' => 'success', 'discrepancy' => 'warning', 'failed' => 'danger', default => 'gray',
                }),
                TextColumn::make('initiated_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(BgvCase::STATUSES)->multiple(),
                SelectFilter::make('overall_result')->options(BgvCase::RESULTS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ChecksRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBgvCases::route('/'),
            'view' => ViewBgvCase::route('/{record}'),
        ];
    }
}

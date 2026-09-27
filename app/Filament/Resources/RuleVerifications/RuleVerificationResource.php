<?php

namespace App\Filament\Resources\RuleVerifications;

use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Filament\Resources\RuleVerifications\Pages\ListRuleVerifications;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Phase 5 Part G/T: the append-only rule verification history (read-only). */
class RuleVerificationResource extends Resource
{
    protected static ?string $model = ComplianceRuleVerification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Rule verification';

    protected static ?int $navigationSort = 21;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('rule');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('rule.code')->label('Rule')->badge(),
                TextColumn::make('rule.state')->label('State')->placeholder('—'),
                TextColumn::make('rule.version')->label('Version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('action')->badge(),
                TextColumn::make('to_status')->label('Status')->badge(),
                TextColumn::make('actor_label')->label('By')->placeholder('system'),
                TextColumn::make('source_url')->label('Source')->limit(40)->placeholder('—'),
                TextColumn::make('rule_checksum')->label('Checksum')->limit(12)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('action')->options(array_combine(ComplianceRuleVerification::ACTIONS, ComplianceRuleVerification::ACTIONS))])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListRuleVerifications::route('/')];
    }
}

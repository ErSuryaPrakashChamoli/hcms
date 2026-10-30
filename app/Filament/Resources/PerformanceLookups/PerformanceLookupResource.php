<?php

namespace App\Filament\Resources\PerformanceLookups;

use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\PerformanceLookups\Pages\ManagePerformanceLookups;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity HP-8a: performance masters — perspectives (Goal → Perspective), initiative types (Initiative)
 * and PIP reasons (PIP → PIP reason), one tab per kind. Standard entries can be renamed or deactivated, not deleted.
 */
class PerformanceLookupResource extends Resource
{
    protected static ?string $model = PerformanceLookup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Performance masters';

    protected static ?string $modelLabel = 'master entry';

    protected static ?int $navigationSort = 64;

    /** @return array<string, string> kind => label (perspectives use the organisation's term) */
    public static function kinds(): array
    {
        $kinds = (array) config('peopleos.performance.lookup_kinds');
        $kinds['perspective'] = PerformanceLabels::get('perspective');

        return $kinds;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('kind')->options(self::kinds())->required()->native(false)->live()
                ->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('code')->required()->maxLength(40)->alphaDash()
                ->disabled(fn (?PerformanceLookup $record) => $record?->is_system ?? false)->dehydrated()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => TenantUnique::rule()($rule)->where('kind', $get('kind'))),
            TextInput::make('sort_order')->numeric()->default(0),
            TextInput::make('description')->maxLength(500)->columnSpanFull(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge()->color('gray')->formatStateUsing(fn (string $state) => self::kinds()[$state] ?? $state),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('description')->limit(60)->placeholder('—')->toggleable(),
                IconColumn::make('is_system')->label('Standard')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('kind')->options(self::kinds())])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make()->using(function (PerformanceLookup $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make()->visible(fn (PerformanceLookup $record) => ! $record->is_system),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformanceLookups::route('/')];
    }
}

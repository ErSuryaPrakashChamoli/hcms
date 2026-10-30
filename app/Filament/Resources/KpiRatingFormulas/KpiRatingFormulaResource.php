<?php

namespace App\Filament\Resources\KpiRatingFormulas;

use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\KpiRatingFormulas\Pages\ManageKpiRatingFormulas;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity HP-8c (Review → KPI rating formula): achievement bands that turn a goal's achievement into a
 * rating — "Select the formula for KPI rating" (achievement ÷ target or target ÷ achievement) and the rating
 * calculation table (from %, to %, rating given).
 */
class KpiRatingFormulaResource extends Resource
{
    protected static ?string $model = KpiRatingFormula::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $modelLabel = 'KPI rating formula';

    protected static ?int $navigationSort = 61;

    public static function getNavigationLabel(): string
    {
        return PerformanceLabels::get('kpi').' rating formulas';
    }

    public static function form(Schema $schema): Schema
    {
        $achievement = PerformanceLabels::get('achievement');
        $target = PerformanceLabels::get('target');

        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
            Radio::make('based_on')->label('Select the formula for KPI rating')->required()->default('achievement_over_target')->columnSpanFull()
                ->options(['achievement_over_target' => "{$achievement} ÷ {$target}", 'target_over_achievement' => "{$target} ÷ {$achievement}"])
                ->helperText('Reverse KPIs (lower is better) always use target ÷ achievement.'),
            Repeater::make('bands')->label('Rating calculation formula')->columnSpanFull()->columns(3)->minItems(1)->reorderable(false)
                ->schema([
                    TextInput::make('from_pct')->label("{$achievement} from (%)")->numeric()->required(),
                    TextInput::make('to_pct')->label('to (%)')->numeric()->placeholder('No upper limit'),
                    TextInput::make('rating')->label('Rating given')->numeric()->required(),
                ])
                ->default([['from_pct' => 0, 'to_pct' => 59.99, 'rating' => 1], ['from_pct' => 60, 'to_pct' => 79.99, 'rating' => 2], ['from_pct' => 80, 'to_pct' => 99.99, 'rating' => 3], ['from_pct' => 100, 'to_pct' => 119.99, 'rating' => 4], ['from_pct' => 120, 'to_pct' => null, 'rating' => 5]]),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Formula')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('based_on')->label('Based on')->formatStateUsing(fn (string $state) => config("peopleos.performance.kpi_formula_bases.{$state}", $state)),
                TextColumn::make('bands')->label('Bands')->wrap()->state(fn (KpiRatingFormula $record) => collect($record->bands)->map(fn ($b) => rtrim(rtrim(number_format($b['from_pct'], 2, '.', ''), '0'), '.').($b['to_pct'] === null ? '%+' : '–'.rtrim(rtrim(number_format($b['to_pct'], 2, '.', ''), '0'), '.').'%').' → '.rtrim(rtrim(number_format($b['rating'], 2, '.', ''), '0'), '.'))->implode(' · ')),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])])
            ->recordActions([
                EditAction::make()->using(function (KpiRatingFormula $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageKpiRatingFormulas::route('/')];
    }
}

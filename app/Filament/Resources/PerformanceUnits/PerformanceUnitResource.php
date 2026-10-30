<?php

namespace App\Filament\Resources\PerformanceUnits;

use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\PerformanceUnits\Pages\ManagePerformanceUnits;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity HP-8a (Goal → Unit of measurement): label, code, default target, how progress is calculated,
 * the default unit, to whom it applies (OU mapping) and the KPI rating formula its goals use. The "Added by" filter
 * separates organisation units from units employees added for themselves (Admin / User tabs in HROne).
 */
class PerformanceUnitResource extends Resource
{
    protected static ?string $model = PerformanceUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?int $navigationSort = 65;

    public static function getNavigationLabel(): string
    {
        return PerformanceLabels::plural('uom', 'Units of measurement');
    }

    public static function getModelLabel(): string
    {
        return mb_strtolower(PerformanceLabels::get('uom', 'unit of measurement'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Unit')->columns(3)->schema([
                TextInput::make('name')->label('Label')->required()->maxLength(64),
                TextInput::make('code')->required()->maxLength(24)
                    ->disabled(fn (?PerformanceUnit $record) => $record?->is_system ?? false)->dehydrated()
                    ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                TextInput::make('default_target')->label('Default '.mb_strtolower(PerformanceLabels::get('target')).' value')->numeric(),
                Select::make('measure_type')->label('Progress is calculated as')->options(config('peopleos.performance.measure_types'))->placeholder('None (the goal chooses)')->native(false),
                Select::make('kpi_rating_formula_id')->label('KPI rating formula')->placeholder('None')->native(false)
                    ->options(fn (?PerformanceUnit $record) => KpiRatingFormula::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $record?->kpi_rating_formula_id ?? 0))->orderBy('name')->pluck('name', 'id')->all()),
                Toggle::make('is_default')->label('Default unit (pre-selected on new goals)')->inline(false),
                Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
            ]),
            Section::make('Applies to')->description('Leave empty to offer the unit for everyone\'s goals; otherwise only goals of employees matching every condition use it.')->schema([
                RuleConditionsSchema::repeater()->statePath('applicability')->hiddenLabel(),
            ])->collapsed(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Label')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('default_target')->label('Default value')->placeholder('None'),
                TextColumn::make('measure_type')->label('Progress is calculated as')->placeholder('None')->formatStateUsing(fn (?string $state) => config("peopleos.performance.measure_types.{$state}", $state)),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('applicability')->label('Applies to')->state(fn (PerformanceUnit $record) => empty($record->applicability) ? 'Everyone' : count($record->applicability).' condition(s)'),
                TextColumn::make('is_admin')->label('Added by')->badge()->color('gray')->formatStateUsing(fn ($state, PerformanceUnit $record) => $state ? 'Organisation' : 'Employee: '.($record->creator?->name ?? '—')),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('is_admin')->label('Added by')->options([1 => 'Organisation (admin)', 0 => 'Employees (user)'])])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(function (PerformanceUnit $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make()->visible(fn (PerformanceUnit $record) => ! $record->is_system),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformanceUnits::route('/')];
    }
}

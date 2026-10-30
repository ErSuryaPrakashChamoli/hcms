<?php

namespace App\Filament\Resources\Kras;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Kras\Pages\ManageKras;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * KRA library with suggested KPIs (§35). HROne setup parity: a KRA has a perspective (HP-8a), and each suggested KPI
 * may carry its unit of measurement, the reverse flag and a KPI rating formula (HP-8c), which a goal created from the
 * library takes over. Labels follow the performance terminology.
 */
class KraResource extends Resource
{
    protected static ?string $model = Kra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?int $navigationSort = 62;

    public static function getNavigationLabel(): string
    {
        return PerformanceLabels::get('kra').' library';
    }

    public static function getModelLabel(): string
    {
        return PerformanceLabels::get('kra');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make(PerformanceLabels::get('kra'))->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                TextInput::make('category')->maxLength(64),
                TextInput::make('default_weight')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
                Select::make('perspective_id')->label(PerformanceLabels::get('perspective'))->placeholder('None')->native(false)
                    ->options(fn (?Kra $record) => PerformanceLookup::options('perspective', $record?->perspective_id)),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            Section::make(PerformanceLabels::plural('kpi', 'KPIs'))->columns(2)->schema([
                Repeater::make('kpis')->label('Suggested '.PerformanceLabels::plural('kpi'))->columnSpanFull()->columns(3)->schema([
                    TextInput::make('name')->required(),
                    TextInput::make('measure')->placeholder('e.g. % on-time delivery'),
                    TextInput::make('target')->label(PerformanceLabels::get('target'))->placeholder('e.g. 95'),
                    Select::make('unit_id')->label(PerformanceLabels::get('uom'))->placeholder('None')->native(false)
                        ->options(fn () => PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->orderBy('name')->get()->mapWithKeys(fn (PerformanceUnit $u) => [$u->id => $u->display()])->all()),
                    Select::make('kpi_rating_formula_id')->label('KPI rating formula')->placeholder('None')->native(false)
                        ->options(fn () => KpiRatingFormula::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                    Toggle::make('is_reverse')->label('Lower is better (reverse)')->inline(false)
                        ->visible(fn () => (bool) app(SettingsRepository::class)->get('performance.review.show_reverse_rating', false)),
                ])->default([]),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('perspective'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('category')->placeholder('—'),
                TextColumn::make('perspective.name')->label(PerformanceLabels::get('perspective'))->placeholder('—')->toggleable(),
                TextColumn::make('default_weight')->label('Weight')->suffix('%'),
                TextColumn::make('kpis')->label(PerformanceLabels::plural('kpi'))->state(fn (Kra $record) => count($record->kpis ?? [])),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make()->using(function (Kra $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageKras::route('/')];
    }
}

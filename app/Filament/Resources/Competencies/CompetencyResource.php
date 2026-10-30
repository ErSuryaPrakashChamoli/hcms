<?php

namespace App\Filament\Resources\Competencies;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\Competencies\Pages\ManageCompetencies;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Competency library (§35). */
class CompetencyResource extends Resource
{
    protected static ?string $model = Competency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    /** HP-8a: the organisation's term for competencies (Performance terminology). */
    public static function getNavigationLabel(): string
    {
        return PerformanceLabels::plural('competency', 'Competencies');
    }

    public static function getModelLabel(): string
    {
        return mb_strtolower(PerformanceLabels::get('competency'));
    }

    protected static ?int $navigationSort = 61;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make(PerformanceLabels::get('competency'))->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                Select::make('category')->options(config('peopleos.performance.competency_categories'))->default('core')->required(),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            Section::make('Level and validity')->columns(2)->schema([
                TextInput::make('level')->maxLength(32)->placeholder('e.g. L2, Proficient'),
                TextInput::make('weight')->numeric()->minValue(0)->suffix('%'),
                DatePicker::make('effective_from')->native(false),
                DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            ]),
            Section::make('Description and indicators')->columns(2)->schema([
                Textarea::make('description')->rows(2)->columnSpanFull(),
                TagsInput::make('indicators')->label('Behavioural indicators')->placeholder('Add an indicator')->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.performance.competency_categories.{$state}", $state)),
                TextColumn::make('indicators')->state(fn (Competency $record) => count($record->indicators ?? []))->label('Indicators'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([SelectFilter::make('category')->options(config('peopleos.performance.competency_categories'))])
            ->recordActions([
                EditAction::make()->using(function (Competency $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompetencies::route('/')];
    }
}

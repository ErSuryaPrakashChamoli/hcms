<?php

namespace App\Filament\Resources\PerformanceCycles;

use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\PerformanceCycles\Pages\CreatePerformanceCycle;
use App\Filament\Resources\PerformanceCycles\Pages\EditPerformanceCycle;
use App\Filament\Resources\PerformanceCycles\Pages\ListPerformanceCycles;
use App\Filament\Resources\PerformanceCycles\RelationManagers\AppraisalsRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\PerformanceActions;
use App\Filament\Support\RuleConditionsSchema;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Performance cycles (§35). */
class PerformanceCycleResource extends Resource
{
    protected static ?string $model = PerformanceCycle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Cycles';

    protected static ?int $navigationSort = 10;

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'gray', 'scheduled' => 'info', 'active' => 'success', 'closed' => 'primary', default => 'gray'
        };
    }

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?PerformanceCycle $record) => $record !== null && ! $record->isEditable();

        return $schema->components([
            Section::make('Cycle')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                Select::make('type')->options(config('peopleos.performance.cycle_types'))->default('annual')->required(),
                DatePicker::make('period_start')->native(false)->required()->disabled($locked)->dehydrated(),
                DatePicker::make('period_end')->native(false)->required()->afterOrEqual('period_start')->disabled($locked)->dehydrated()->live(),
                Select::make('rating_scale_id')->label('Rating scale')->required()->disabled($locked)->dehydrated()
                    ->options(fn () => RatingScale::query()->where('status', 'active')->pluck('name', 'id')->all())
                    ->default(fn () => RatingScale::default()?->id),
                TextInput::make('weights.goals')->label('Goals weight')->numeric()->suffix('%')->default(config('peopleos.performance.default_weights.goals'))->required()->disabled($locked)->dehydrated(),
                TextInput::make('weights.competencies')->label('Competencies weight')->numeric()->suffix('%')->default(config('peopleos.performance.default_weights.competencies'))->required()->disabled($locked)->dehydrated(),
                Select::make('competency_ids')->label('Competencies rated')->multiple()->disabled($locked)->dehydrated()
                    ->options(fn () => Competency::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn () => Competency::query()->where('status', 'active')->pluck('id')->all()),
            ]),
            Section::make('Stages')->schema([
                Repeater::make('stages')->hiddenLabel()->columns(4)->reorderable()->minItems(1)
                    ->default(fn (Get $get) => PerformanceCycle::defaultStages(Carbon::parse($get('period_end') ?? now()->endOfYear())))
                    ->schema([
                        Select::make('key')->options(config('peopleos.performance.stages'))->required()->distinct(),
                        TextInput::make('name')->required(),
                        DatePicker::make('starts_on')->native(false),
                        DatePicker::make('ends_on')->native(false),
                    ]),
            ]),
            Section::make('Options')->columns(3)->schema([
                Toggle::make('settings.allow_resubmit')->label('Allow reviewers to resubmit'),
                Toggle::make('settings.show_self_to_manager')->label('Show self review to manager')->default(true),
                Toggle::make('settings.peer_anonymous')->label('Peer reviews anonymous by default'),
            ]),
            Section::make('Eligibility')->description('Leave empty to include every employed person as of the period end.')->schema([
                RuleConditionsSchema::repeater()->statePath('eligibility')->hiddenLabel(),
            ])->collapsed(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.performance.cycle_types.{$state}", $state)),
                TextColumn::make('period_start')->date()->sortable(),
                TextColumn::make('period_end')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('current_stage')->label('Stage')->placeholder('—')->formatStateUsing(fn (?string $state) => config("peopleos.performance.stages.{$state}", $state)),
                TextColumn::make('appraisals_count')->counts('appraisals')->label('Appraisals'),
            ])
            ->defaultSort('period_start', 'desc')
            ->filters([SelectFilter::make('status')->options(PerformanceCycle::STATUSES)])
            ->recordActions([EditAction::make()->visible(fn (PerformanceCycle $record) => $record->isEditable()), ...PerformanceActions::forCycle()]);
    }

    public static function getRelations(): array
    {
        return [AppraisalsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPerformanceCycles::route('/'),
            'create' => CreatePerformanceCycle::route('/create'),
            'edit' => EditPerformanceCycle::route('/{record}/edit'),
        ];
    }
}

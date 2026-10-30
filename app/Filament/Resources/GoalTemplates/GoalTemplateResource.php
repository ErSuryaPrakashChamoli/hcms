<?php

namespace App\Filament\Resources\GoalTemplates;

use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\GoalTemplates\Pages\CreateGoalTemplate;
use App\Filament\Resources\GoalTemplates\Pages\EditGoalTemplate;
use App\Filament\Resources\GoalTemplates\Pages\ListGoalTemplates;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity HP-8e (Goal → Goal template): settings, the KRAs with their KPIs (typed, or added from the KRA
 * library), to whom the system maps the template (HROne's "Map template", rule conditions) and the feedback form the
 * manager answers about the goal sheet. Templates are used by the goal sheet policy (goal creation "system") and by
 * "Use template" on the goals screen.
 */
class GoalTemplateResource extends Resource
{
    protected static ?string $model = GoalTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Goal templates';

    protected static ?int $navigationSort = 63;

    public static function form(Schema $schema): Schema
    {
        $kra = PerformanceLabels::get('kra');
        $kpi = PerformanceLabels::get('kpi');
        $units = fn () => PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->orderBy('name')->pluck('name', 'id')->all();
        $measure = Select::make('measure_type')->label('Measure')->options(config('peopleos.performance.measure_types'))->default('percentage')->required()->native(false);

        return $schema->components([
            Section::make('Settings')->columns(3)->schema([
                TextInput::make('name')->label('Template name')->required()->maxLength(120)->columnSpan(2),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                Select::make('status')->options(GoalTemplate::STATUSES)->default('active')->required()->native(false),
                Select::make('feedback_template_id')->label(PerformanceLabels::get('feedback').' template')->placeholder('None')->native(false)->columnSpan(2)
                    ->options(fn () => FeedbackTemplates::options('goal_review'))
                    ->helperText('Asked of the manager with the manager review for goal sheets made from this template (Forms & templates → Feedback templates, "Goal sheet feedback by the manager").'),
            ]),
            Section::make($kra.'s and '.$kpi.'s')->description('Each '.$kra.' becomes a goal; its '.$kpi.'s become the goal\'s key results.')->schema([
                Repeater::make('goals')->relationship()->hiddenLabel()->columns(4)->reorderable()->orderColumn('sort_order')->collapsible()->defaultItems(0)
                    ->itemLabel(fn (array $state) => $state['title'] ?? null)
                    ->schema([
                        Select::make('kra_id')->label('From the '.$kra.' library')->placeholder('—')->native(false)->live()
                            ->options(fn () => Kra::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state && ($library = Kra::query()->find($state))) {
                                    $set('title', $library->name);
                                    $set('perspective_id', $library->perspective_id);
                                }
                            }),
                        TextInput::make('title')->label($kra)->required()->maxLength(255)->columnSpan(2),
                        TextInput::make('weight')->label('Weightage')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
                        $measure,
                        Select::make('unit_id')->label(PerformanceLabels::get('uom'))->placeholder('None')->native(false)->options($units),
                        TextInput::make('start_value')->numeric()->default(0),
                        TextInput::make('target_value')->label(PerformanceLabels::get('target'))->numeric(),
                        Select::make('perspective_id')->label(PerformanceLabels::get('perspective'))->placeholder('None')->native(false)
                            ->options(fn () => PerformanceLookup::options('perspective')),
                        Select::make('kpi_rating_formula_id')->label($kpi.' rating formula')->placeholder('None')->native(false)
                            ->options(fn () => KpiRatingFormula::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                        Toggle::make('is_reverse')->label('Lower is better')->inline(false),
                        Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                        Repeater::make('kpis')->relationship()->label($kpi.'s')->columns(4)->reorderable()->orderColumn('sort_order')->defaultItems(0)->columnSpanFull()
                            ->schema([
                                TextInput::make('title')->label($kpi)->required()->maxLength(255)->columnSpan(2),
                                TextInput::make('weight')->label('Weightage')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
                                Select::make('measure_type')->label('Measure')->options(config('peopleos.performance.measure_types'))->default('percentage')->required()->native(false),
                                Select::make('unit_id')->label(PerformanceLabels::get('uom'))->placeholder('None')->native(false)->options($units),
                                TextInput::make('start_value')->numeric()->default(0),
                                TextInput::make('target_value')->label(PerformanceLabels::get('target'))->numeric(),
                                Toggle::make('is_reverse')->label('Lower is better')->inline(false),
                            ]),
                    ]),
            ]),
            Section::make('Map template')->description('To whom the system maps this template when a goal sheet policy creates goals by the system without naming a template. Leave empty for everyone.')->schema([
                RuleConditionsSchema::repeater()->statePath('applicability')->defaultItems(0)->minItems(0)->hiddenLabel(),
            ])->collapsed(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('goals')->with('feedbackTemplate'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('goals_count')->label(PerformanceLabels::plural('kra', 'KRAs')),
                TextColumn::make('applicability')->label('Mapped to')->state(fn (GoalTemplate $record) => empty($record->applicability) ? 'Everyone' : count($record->applicability).' condition(s)'),
                TextColumn::make('feedbackTemplate.name')->label(PerformanceLabels::get('feedback'))->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray')->formatStateUsing(fn (string $state) => GoalTemplate::STATUSES[$state] ?? $state),
            ])
            ->filters([SelectFilter::make('status')->options(GoalTemplate::STATUSES)])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoalTemplates::route('/'),
            'create' => CreateGoalTemplate::route('/create'),
            'edit' => EditGoalTemplate::route('/{record}/edit'),
        ];
    }
}

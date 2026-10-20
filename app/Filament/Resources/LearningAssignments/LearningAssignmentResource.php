<?php

namespace App\Filament\Resources\LearningAssignments;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Services\Learning;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Filament\Resources\LearningAssignments\Pages\ManageLearningAssignments;
use App\Filament\Support\LearningActions;
use App\Filament\Support\RuleConditionsSchema;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Who must learn what, by when, how often (§37 mandatory compliance training). */
class LearningAssignmentResource extends Resource
{
    protected static ?string $model = LearningAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Assignments';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('learning.assign') || auth()->user()?->can('learning.manage') || auth()->user()?->can('learning.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['course', 'path', 'employee.person'])->withCount('enrolments');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('course_id')->label('Course')->searchable()->options(fn () => Course::query()->whereIn('status', Course::ENROLLABLE)->orderBy('title')->pluck('title', 'id')->all())->requiredWithout('learning_path_id')->live(),
                Select::make('learning_path_id')->label('or learning path')->options(fn () => LearningPath::query()->where('status', 'active')->pluck('name', 'id')->all())->visible(fn (Get $get) => blank($get('course_id'))),
            ]),
            Section::make('Who')->description('Managers assign to employees and teams they manage; organisation units and rule-defined populations need learning.manage. Conditions narrow any target.')->columns(2)->schema([
                Select::make('target_type')->label('Target')->options(LearningAssignment::TARGETS)->default('employee')->required()->live(),
                Select::make('employee_id')->label('Employee')->searchable()->options(fn () => LearningActions::peopleOptions())
                    ->visible(fn (Get $get) => $get('target_type') === 'employee')->required(fn (Get $get) => $get('target_type') === 'employee'),
                Select::make('target_id')->label(fn (Get $get) => $get('target_type') === 'team' ? 'Manager (their team)' : 'Organisation unit')->searchable()
                    ->visible(fn (Get $get) => in_array($get('target_type'), ['team', 'organisation_unit'], true))->required(fn (Get $get) => in_array($get('target_type'), ['team', 'organisation_unit'], true))
                    ->options(fn (Get $get) => $get('target_type') === 'team'
                        ? (auth()->user()->can('learning.manage') ? LearningActions::allPeopleOptions() : array_filter([LearningActions::me()?->id => 'My team']))
                        : OrganisationNode::query()->with('nodeable')->get()->mapWithKeys(fn ($n) => [$n->id => $n->auditLabel()])->all()),
                Toggle::make('auto_enrol_new_joiners')->label('Keep enrolling people who match later (daily)')->default(true)->visible(fn (Get $get) => $get('target_type') !== 'employee'),
                RuleConditionsSchema::repeater()->columnSpanFull()->visible(fn (Get $get) => $get('target_type') !== 'employee'),
            ]),
            Section::make('When and why')->columns(4)->schema([
                TextInput::make('due_days')->label('Due within')->numeric()->minValue(1)->default(30)->suffix('days')->required(),
                TextInput::make('recur_months')->label('Repeat every')->numeric()->minValue(1)->suffix('months')->placeholder('Once'),
                Toggle::make('is_mandatory')->label('Mandatory')->inline(false),
                Toggle::make('is_required')->label('Required (not optional)')->default(true)->inline(false),
                Select::make('priority')->options(config('peopleos.learning.priorities'))->default('normal')->required(),
                DatePicker::make('effective_from')->native(false),
                DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                Select::make('status')->options(['active' => 'Active', 'paused' => 'Paused'])->default('active')->required(),
                Textarea::make('reason')->maxLength(500)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('target')->label('Learning')->state(fn (LearningAssignment $record) => $record->course?->title ?? ('Path: '.$record->path?->name)),
                TextColumn::make('audience')->label('Who')->state(fn (LearningAssignment $record) => match ($record->target_type) {
                    'employee' => $record->employee?->person?->full_name,
                    'team' => 'Team of #'.$record->target_id,
                    'organisation_unit' => OrganisationNode::query()->find($record->target_id)?->auditLabel(),
                    default => empty($record->conditions) ? 'Everyone' : RuleConditionsSchema::describe($record->conditions),
                })->wrap(),
                TextColumn::make('priority')->badge()->color('gray'),
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('due_days')->label('Due in')->suffix(' d'),
                TextColumn::make('recur_months')->label('Repeat')->suffix(' mo')->placeholder('Once'),
                IconColumn::make('is_mandatory')->label('Mandatory')->boolean(),
                TextColumn::make('enrolments_count')->label('Enrolments'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success', 'cancelled' => 'danger', default => 'gray'
                }),
            ])
            ->recordActions([
                Action::make('apply')->label('Apply now')->icon('heroicon-m-play')->color('primary')
                    ->visible(fn (LearningAssignment $record) => $record->status === 'active' && auth()->user()->can('learning.manage'))
                    ->requiresConfirmation()
                    ->action(fn (LearningAssignment $record) => LearningActions::run(fn () => app(Learning::class)->applyAssignment($record, auth()->user())->count(), fn ($n) => "{$n} employee(s) enrolled")),
                EditAction::make()->visible(fn (LearningAssignment $record) => $record->status !== 'cancelled' && auth()->user()->can('learning.manage')),
                Action::make('cancel')->label('Cancel')->icon('heroicon-m-no-symbol')->color('danger')
                    ->visible(fn (LearningAssignment $record) => $record->status !== 'cancelled' && (auth()->user()->can('learning.manage') || (int) $record->created_by === (int) auth()->id()))
                    ->modalDescription('Enrolments from this assignment that have not started are cancelled with it; started learning continues.')
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (LearningAssignment $record, array $data) => LearningActions::run(fn () => app(Learning::class)->cancelAssignment($record, $data['reason'], auth()->user()), fn ($n) => "Cancelled ({$n} enrolment(s))")),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLearningAssignments::route('/')];
    }
}

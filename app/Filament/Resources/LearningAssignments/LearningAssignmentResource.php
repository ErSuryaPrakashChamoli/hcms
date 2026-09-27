<?php

namespace App\Filament\Resources\LearningAssignments;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Services\Learning;
use App\Filament\Resources\LearningAssignments\Pages\ManageLearningAssignments;
use App\Filament\Support\LearningActions;
use App\Filament\Support\RuleConditionsSchema;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
                Select::make('course_id')->label('Course')->searchable()->options(fn () => Course::query()->where('status', 'published')->orderBy('title')->pluck('title', 'id')->all())->requiredWithout('learning_path_id')->live(),
                Select::make('learning_path_id')->label('or learning path')->options(fn () => LearningPath::query()->where('status', 'active')->pluck('name', 'id')->all())->visible(fn (Get $get) => blank($get('course_id'))),
            ]),
            Section::make('Who')->description('Pick one employee, or leave empty and use conditions for a group (empty conditions = everyone employed).')->columns(2)->schema([
                Select::make('employee_id')->label('One employee')->searchable()->placeholder('Everyone matching the conditions')->options(fn () => LearningActions::peopleOptions())->live(),
                Toggle::make('auto_enrol_new_joiners')->label('Keep enrolling people who match later (daily)')->default(true)->visible(fn (Get $get) => blank($get('employee_id'))),
                RuleConditionsSchema::repeater()->columnSpanFull()->visible(fn (Get $get) => blank($get('employee_id'))),
            ]),
            Section::make('When')->columns(4)->schema([
                TextInput::make('due_days')->label('Due within')->numeric()->minValue(1)->default(30)->suffix('days')->required(),
                TextInput::make('recur_months')->label('Repeat every')->numeric()->minValue(1)->suffix('months')->placeholder('Once'),
                Toggle::make('is_mandatory')->label('Mandatory')->inline(false),
                Select::make('status')->options(['active' => 'Active', 'paused' => 'Paused'])->default('active')->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('target')->label('Learning')->state(fn (LearningAssignment $record) => $record->course?->title ?? ('Path: '.$record->path?->name)),
                TextColumn::make('audience')->label('Who')->state(fn (LearningAssignment $record) => $record->employee ? $record->employee->person?->full_name : (empty($record->conditions) ? 'Everyone' : RuleConditionsSchema::describe($record->conditions)))->wrap(),
                TextColumn::make('due_days')->label('Due in')->suffix(' d'),
                TextColumn::make('recur_months')->label('Repeat')->suffix(' mo')->placeholder('Once'),
                IconColumn::make('is_mandatory')->label('Mandatory')->boolean(),
                TextColumn::make('enrolments_count')->label('Enrolments'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('apply')->label('Apply now')->icon('heroicon-m-play')->color('primary')
                    ->visible(fn (LearningAssignment $record) => $record->status === 'active' && (auth()->user()->can('learning.assign') || auth()->user()->can('learning.manage')))
                    ->requiresConfirmation()
                    ->action(fn (LearningAssignment $record) => LearningActions::run(fn () => app(Learning::class)->applyAssignment($record, auth()->user())->count(), fn ($n) => "{$n} employee(s) enrolled")),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLearningAssignments::route('/')];
    }
}

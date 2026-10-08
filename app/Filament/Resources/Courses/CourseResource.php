<?php

namespace App\Filament\Resources\Courses;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningInstructor;
use App\Domain\Learning\Models\LearningProvider;
use App\Domain\People\Models\Skill;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Courses\Pages\CreateCourse;
use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\Courses\RelationManagers\ModulesRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CourseLifecycleActions;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Course catalogue (§37). */
class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Courses';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return auth()->user()->can('learning.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Course')->columns(3)->schema([
                TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                Select::make('type')->options(config('peopleos.learning.course_types'))->default('elearning')->required(),
                Select::make('category')->options(config('peopleos.learning.categories'))->default('other')->required(),
                TextInput::make('duration_minutes')->numeric()->minValue(0)->suffix('min'),
                TextInput::make('content_url')->url()->maxLength(255)->columnSpan(2)->placeholder('External LMS / video link'),
                Select::make('owner_id')->label('Owner')->searchable()->placeholder('—')->options(fn () => LearningActions::peopleOptions()),
                Textarea::make('description')->rows(3)->columnSpanFull(),
            ]),
            Section::make('Catalogue')->columns(4)->schema([
                TextInput::make('topic')->maxLength(64),
                Select::make('difficulty')->options(config('peopleos.learning.difficulties')),
                Select::make('delivery_mode')->options(config('peopleos.learning.delivery_modes')),
                Select::make('language')->options(config('peopleos.learning.languages')),
                Select::make('learning_provider_id')->label('Provider')->options(fn () => LearningProvider::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('learning_instructor_id')->label('Instructor')->options(fn () => LearningInstructor::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('cost')->numeric()->minValue(0)->visible(fn () => auth()->user()->can('learning.costs')),
                TextInput::make('currency')->maxLength(3)->placeholder('INR')->visible(fn () => auth()->user()->can('learning.costs')),
                DatePicker::make('effective_from')->native(false),
                DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                Select::make('prerequisite_course_ids')->label('Prerequisites')->multiple()->columnSpan(2)
                    ->options(fn (?Course $record) => Course::query()->when($record, fn ($q) => $q->whereKeyNot($record->id))->orderBy('title')->pluck('title', 'id')->all()),
            ]),
            Section::make('Rules')->columns(4)->schema([
                Toggle::make('is_mandatory')->label('Mandatory (compliance)'),
                Toggle::make('allow_self_enrol')->label('Employees may self-enrol'),
                Toggle::make('requires_approval')->label('Requests need approval'),
                TextInput::make('approval_workflow_key')->label('Approval workflow key')->maxLength(64)->placeholder('Manager / L&D decides directly'),
                TextInput::make('validity_months')->label('Certificate valid for')->numeric()->minValue(1)->suffix('months')->placeholder('No expiry'),
                TextInput::make('passing_score')->numeric()->minValue(0)->maxValue(100)->suffix('%')->placeholder('Per assessment'),
                TextInput::make('attempts_allowed')->numeric()->minValue(1)->default(3),
                TextEntry::make('lifecycle')->label('Status')->state(fn (?Course $record) => Course::STATUSES[$record?->status ?? 'draft'] ?? '—')
                    ->helperText('Changed through Submit → Approve → Publish / Retire actions; publishing creates a new immutable version.'),
            ]),
            Section::make('Skill outcomes')->description('Skill levels a completion evidences (recorded as verified, from learning).')->schema([
                Repeater::make('skill_outcomes')->hiddenLabel()->columns(2)->default([])->schema([
                    Select::make('skill_id')->label('Skill')->required()->searchable()->options(fn () => Skill::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('level')->numeric()->required()->helperText('A value on the skill\'s scale'),
                ]),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->description(fn (Course $record) => $record->code),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.learning.course_types.{$state}", $state)),
                TextColumn::make('category')->badge()->color('info')->formatStateUsing(fn (string $state) => config("peopleos.learning.categories.{$state}", $state)),
                TextColumn::make('duration_minutes')->label('Duration')->suffix(' min')->placeholder('—'),
                IconColumn::make('is_mandatory')->label('Mandatory')->boolean(),
                TextColumn::make('validity_months')->label('Valid')->suffix(' mo')->placeholder('—'),
                TextColumn::make('modules_count')->counts('modules')->label('Modules'),
                TextColumn::make('enrolments_count')->counts('enrolments')->label('Enrolled'),
                TextColumn::make('currentVersion.version')->label('Version')->prefix('v')->placeholder('—'),
                TextColumn::make('delivery_mode')->label('Mode')->badge()->color('gray')->placeholder('—')->formatStateUsing(fn (?string $state) => config("peopleos.learning.delivery_modes.{$state}", $state))->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Course::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'published', 'active' => 'success', 'pending_approval', 'approved', 'scheduled' => 'warning', 'retired', 'archived' => 'gray', default => 'info'
                }),
            ])
            ->filters([SelectFilter::make('category')->options(config('peopleos.learning.categories')), SelectFilter::make('status')->options(Course::STATUSES)])
            ->recordActions([EditAction::make()->visible(fn (Course $record) => auth()->user()->can('learning.manage') && $record->status !== 'archived'), ...CourseLifecycleActions::all()]);
    }

    public static function getRelations(): array
    {
        return [ModulesRelationManager::class, AssessmentsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'create' => CreateCourse::route('/create'),
            'edit' => EditCourse::route('/{record}/edit'),
        ];
    }
}

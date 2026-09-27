<?php

namespace App\Filament\Resources\Courses;

use App\Domain\Learning\Models\Course;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Courses\Pages\CreateCourse;
use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\Courses\RelationManagers\ModulesRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
            Section::make('Rules')->columns(4)->schema([
                Toggle::make('is_mandatory')->label('Mandatory (compliance)'),
                TextInput::make('validity_months')->label('Certificate valid for')->numeric()->minValue(1)->suffix('months')->placeholder('No expiry'),
                TextInput::make('passing_score')->numeric()->minValue(0)->maxValue(100)->suffix('%')->placeholder('Per assessment'),
                TextInput::make('attempts_allowed')->numeric()->minValue(1)->default(3),
                Select::make('status')->options(Course::STATUSES)->default('draft')->required(),
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
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'published' => 'success', 'retired' => 'gray', default => 'warning'
                }),
            ])
            ->filters([SelectFilter::make('category')->options(config('peopleos.learning.categories')), SelectFilter::make('status')->options(Course::STATUSES)])
            ->recordActions([EditAction::make()->visible(fn () => auth()->user()->can('learning.manage'))]);
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

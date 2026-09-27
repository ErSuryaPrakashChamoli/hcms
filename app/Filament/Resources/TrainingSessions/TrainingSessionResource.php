<?php

namespace App\Filament\Resources\TrainingSessions;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Services\TrainingSessions;
use App\Filament\Resources\TrainingSessions\Pages\CreateTrainingSession;
use App\Filament\Resources\TrainingSessions\Pages\EditTrainingSession;
use App\Filament\Resources\TrainingSessions\Pages\ListTrainingSessions;
use App\Filament\Resources\TrainingSessions\RelationManagers\AttendeesRelationManager;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Classroom and virtual sessions (§37). Employees register themselves. */
class TrainingSessionResource extends Resource
{
    protected static ?string $model = TrainingSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Training sessions';

    protected static ?int $navigationSort = 12;

    public static function canCreate(): bool
    {
        return auth()->user()->can('learning.manage') || auth()->user()->can('learning.assign');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['course', 'trainer.person'])->withCount('attendees');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('course_id')->label('Course')->required()->searchable()->options(fn () => Course::query()->where('status', 'published')->whereIn('type', ['classroom', 'virtual'])->orderBy('title')->pluck('title', 'id')->all()),
            TextInput::make('title')->required()->maxLength(255),
            Select::make('mode')->options(['classroom' => 'Classroom', 'virtual' => 'Virtual'])->default('classroom')->required()->live(),
            Select::make('trainer_id')->label('Internal trainer')->searchable()->placeholder('—')->options(fn () => LearningActions::peopleOptions()),
            TextInput::make('trainer_name')->label('External trainer')->maxLength(255),
            TextInput::make('capacity')->numeric()->minValue(1)->placeholder('Unlimited'),
            DateTimePicker::make('starts_at')->native(false)->required()->default(now()->addWeek()->setTime(10, 0)),
            DateTimePicker::make('ends_at')->native(false)->required()->default(now()->addWeek()->setTime(13, 0))->after('starts_at'),
            TextInput::make('venue')->maxLength(255)->visible(fn (Get $get) => $get('mode') === 'classroom'),
            TextInput::make('meeting_url')->url()->maxLength(255)->visible(fn (Get $get) => $get('mode') === 'virtual'),
            Select::make('status')->options(config('peopleos.learning.session_statuses'))->default('scheduled')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('title')->searchable()->description(fn (TrainingSession $record) => $record->course->title),
                TextColumn::make('mode')->badge()->color('gray'),
                TextColumn::make('trainer')->label('Trainer')->state(fn (TrainingSession $record) => $record->trainer?->person?->full_name ?? $record->trainer_name)->placeholder('—'),
                TextColumn::make('venue')->state(fn (TrainingSession $record) => $record->venue ?? $record->meeting_url)->limit(40)->placeholder('—'),
                TextColumn::make('attendees_count')->label('Registered')->formatStateUsing(fn ($state, TrainingSession $record) => $record->capacity ? "{$state} / {$record->capacity}" : $state),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'completed' => 'success', 'cancelled' => 'danger', default => 'info'
                }),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.learning.session_statuses'))])
            ->recordActions([
                Action::make('register')->label('Register me')->icon('heroicon-m-user-plus')->color('primary')
                    ->visible(fn (TrainingSession $record) => $record->status === 'scheduled' && LearningActions::me() !== null && $record->attendees()->where('employee_id', LearningActions::me()->id)->whereIn('status', ['registered', 'attended'])->doesntExist())
                    ->action(fn (TrainingSession $record) => LearningActions::run(fn () => app(TrainingSessions::class)->register($record, LearningActions::me(), auth()->user()), 'Registered')),
                EditAction::make()->visible(fn () => self::canCreate()),
            ]);
    }

    public static function getRelations(): array
    {
        return [AttendeesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingSessions::route('/'),
            'create' => CreateTrainingSession::route('/create'),
            'edit' => EditTrainingSession::route('/{record}/edit'),
        ];
    }
}

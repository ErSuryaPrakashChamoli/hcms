<?php

namespace App\Filament\Resources\TrainingSessions\RelationManagers;

use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\TrainingSessionAttendee;
use App\Domain\Learning\Services\TrainingSessions;
use App\Filament\Support\LearningActions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class AttendeesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendees';

    protected static ?string $title = 'Attendees';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('learning.assign') || auth()->user()?->can('learning.manage');
    }

    public function table(Table $table): Table
    {
        $session = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'enrolment']))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code'),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'attended' => 'success', 'absent' => 'danger', 'cancelled' => 'gray', default => 'info'
                })->formatStateUsing(fn (string $state) => config("peopleos.learning.attendee_statuses.{$state}", $state)),
                TextColumn::make('enrolment.status')->label('Enrolment')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('rating')->placeholder('—'),
            ])
            ->headerActions([
                Action::make('add')->label('Register employee')->icon('heroicon-m-user-plus')
                    ->schema([Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => LearningActions::peopleOptions())])
                    ->action(fn (array $data) => LearningActions::run(fn () => app(TrainingSessions::class)->register($session, Employee::query()->findOrFail($data['employee_id']), auth()->user()), 'Registered')),
            ])
            ->recordActions([
                Action::make('attended')->label('Attended')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (TrainingSessionAttendee $record) => $record->status === 'registered')
                    ->action(fn (TrainingSessionAttendee $record) => LearningActions::run(fn () => app(TrainingSessions::class)->markAttendance($session, [$record->employee_id => 'attended'], auth()->user()), 'Marked attended')),
                Action::make('absent')->label('Absent')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (TrainingSessionAttendee $record) => $record->status === 'registered')
                    ->action(fn (TrainingSessionAttendee $record) => LearningActions::run(fn () => app(TrainingSessions::class)->markAttendance($session, [$record->employee_id => 'absent'], auth()->user()), 'Marked absent')),
            ])
            ->toolbarActions([
                BulkAction::make('markAttended')->label('Mark attended')->icon('heroicon-m-check')
                    ->action(fn (Collection $records) => LearningActions::run(fn () => app(TrainingSessions::class)->markAttendance($session, $records->mapWithKeys(fn ($r) => [$r->employee_id => 'attended'])->all(), auth()->user()), fn ($n) => "{$n} marked attended")),
            ]);
    }
}

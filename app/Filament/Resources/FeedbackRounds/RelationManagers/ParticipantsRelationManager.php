<?php

namespace App\Filament\Resources\FeedbackRounds\RelationManagers;

use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Services\FeedbackRoundResults;
use App\Filament\Support\Feedback360Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** HROne setup parity HP-8h: the employees of a 360 round, their nomination sets and who answered (never what). */
class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $title = 'Employees and nominations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return FeedbackRoundResults::isHr(auth()->user());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'nominatorUser', 'nominatorRole', 'round']))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code'),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('nominator')->label('Nominated by')->state(fn (FeedbackParticipant $record) => $record->nominatorUser?->name ?? ($record->nominatorRole ? 'Role: '.$record->nominatorRole->name : '—')),
                TextColumn::make('nomination_status')->label('Nominations')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_360.participant_statuses.{$state}", $state)),
                TextColumn::make('answered')->label('Answered / asked')->state(fn (FeedbackParticipant $record) => Feedback360Actions::statusText($record))->wrap(),
            ])
            ->filters([SelectFilter::make('nomination_status')->label('Nominations')->options(config('peopleos.performance.feedback_360.participant_statuses'))])
            ->recordActions(Feedback360Actions::forParticipant());
    }
}

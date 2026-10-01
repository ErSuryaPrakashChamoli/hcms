<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Learning. */
class LearningRelationManager extends RelationManager
{
    protected static string $relationship = 'learningEnrolments';

    protected static ?string $title = 'Learning';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new LearningEnrolment(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['course', 'courseVersion']))
            ->description(sprintf('Completed %d · in progress %d · overdue %d · certificates valid %d · learning hours %.1f',
                $employee->learningEnrolments()->where('status', 'completed')->count(),
                $employee->learningEnrolments()->whereIn('status', ['assigned', 'enrolled', 'approved', 'in_progress'])->count(),
                $employee->learningEnrolments()->where('status', 'overdue')->count(),
                $employee->learningCertificates()->whereIn('status', ['valid', 'expiring'])->count(),
                (float) LearningCompletion::query()->where('employee_id', $employee->id)->where('status', 'final')->sum('hours')))
            ->columns([
                TextColumn::make('course.title')->label('Course'),
                TextColumn::make('courseVersion.version')->label('Version')->prefix('v')->placeholder('—'),
                TextColumn::make('course.category')->label('Category')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => LearningEnrolmentResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.learning.enrolment_statuses.{$state}", $state)),
                TextColumn::make('progress')->suffix('%'),
                TextColumn::make('due_on')->date()->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
                TextColumn::make('expires_on')->date()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([Action::make('open')->label('Open')->url(fn (LearningEnrolment $record) => LearningEnrolmentResource::getUrl('view', ['record' => $record]))]);
    }
}

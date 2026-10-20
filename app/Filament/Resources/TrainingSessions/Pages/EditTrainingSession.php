<?php

namespace App\Filament\Resources\TrainingSessions\Pages;

use App\Domain\Learning\Services\TrainingSessions;
use App\Filament\Resources\TrainingSessions\TrainingSessionResource;
use App\Filament\Support\LearningActions;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\Action;

class EditTrainingSession extends PeopleEditRecord
{
    protected static string $resource = TrainingSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')->label('Mark session completed')->icon('heroicon-m-check-circle')->color('success')
                ->visible(fn () => $this->getRecord()->status === 'scheduled')
                ->requiresConfirmation()->modalDescription('Record attendance on the Attendees tab first; attending completes each learner\'s enrolment.')
                ->action(fn () => LearningActions::run(fn () => app(TrainingSessions::class)->close($this->getRecord(), auth()->user()), 'Session completed')),
        ];
    }
}

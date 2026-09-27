<?php

namespace App\Filament\Resources\LearningEnrolments\Pages;

use App\Domain\Learning\Policies\EnrolmentPolicy;
use App\Domain\Learning\Services\Learning;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Support\LearningActions;
use Filament\Resources\Pages\ViewRecord;

class ViewLearningEnrolment extends ViewRecord
{
    protected static string $resource = LearningEnrolmentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $enrolment = $this->getRecord();
        if ($enrolment->status === 'enrolled' && EnrolmentPolicy::isOwn(auth()->user(), $enrolment)) {
            app(Learning::class)->start($enrolment); // opening it counts as starting
        }
    }

    protected function getHeaderActions(): array
    {
        return LearningActions::forEnrolment();
    }
}

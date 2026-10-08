<?php

namespace App\Filament\Resources\LearningAssignments\Pages;

use App\Domain\Learning\Services\Learning;
use App\Filament\Resources\LearningAssignments\LearningAssignmentResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageLearningAssignments extends PeopleManageRecords
{
    protected static string $resource = LearningAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Phase 8: created and applied through Learning::assign — scope checks, one audited bulk operation.
            CreateAction::make()->label('Assign learning')
                ->using(fn (array $data) => app(Learning::class)->assign($data, auth()->user())),
        ];
    }
}

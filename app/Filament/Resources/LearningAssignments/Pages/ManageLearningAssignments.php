<?php

namespace App\Filament\Resources\LearningAssignments\Pages;

use App\Domain\Learning\Services\Learning;
use App\Filament\Resources\LearningAssignments\LearningAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLearningAssignments extends ManageRecords
{
    protected static string $resource = LearningAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])
                ->after(fn ($record) => app(Learning::class)->applyAssignment($record, auth()->user())),
        ];
    }
}

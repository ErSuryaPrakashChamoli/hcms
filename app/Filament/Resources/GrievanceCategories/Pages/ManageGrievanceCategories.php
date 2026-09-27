<?php

namespace App\Filament\Resources\GrievanceCategories\Pages;

use App\Filament\Resources\GrievanceCategories\GrievanceCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGrievanceCategories extends ManageRecords
{
    protected static string $resource = GrievanceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

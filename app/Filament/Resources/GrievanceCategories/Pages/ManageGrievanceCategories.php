<?php

namespace App\Filament\Resources\GrievanceCategories\Pages;

use App\Filament\Resources\GrievanceCategories\GrievanceCategoryResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageGrievanceCategories extends PeopleManageRecords
{
    protected static string $resource = GrievanceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

namespace App\Filament\Resources\Competencies\Pages;

use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageCompetencies extends PeopleManageRecords
{
    protected static string $resource = CompetencyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

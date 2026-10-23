<?php

namespace App\Filament\Resources\LetterTemplates\Pages;

use App\Domain\Letters\Models\LetterTemplate;
use App\Filament\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Support\LetterVariablesUi;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageLetterTemplates extends PeopleManageRecords
{
    protected static string $resource = LetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LetterVariablesUi::guideAction(),
            CreateAction::make()->using(fn (array $data) => LetterTemplateResource::save(new LetterTemplate, $data)),
        ];
    }
}

<?php

namespace App\Filament\Resources\LetterTemplates\Pages;

use App\Filament\Resources\LetterTemplates\LetterTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLetterTemplates extends ManageRecords
{
    protected static string $resource = LetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

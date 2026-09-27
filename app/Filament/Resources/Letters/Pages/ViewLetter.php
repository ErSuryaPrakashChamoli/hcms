<?php

namespace App\Filament\Resources\Letters\Pages;

use App\Filament\Resources\Letters\LetterResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLetter extends ViewRecord
{
    protected static string $resource = LetterResource::class;

    protected function getHeaderActions(): array
    {
        return LetterResource::forLetter();
    }
}

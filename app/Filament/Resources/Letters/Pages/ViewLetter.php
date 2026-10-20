<?php

namespace App\Filament\Resources\Letters\Pages;

use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewLetter extends PeopleViewRecord
{
    protected static string $resource = LetterResource::class;

    protected function getHeaderActions(): array
    {
        return LetterResource::forLetter();
    }
}

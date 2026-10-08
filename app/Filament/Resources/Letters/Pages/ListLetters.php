<?php

namespace App\Filament\Resources\Letters\Pages;

use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListLetters extends PeopleListRecords
{
    protected static string $resource = LetterResource::class;

    protected function getHeaderActions(): array
    {
        return [LetterResource::generate()];
    }
}

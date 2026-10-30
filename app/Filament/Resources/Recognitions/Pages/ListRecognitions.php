<?php

namespace App\Filament\Resources\Recognitions\Pages;

use App\Filament\Resources\Recognitions\RecognitionResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RecognitionActions;

class ListRecognitions extends PeopleListRecords
{
    protected static string $resource = RecognitionResource::class;

    protected function getHeaderActions(): array
    {
        return [RecognitionActions::give()];
    }
}

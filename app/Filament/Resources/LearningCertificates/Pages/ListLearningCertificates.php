<?php

namespace App\Filament\Resources\LearningCertificates\Pages;

use App\Filament\Resources\LearningCertificates\LearningCertificateResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListLearningCertificates extends PeopleListRecords
{
    protected static string $resource = LearningCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [LearningCertificateResource::recordExternal()];
    }
}

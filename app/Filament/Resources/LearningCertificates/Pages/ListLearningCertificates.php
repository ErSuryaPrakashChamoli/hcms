<?php

namespace App\Filament\Resources\LearningCertificates\Pages;

use App\Filament\Resources\LearningCertificates\LearningCertificateResource;
use Filament\Resources\Pages\ListRecords;

class ListLearningCertificates extends ListRecords
{
    protected static string $resource = LearningCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [LearningCertificateResource::recordExternal()];
    }
}

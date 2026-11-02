<?php

namespace App\Filament\Resources\DataUploads\Pages;

use App\Filament\Resources\DataUploads\DataUploadResource;
use App\Filament\Resources\TimeOfficeImports\Pages\ListTimeOfficeImports;

class ListDataUploads extends ListTimeOfficeImports
{
    protected static string $resource = DataUploadResource::class;
}

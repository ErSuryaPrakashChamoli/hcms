<?php

namespace App\Filament\Resources\WorkforceUploads\Pages;

use App\Filament\Resources\TimeOfficeImports\Pages\ListTimeOfficeImports;
use App\Filament\Resources\WorkforceUploads\WorkforceUploadResource;

class ListWorkforceUploads extends ListTimeOfficeImports
{
    protected static string $resource = WorkforceUploadResource::class;
}

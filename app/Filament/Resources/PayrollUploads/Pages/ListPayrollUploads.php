<?php

namespace App\Filament\Resources\PayrollUploads\Pages;

use App\Filament\Resources\PayrollUploads\PayrollUploadResource;
use App\Filament\Resources\TimeOfficeImports\Pages\ListTimeOfficeImports;

class ListPayrollUploads extends ListTimeOfficeImports
{
    protected static string $resource = PayrollUploadResource::class;
}

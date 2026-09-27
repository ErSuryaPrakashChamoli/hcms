<?php

namespace App\Filament\Resources\Appraisals\Pages;

use App\Filament\Resources\Appraisals\AppraisalResource;
use Filament\Resources\Pages\ListRecords;

class ListAppraisals extends ListRecords
{
    protected static string $resource = AppraisalResource::class;
}

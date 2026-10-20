<?php

namespace App\Filament\Resources\Appraisals\Pages;

use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListAppraisals extends PeopleListRecords
{
    protected static string $resource = AppraisalResource::class;
}

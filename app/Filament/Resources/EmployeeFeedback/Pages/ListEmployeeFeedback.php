<?php

namespace App\Filament\Resources\EmployeeFeedback\Pages;

use App\Filament\Resources\EmployeeFeedback\EmployeeFeedbackResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListEmployeeFeedback extends PeopleListRecords
{
    protected static string $resource = EmployeeFeedbackResource::class;
}

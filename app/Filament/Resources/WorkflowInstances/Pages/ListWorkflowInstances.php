<?php

namespace App\Filament\Resources\WorkflowInstances\Pages;

use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListWorkflowInstances extends PeopleListRecords
{
    protected static string $resource = WorkflowInstanceResource::class;
}

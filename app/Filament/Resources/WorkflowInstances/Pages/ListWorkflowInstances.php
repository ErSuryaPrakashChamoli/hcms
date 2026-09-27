<?php

namespace App\Filament\Resources\WorkflowInstances\Pages;

use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkflowInstances extends ListRecords
{
    protected static string $resource = WorkflowInstanceResource::class;
}

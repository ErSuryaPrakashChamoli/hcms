<?php

namespace App\Filament\Resources\LeaveEncashmentBatches\Pages;

use App\Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewLeaveEncashmentBatch extends PeopleViewRecord
{
    protected static string $resource = LeaveEncashmentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LeaveEncashmentBatchResource::approveAction(),
            LeaveEncashmentBatchResource::rejectAction(),
            LeaveEncashmentBatchResource::withdrawAction(),
            LeaveEncashmentBatchResource::unprocessAction(),
        ];
    }
}

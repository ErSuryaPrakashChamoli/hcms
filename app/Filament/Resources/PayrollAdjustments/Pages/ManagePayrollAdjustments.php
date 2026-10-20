<?php

namespace App\Filament\Resources\PayrollAdjustments\Pages;

use App\Filament\Resources\PayrollAdjustments\PayrollAdjustmentResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManagePayrollAdjustments extends PeopleManageRecords
{
    protected static string $resource = PayrollAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])];
    }
}

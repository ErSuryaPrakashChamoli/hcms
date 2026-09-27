<?php

namespace App\Filament\Resources\PayrollAdjustments\Pages;

use App\Filament\Resources\PayrollAdjustments\PayrollAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePayrollAdjustments extends ManageRecords
{
    protected static string $resource = PayrollAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])];
    }
}

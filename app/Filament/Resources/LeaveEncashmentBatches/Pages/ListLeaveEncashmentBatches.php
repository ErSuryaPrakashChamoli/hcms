<?php

namespace App\Filament\Resources\LeaveEncashmentBatches\Pages;

use App\Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource;
use App\Filament\Support\LeaveEncashmentWizards;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;

/** HROne parity PO-8: the encashment batches and "Encash leaves" (`?action=encashLeaves` opens the wizard). */
class ListLeaveEncashmentBatches extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = LeaveEncashmentBatchResource::class;

    public static function registerKey(): string
    {
        return 'leave_encashment_batches';
    }

    public static function presetViews(): array
    {
        return [
            'waiting' => ['label' => 'Waiting for approval', 'filters' => ['status' => ['value' => 'pending']]],
            'processed' => ['label' => 'Processed', 'filters' => ['status' => ['value' => 'processed']]],
            'unprocessed' => ['label' => 'Unprocessed', 'filters' => ['status' => ['value' => 'unprocessed']]],
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Encash a leave type for many employees at once, as their encashment rule allows or a set number of days. The encashments are paid in the pay month chosen, or by the next payroll run.';
    }

    protected function getHeaderActions(): array
    {
        return [LeaveEncashmentWizards::batch(), ...$this->registerToolActions()];
    }
}

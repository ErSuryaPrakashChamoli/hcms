<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\ServiceDesk\Models\TicketCategory;

/** Starter ticket and grievance categories for every tenant (§48, §49). */
final class ServiceDeskDefaults
{
    public function seed(): void
    {
        foreach (config('peopleos.servicedesk.defaults', []) as $i => $row) {
            TicketCategory::query()->firstOrCreate(['code' => $row['code']], $row + ['sort_order' => ($i + 1) * 10, 'status' => 'active']);
        }
        foreach (config('peopleos.grievance.defaults', []) as $row) {
            GrievanceCategory::query()->firstOrCreate(['code' => $row['code']], $row + ['status' => 'active']);
        }
    }
}

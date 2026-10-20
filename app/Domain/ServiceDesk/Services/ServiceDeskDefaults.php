<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\TicketCategory;
use Illuminate\Support\Carbon;

/**
 * Starter ticket and grievance categories for every tenant (§48, §49). Phase 12 adds:
 * - a starter SLA policy;
 * - the example catalogue services as DRAFT versions.
 *
 * Nothing is live until HR completes a version (form, workflow) and a second person approves it.
 */
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

        $sla = config('peopleos.servicedesk.sla_defaults');
        $policy = $sla ? ServiceSlaPolicy::query()->firstOrCreate(['code' => $sla['code']], collect($sla)->except('code')->all() + ['effective_from' => Carbon::today()->startOfYear()->toDateString(), 'status' => 'active']) : null;

        foreach (config('peopleos.servicedesk.service_defaults', []) as $i => $row) {
            if (ServiceDefinition::query()->where('code', $row['code'])->exists()) {
                continue;
            }
            $service = ServiceDefinition::query()->create([
                'code' => $row['code'], 'name' => $row['name'], 'ticket_category_id' => TicketCategory::query()->where('code', $row['category'] ?? 'OTHER')->value('id'),
                'status' => 'active', 'sort_order' => ($i + 1) * 10,
            ]);
            ServiceDefinitionVersion::query()->create([
                'service_definition_id' => $service->id, 'version' => 1, 'status' => 'draft', 'effective_from' => Carbon::today()->toDateString(),
                'domain_action' => $row['domain_action'] ?? null, 'confidentiality' => $row['confidentiality'] ?? 'standard',
                'approval_required' => (bool) ($row['approval_required'] ?? false), 'availability' => $row['availability'] ?? ['employee' => true, 'manager' => false, 'hr' => true],
                'sla_policy_id' => $policy?->id, 'attachment_rule' => 'optional', 'change_note' => 'Starter service (complete and submit for approval)',
            ]);
        }
    }
}

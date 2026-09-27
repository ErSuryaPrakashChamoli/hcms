<?php

namespace App\Console\Commands;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Platform\Models\Tenant;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class TickServiceDesk extends Command
{
    protected $signature = 'peopleos:servicedesk:tick {--tenant=}';

    protected $description = 'Escalate SLA-breached tickets and overdue grievances; auto-close resolved tickets';

    public function handle(ServiceDesk $desk, Grievances $grievances, AuditRecorder $audit, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($desk, $grievances, $audit, $tenants) {
                $tenants->runAs($tenant, function () use ($desk, $grievances, $audit, $tenant) {
                    $r = $desk->tick();
                    $overdue = 0;
                    $grievances->overdue()->each(function ($case) use ($audit, &$overdue) {
                        if ($case->updated_at->lt(now()->subDay())) {
                            $audit->record(AuditAction::Escalated, 'grievance', $case, [], 'Past due date', metadata: ['due_on' => $case->due_on->toDateString()]);
                            ServiceDeskEvent::dispatch('grievance.escalated', $case, ['number' => $case->number, 'due_on' => $case->due_on->toDateString()], array_filter([$case->assignee_id]));
                            $case->touch();
                            $overdue++;
                        }
                    });
                    $this->info("{$tenant->slug}: {$r['escalated']} ticket(s) escalated, {$r['auto_closed']} auto-closed, {$overdue} grievance(s) overdue");
                });
            });

        return self::SUCCESS;
    }
}

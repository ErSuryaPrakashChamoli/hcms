<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;

class ExportAudit extends Command
{
    protected $signature = 'peopleos:audit:export {--tenant=} {--from=} {--to=}';

    protected $description = 'Export a tenant\'s audit trail (with hash chain) to CSV on the warehouse disk';

    public function handle(TenantContext $tenants): int
    {
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant) {
                $writer = Writer::createFromString();
                $writer->insertOne(['id', 'occurred_at', 'actor', 'action', 'module', 'entity_type', 'entity_id', 'entity_label', 'reason', 'source', 'hash', 'previous_hash']);
                AuditEvent::query()->orderBy('occurred_at')->orderBy('id')
                    ->when($this->option('from'), fn ($q, $d) => $q->where('occurred_at', '>=', $d))
                    ->when($this->option('to'), fn ($q, $d) => $q->where('occurred_at', '<=', $d.' 23:59:59'))
                    ->chunk(1000, function ($events) use ($writer) {
                        foreach ($events as $e) {
                            $writer->insertOne([$e->id, $e->occurred_at, $e->actor_name, $e->action, $e->module, $e->entity_type, $e->entity_id, $e->entity_label, $e->reason, $e->source, $e->hash, $e->previous_hash]);
                        }
                    });
                $path = "warehouse/{$tenant->slug}/audit-".now()->format('Ymd-His').'.csv';
                Storage::disk(config('peopleos.enterprise.warehouse_disk', 'local'))->put($path, $writer->toString());
                $this->info("{$tenant->slug}: {$path}");
            }));

        return self::SUCCESS;
    }
}

<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Data-warehouse feed (§110): every dataset as newline-delimited JSON per tenant per day on the warehouse disk.
 *
 * SaaS.8: opt-in per tenant (setting warehouse.export_enabled; it exported every tenant's people data daily without
 * anyone asking), and each tenant's daily snapshots are deleted after warehouse.retention_days by the retention purge
 * (purgeSnapshots; they were never deleted).
 */
final class WarehouseExport
{
    public function __construct(private readonly DatasetRegistry $datasets, private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function enabled(): bool
    {
        return (bool) app(SettingsRepository::class)->get('warehouse.export_enabled', false);
    }

    /** @return array<string, int> dataset => rows */
    public function run(?User $actor = null, ?array $only = null): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The data warehouse feed is not enabled for this organisation.');
        }
        $disk = config('peopleos.enterprise.warehouse_disk', 'local');
        $tenant = $this->tenants->current();
        $folder = "warehouse/{$tenant->slug}/".now()->format('Y-m-d');
        $out = [];
        $truncated = [];

        foreach ($this->datasets->all() as $key => $dataset) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }
            // Phase 14: the feed carries non-sensitive fields only (as a run without a user would), unless the
            // tenant explicitly enabled sensitive fields in the warehouse feed (setting warehouse.include_sensitive).
            $fields = array_keys(app(SettingsRepository::class)->get('warehouse.include_sensitive', false) ? $dataset->fields() : $dataset->fieldsFor(null));
            $lines = [];
            $max = (int) config('peopleos.analytics.max_rows', 10000);
            $truncated[$key] = $dataset->query()->count() > $max;
            $dataset->query()->limit($max)->get()->each(function ($model) use ($dataset, $fields, &$lines) {
                $row = ['_exported_at' => now()->toIso8601String()];
                foreach ($fields as $field) {
                    $row[$field] = $dataset->value($field, $model);
                }
                $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
            });
            Storage::disk($disk)->put("{$folder}/{$key}.jsonl", implode("\n", $lines).($lines ? "\n" : ''));
            $out[$key] = count($lines);
        }

        Storage::disk($disk)->put("{$folder}/manifest.json", json_encode(['tenant' => $tenant->slug, 'exported_at' => now()->toIso8601String(), 'datasets' => $out, 'truncated' => array_keys(array_filter($truncated))], JSON_PRETTY_PRINT));
        $this->audit->record(AuditAction::Export, 'enterprise', null, [], 'Warehouse export', actor: $actor, metadata: ['datasets' => $out, 'folder' => $folder]);

        return $out;
    }

    /** Deletes the bound tenant's daily snapshot folders older than warehouse.retention_days (0 keeps them). @return int folders deleted */
    public function purgeSnapshots(): int
    {
        $days = (int) app(SettingsRepository::class)->get('warehouse.retention_days', 30);
        $tenant = $this->tenants->current();
        if ($days <= 0 || $tenant === null) {
            return 0;
        }
        $storage = Storage::disk(config('peopleos.enterprise.warehouse_disk', 'local'));
        $cutoff = now()->subDays($days)->format('Y-m-d');
        $deleted = 0;
        foreach ($storage->directories("warehouse/{$tenant->slug}") as $folder) {
            $day = basename($folder);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && $day < $cutoff) {
                $storage->deleteDirectory($folder);
                $deleted++;
            }
        }

        return $deleted;
    }
}

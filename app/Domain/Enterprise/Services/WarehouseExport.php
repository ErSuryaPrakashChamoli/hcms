<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/** Data-warehouse feed (§110): every dataset as newline-delimited JSON per tenant per day on the warehouse disk. */
final class WarehouseExport
{
    public function __construct(private readonly DatasetRegistry $datasets, private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    /** @return array<string, int> dataset => rows */
    public function run(?User $actor = null, ?array $only = null): array
    {
        $disk = config('peopleos.enterprise.warehouse_disk', 'local');
        $tenant = $this->tenants->current();
        $folder = "warehouse/{$tenant->slug}/".now()->format('Y-m-d');
        $out = [];

        foreach ($this->datasets->all() as $key => $dataset) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }
            $fields = array_keys($dataset->fields());
            $lines = [];
            $dataset->query()->limit((int) config('peopleos.analytics.max_rows', 10000))->get()->each(function ($model) use ($dataset, $fields, &$lines) {
                $row = ['_exported_at' => now()->toIso8601String()];
                foreach ($fields as $field) {
                    $row[$field] = $dataset->value($field, $model);
                }
                $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
            });
            Storage::disk($disk)->put("{$folder}/{$key}.jsonl", implode("\n", $lines).($lines ? "\n" : ''));
            $out[$key] = count($lines);
        }

        Storage::disk($disk)->put("{$folder}/manifest.json", json_encode(['tenant' => $tenant->slug, 'exported_at' => now()->toIso8601String(), 'datasets' => $out], JSON_PRETTY_PRINT));
        $this->audit->record(AuditAction::Export, 'enterprise', null, [], 'Warehouse export', actor: $actor, metadata: ['datasets' => $out, 'folder' => $folder]);

        return $out;
    }
}

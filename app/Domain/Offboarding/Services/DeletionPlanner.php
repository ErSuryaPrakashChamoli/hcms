<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Enums\ExportStatus;
use App\Domain\Offboarding\Enums\OffboardingStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Offboarding\Support\SchemaGraph;
use App\Domain\Payments\Services\FinancialStanding;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * SaaS.9: what a deletion of a closed tenant would do today, category by category, and why. Read-only and deterministic;
 * the same function is used to show the plan, to approve it (its hash is what the checker approves) and, again, by the
 * worker immediately before it deletes (it never deletes more than both the approved plan and today's plan allow).
 *
 * A category is deleted only when ALL of these hold:
 * - it is the customer's data (Markedge's commercial records and the exit's evidence are retained, never deleted here);
 * - an approved retention policy exists for it (none is assumed: RETENTION_POLICY_NOT_CONFIGURED) and its period, counted
 *   from the closure, has ended;
 * - no legal hold covers it;
 * - no kept record (retained, held, blocked, anonymised, Markedge's or the platform's) still references its rows; such a
 *   category is protected (PROTECTED_BY_DEPENDENCY), except sign-in accounts, which are anonymised instead (only the
 *   accounts; the identity a payroll or statutory record needs is in people and employees, which stay protected).
 *
 * The tenant itself must be deletable at all (gates): closed, its export delivered or waived (if required), no open
 * financial item, and no other deletion planned, approved, running or failed.
 */
final class DeletionPlanner
{
    public const ELIGIBLE = 'ELIGIBLE';

    public function __construct(
        private readonly RetentionPolicies $policies,
        private readonly LegalHolds $holds,
        private readonly FinancialStanding $financial,
        private readonly TenantContext $tenants,
    ) {}

    /** @return array<string, mixed> the plan (see class doc); stored with the deletion and hashed for the approval */
    public function plan(Tenant $tenant, ?TenantOffboarding $case, ?TenantDeletion $ignoring = null): array
    {
        $tenant = Tenant::query()->findOrFail($tenant->getKey());
        $closedAt = $case?->closed_at ? Carbon::parse($case->closed_at) : null;
        $categories = [];

        // 1. Each category on its own: ownership, legal holds, retention, content.
        foreach (DataInventory::CATEGORIES as $key => $definition) {
            $tables = [];
            foreach ([...DataInventory::TABLES[$key], ...DataInventory::childrenOf($key)] as $table) {
                $tables[$table] = (int) ExportPackager::rowsOf($tenant, $table)->count();
            }
            $files = $this->fileCount($tenant, $key);
            $stored = count(self::storedFiles($tenant, $key));
            $retention = $closedAt === null ? null : $this->policies->retentionFor($key, (string) $tenant->country_code, $closedAt);
            $holds = $this->holds->covering($tenant->getKey(), $key);
            [$action, $reason, $detail] = match (true) {
                $definition['action'] === DataInventory::RETAIN && $definition['owner'] === 'markedge' => [DataInventory::RETAIN, 'RETAINED_MARKEDGE_RECORDS', 'Markedge\'s own commercial records: kept under Markedge\'s retention, never deleted with the tenant'],
                $definition['action'] === DataInventory::RETAIN => [DataInventory::RETAIN, 'RETAINED_EXIT_EVIDENCE', 'The record of this exit: kept'],
                array_sum($tables) === 0 && $files === 0 && $stored === 0 => ['none', 'NOTHING_TO_DELETE', 'No data in this category'],
                $holds !== [] => [DataInventory::RETAIN, 'LEGAL_HOLD', 'Held by '.implode(', ', $holds)],
                $retention === null => [DataInventory::RETAIN, 'TENANT_NOT_CLOSED', 'Retention starts when the tenant is closed'],
                ! $retention['configured'] => [DataInventory::RETAIN, 'RETENTION_POLICY_NOT_CONFIGURED', 'No approved retention policy for this category: nothing is assumed'],
                $retention['until'] > today()->toDateString() => [DataInventory::RETAIN, 'RETENTION_RUNNING', "Kept until {$retention['until']}"],
                default => [$definition['action'], self::ELIGIBLE, $definition['action'] === DataInventory::DELETE ? 'Retention ended' : 'Retention ended; anonymised'],
            };
            $categories[$key] = ['label' => $definition['label'], 'owner' => $definition['owner'], 'action' => $action, 'reason' => $reason, 'detail' => $detail,
                'retention' => $retention, 'holds' => $holds, 'tables' => $tables, 'files' => $files, 'stored_files' => $stored, 'protected_by' => [], 'anonymised_tables' => []];
        }

        // 2. Dependencies: nothing kept may reference a deleted row (that would delete, null or orphan a kept record).
        $this->protectDependencies($tenant, $categories);

        $gates = $this->gates($tenant, $case, $ignoring);
        $eligible = array_keys(array_filter($categories, fn (array $c) => in_array($c['action'], [DataInventory::DELETE, DataInventory::ANONYMISE], true)));
        $remaining = array_keys(array_filter($categories, fn (array $c) => $c['owner'] !== 'markedge' && $c['reason'] !== 'RETAINED_EXIT_EVIDENCE'
            && ! in_array($c['action'], [DataInventory::DELETE, 'none'], true)));

        return [
            'tenant' => ['id' => $tenant->getKey(), 'slug' => $tenant->slug, 'name' => $tenant->name, 'jurisdiction' => (string) $tenant->country_code, 'status' => $tenant->status->value],
            'offboarding' => $case?->reference,
            'closed_at' => $closedAt?->toIso8601String(),
            'evaluated_on' => today()->toDateString(),
            'gates' => $gates,
            'categories' => $categories,
            'eligible' => $eligible,
            'final' => $remaining === [], // after this deletion nothing of the customer's data would remain
            'deletable' => $gates === [] && $eligible !== [],
        ];
    }

    /**
     * The approved actions as a stable fingerprint (what the checker approves): categories, actions, tables, and the
     * dependency outcome. Counts and dates are informative and not part of it.
     */
    public static function fingerprint(array $plan): string
    {
        $core = [];
        foreach ($plan['categories'] as $key => $c) {
            $core[$key] = [$c['action'], $c['reason'], array_keys($c['tables']), $c['anonymised_tables']];
        }
        ksort($core);

        return hash('sha256', json_encode([$plan['tenant']['id'], $plan['offboarding'], $core]));
    }

    /** @return list<string> the references of every active hold the plan considered, sorted */
    public static function holdReferences(array $plan): array
    {
        $references = array_values(array_unique(array_merge([], ...array_values(array_map(fn (array $c) => $c['holds'], $plan['categories'])))));
        sort($references);

        return $references;
    }

    /** @return list<array{code: string, detail: string}> */
    private function gates(Tenant $tenant, ?TenantOffboarding $case, ?TenantDeletion $ignoring): array
    {
        $gates = [];
        if ($case === null || $case->status !== OffboardingStatus::Closed || $tenant->status !== TenantStatus::Closed) {
            $gates[] = ['code' => 'TENANT_NOT_CLOSED', 'detail' => 'Only a closed tenant (offboarding closed) is deleted'];
        }
        if ($case !== null && (bool) config('peopleos.offboarding.export_required_before_deletion', true) && $case->export_waived_at === null) {
            $delivered = $this->tenants->bypass(fn () => TenantExport::query()->where('tenant_id', $tenant->getKey())
                ->whereIn('status', [ExportStatus::Ready->value, ExportStatus::Expired->value])->whereNotNull('delivered_at')->exists());
            if (! $delivered) {
                $gates[] = ['code' => 'EXPORT_NOT_DELIVERED', 'detail' => 'No export has been delivered to the customer, and the export was not waived'];
            }
        }
        if ($this->tenants->bypass(fn () => TenantExport::query()->where('tenant_id', $tenant->getKey())->whereIn('status', ExportStatus::inFlightValues())->exists())) {
            $gates[] = ['code' => 'EXPORT_IN_PROGRESS', 'detail' => 'An export is being generated: it reads the data a deletion would remove'];
        }
        foreach ($this->financial->openItems($tenant) as $item) {
            $gates[] = ['code' => 'FINANCIAL_'.$item['code'], 'detail' => $item['detail']];
        }
        $inFlight = $this->tenants->bypass(fn () => TenantDeletion::query()->where('tenant_id', $tenant->getKey())
            ->whereIn('status', [DeletionStatus::Planned->value, DeletionStatus::Approved->value, DeletionStatus::InProgress->value, DeletionStatus::Failed->value])
            ->when($ignoring !== null, fn ($q) => $q->whereKeyNot($ignoring->getKey()))->exists());
        if ($inFlight) {
            $gates[] = ['code' => 'DELETION_IN_FLIGHT', 'detail' => 'A deletion of this tenant is already planned, approved, running or failed: finish, cancel or reject it first'];
        }

        return $gates;
    }

    /** @param  array<string, array<string, mixed>>  $categories */
    private function protectDependencies(Tenant $tenant, array &$categories): void
    {
        $tableCategory = [];
        foreach (DataInventory::categories() as $key) {
            foreach ([...DataInventory::TABLES[$key], ...DataInventory::childrenOf($key)] as $table) {
                $tableCategory[$table] = $key;
            }
        }
        $deleted = function (string $table) use (&$categories, $tableCategory): bool {
            $category = $tableCategory[$table] ?? null;
            if ($category === null) {
                return false; // platform tables are never deleted
            }
            $c = $categories[$category];

            return $c['action'] === DataInventory::DELETE || ($c['action'] === DataInventory::ANONYMISE && ! in_array($table, $c['anonymised_tables'], true));
        };
        $referenced = [];
        do {
            $changed = false;
            foreach (SchemaGraph::edges() as $edge) {
                if ($edge['parent'] === 'tenants' || ! $deleted($edge['parent']) || $deleted($edge['child'])) {
                    continue;
                }
                $category = $tableCategory[$edge['parent']];
                if (($tableCategory[$edge['child']] ?? null) === $category && $categories[$category]['action'] !== DataInventory::ANONYMISE) {
                    continue;
                }
                $key = $edge['child'].'.'.$edge['column'].'>'.$edge['parent'];
                $referenced[$key] ??= DB::table($edge['child'])->whereIn($edge['column'], ExportPackager::rowsOf($tenant, $edge['parent'])->select($edge['parent_column']))->exists();
                if (! $referenced[$key]) {
                    continue;
                }
                $by = $tableCategory[$edge['child']] ?? 'platform';
                $c = &$categories[$category];
                if (DataInventory::CATEGORIES[$category]['anonymisable'] && isset(DataInventory::ANONYMISED_COLUMNS[$edge['parent']])) {
                    $c['action'] = DataInventory::ANONYMISE;
                    $c['anonymised_tables'] = array_values(array_unique([...$c['anonymised_tables'], $edge['parent']]));
                    $c['detail'] = 'Sign-in accounts still referenced by kept records: anonymised, not deleted';
                } else {
                    $c['action'] = DataInventory::RETAIN;
                    $c['reason'] = 'PROTECTED_BY_DEPENDENCY';
                    $c['detail'] = 'Still referenced by kept records';
                }
                $c['protected_by'] = array_values(array_unique([...$c['protected_by'], "{$by} ({$edge['child']}.{$edge['column']})"]));
                unset($c);
                $changed = true;
            }
        } while ($changed);
    }

    /**
     * Every file stored under the category's tenant prefixes (generated folders, warehouse snapshots, anything a row no
     * longer references).
     *
     * @return list<array{disk: string, path: string}>
     */
    public static function storedFiles(Tenant $tenant, string $category): array
    {
        $out = [];
        foreach (DataInventory::prefixes($tenant)[$category] ?? [] as $prefix) {
            foreach (Storage::disk($prefix['disk'])->allFiles($prefix['prefix']) as $path) {
                $out[] = ['disk' => $prefix['disk'], 'path' => $path];
            }
        }

        return $out;
    }

    private function fileCount(Tenant $tenant, string $category): int
    {
        $count = 0;
        foreach (DataInventory::FILES as $table => $columns) {
            if (DataInventory::categoryOf($table) !== $category) {
                continue;
            }
            foreach ($columns as $column) {
                $count += (int) ExportPackager::rowsOf($tenant, $table)->whereNotNull($column['path'])->where($column['path'], '!=', '')->count();
            }
        }

        return $count;
    }
}

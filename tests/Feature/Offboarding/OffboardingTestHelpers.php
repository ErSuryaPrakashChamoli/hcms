<?php

use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Models\RetentionPolicy;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Services\ExportPackager;
use App\Domain\Offboarding\Services\RetentionPolicies;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantExports;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/** Two platform operators: the maker and the checker of every SaaS.9 maker-checker step. @return array{0: User, 1: User} */
function exitOperators(): array
{
    return [User::factory()->platformAdmin()->create(['name' => 'Exit Maker']), User::factory()->platformAdmin()->create(['name' => 'Exit Checker'])];
}

/**
 * Approved retention policies (fictional test values, never legal ones) for the given categories, proposed by the maker
 * and approved by the checker.
 *
 * @param  list<string>|null  $categories  null = every category the inventory deletes
 */
function retentionFor(?array $categories, int $months, User $maker, User $checker, string $jurisdiction = '*'): void
{
    $categories ??= array_keys(array_filter(DataInventory::CATEGORIES, fn (array $c) => $c['action'] !== DataInventory::RETAIN));
    $service = app(RetentionPolicies::class);
    foreach ($categories as $category) {
        $policy = $service->propose($category, $jurisdiction, null, null, null, $months, 'Fictional test policy', today()->toDateString(), 'Test retention policy', $maker);
        $service->approve($policy, $checker, 'Checked in the test');
    }
}

/** Starts and closes the tenant's offboarding (the subscription, if any, must already have ended). */
function offboardAndClose(Tenant $tenant, User $operator): TenantOffboarding
{
    $service = app(TenantOffboardings::class);
    $case = $service->request($tenant, 'Customer gave notice (test)', 'CONTRACT-TEST-1', null, $operator);

    return $service->close($case, $operator, 'End of service (test)');
}

/** An export generated (the sync queue runs the job) and recorded as delivered by the operator. */
function deliveredExport(Tenant $tenant, User $operator, ?TenantOffboarding $case = null): TenantExport
{
    $export = app(TenantExports::class)->request($tenant, $operator, 'Offboarding export (test)', $case);
    $export = app(TenantExports::class)->find($export->id);
    expect($export->status->value)->toBe('ready', (string) $export->failure);

    return app(TenantExports::class)->markDelivered($export, $operator, 'Handed over to the customer (test)');
}

/** Plans (maker), approves (checker) and executes the deletion past its cooling-off. */
function approvedDeletion(TenantOffboarding $case, User $maker, User $checker): TenantDeletion
{
    $deletions = app(TenantDeletions::class);
    $deletion = $deletions->request($case, $maker, 'Retention ended; delete (test)');

    return $deletions->approve($deletion, $checker, 'Plan reviewed (test)');
}

/** @return array<string, int> table => this tenant's rows, for every table the inventory knows */
function tenantRowCounts(Tenant $tenant): array
{
    $counts = [];
    foreach ([...array_keys(DataInventory::tableCategories()), ...array_keys(DataInventory::CHILDREN)] as $table) {
        $counts[$table] = (int) app(TenantContext::class)->bypass(fn () => ExportPackager::rowsOf($tenant, $table)->count());
    }

    return $counts;
}

/** Policies are platform configuration: a test resets them by deleting the rows (never possible in the product). */
function noRetentionPolicies(): void
{
    RetentionPolicy::query()->delete();
}

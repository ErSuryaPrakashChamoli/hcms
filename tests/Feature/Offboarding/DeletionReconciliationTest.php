<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Services\DeletionPlanner;
use App\Domain\Offboarding\Services\DeletionReconciliation;
use App\Domain\Offboarding\Services\DeletionRegister;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Offboarding\Support\Signatures;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Observability\PlatformReadiness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.12: a restore never silently brings back a tenant that was legitimately deleted. Each certified deletion is also
| written to the deletion register, outside the database. After a restore the register is compared with the database:
| a tenant restored past its deletion is contained (suspended, re-application recorded), never deleted automatically, and
| the deletion is re-applied through the ordinary workflow (holds, maker-checker, cooling-off), which records what it
| re-applies. Doubtful entries are never acted on.
|
| A restore is simulated faithfully: every row of every table is captured, the deletion runs (and writes the register on
| its own disk), then every row is put back. The register survives, as it does on its own storage in production.
*/

/** @return array<string, list<array<string, mixed>>> every row of every table (the "backup") */
function snapshotDatabase(): array
{
    $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' and name <> 'migrations'"))->pluck('name');

    return $tables->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($row) => (array) $row)->all()])->all();
}

/** Puts every table back to the snapshot (the "restore"). */
function restoreDatabase(array $snapshot): void
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
    foreach ($snapshot as $table => $rows) {
        DB::table($table)->delete();
        foreach (array_chunk($rows, 25) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
    app(TenantContext::class)->clear();
}

function reconciliationCheck(): array
{
    return collect(app(PlatformReadiness::class)->report(false)['checks'])->firstWhere('area', 'offboarding');
}

/** Offboards, closes, waives the export, plans (maker), approves (checker) and runs the deletion past its cooling-off. */
function completedDeletion(object $test, Tenant $tenant): TenantDeletion
{
    $case = offboardAndClose($tenant, $test->maker);
    app(TenantOffboardings::class)->waiveExport($case, $test->maker, 'Customer kept their own copy (test)');
    $deletion = approvedDeletion($case->fresh(), $test->maker, $test->checker);
    $test->travel(15)->days();
    app(TenantDeletions::class)->dispatchDue();
    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure);

    return $deletion;
}

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.offboarding.deletion_enabled' => true]);
    [$this->maker, $this->checker] = exitOperators();
    retentionFor(null, 0, $this->maker, $this->checker);
    $this->staying = provisionTenant('Staying Co');
    $this->tenant = provisionTenant('Leaving Co');
    app(TenantContext::class)->runAs($this->tenant, fn () => employeeWithUser());
    app(TenantContext::class)->clear();
});

it('writes each certified deletion to the register once, outside the database, and never rewrites an entry', function () {
    $deletion = completedDeletion($this, $this->tenant);
    $register = app(DeletionRegister::class);
    $path = DeletionRegister::pathOf($deletion->reference);

    $entry = json_decode(Storage::disk('local')->get($path), true);
    expect($entry['format'])->toBe(DeletionRegister::FORMAT)
        ->and($entry['tenant'])->toBe(['id' => $this->tenant->id, 'slug' => $this->tenant->slug])
        ->and($entry['certificate_sha256'])->toBe($deletion->certificate_sha256)
        ->and(Signatures::verify('deletion-certificate', $entry['certificate'], $entry['certificate_signature']))->toBeTrue()
        ->and($register->record($deletion))->toBeFalse(); // already there: nothing written

    Storage::disk('local')->put($path, json_encode(['certificate_sha256' => str_repeat('0', 64)] + $entry));
    expect(fn () => $register->record($deletion))->toThrow(RuntimeException::class, 'already holds a different entry');

    Storage::disk('local')->put($path, json_encode($entry)); // as written
    $check = app(DeletionReconciliation::class)->check();
    expect($check['entries'][0]['outcome'])->toBe(DeletionReconciliation::CONSISTENT)
        ->and(DeletionReconciliation::clean($check['summary']))->toBeTrue()
        ->and(reconciliationCheck()['status'])->toBe(PlatformReadiness::PASS);
});

it('finds and contains a tenant a restore brought back past its deletion, and deletes nothing itself', function () {
    $backup = snapshotDatabase();
    $deletion = completedDeletion($this, $this->tenant);
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Deleted);

    restoreDatabase($backup);

    $employees = Employee::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count();
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Active)->and($employees)->toBeGreaterThan(0);
    $entry = app(DeletionReconciliation::class)->check()['entries'][0];
    expect($entry)->toMatchArray(['reference' => $deletion->reference, 'outcome' => DeletionReconciliation::RESURRECTED, 'operating' => true,
        'restored_tenant_status' => 'active', 'restored_deletion_status' => 'absent'])
        ->and(reconciliationCheck()['status'])->toBe(PlatformReadiness::WARN);
    app()->detectEnvironment(fn () => 'production');
    expect(reconciliationCheck()['status'])->toBe(PlatformReadiness::FAIL);
    app()->detectEnvironment(fn () => 'testing');

    $this->artisan('peopleos:deletions:reconcile', ['--contain' => true])->assertExitCode(1);
    $this->artisan('peopleos:deletions:reconcile', ['--contain' => true])->assertExitCode(1); // idempotent

    $tenant = $this->tenant->fresh();
    $required = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'DELETION_REAPPLICATION_REQUIRED')->get();
    expect($tenant->status)->toBe(TenantStatus::Suspended)
        ->and($required)->toHaveCount(1)
        ->and($required->first()->metadata)->toMatchArray(['deletion' => $deletion->reference, 'subject_tenant_id' => $this->tenant->id, 'restored_tenant_status' => 'active'])
        ->and(Employee::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe($employees) // nothing deleted
        ->and(app(DeletionReconciliation::class)->check()['entries'][0]['operating'])->toBeFalse()
        ->and(reconciliationCheck()['status'])->toBe(PlatformReadiness::WARN)
        ->and($this->staying->fresh()->status)->toBe(TenantStatus::Active);
});

it('re-applies the deletion only through the ordinary workflow: holds, maker-checker and the cooling-off apply', function () {
    $backup = snapshotDatabase();
    $original = completedDeletion($this, $this->tenant);
    restoreDatabase($backup);
    app(DeletionReconciliation::class)->contain(null);

    // A hold in the restored data is honoured: the tenant cannot even close while every category is held.
    $hold = app(LegalHolds::class)->place($this->tenant->fresh(), null, 'Matter still open in the restored data (fictional)', null, null, $this->maker);
    $case = offboardAndClose($this->tenant->fresh(), $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer kept their own copy (test)');
    expect(fn () => app(TenantDeletions::class)->request($case->fresh(), $this->maker, 'Re-apply the certified deletion'))->toThrow(RuntimeException::class, 'No category is eligible')
        ->and(app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh())['categories']['people']['reason'])->toBe('LEGAL_HOLD');
    app(LegalHolds::class)->approveRelease(app(LegalHolds::class)->requestRelease($hold, $this->maker, 'Matter closed (fictional)'), $this->checker, 'Release confirmed (test)');

    $deletion = app(TenantDeletions::class)->request($case->fresh(), $this->maker, 'Re-apply the certified deletion');
    expect($deletion->plan['reapplies'])->toHaveCount(1)
        ->and($deletion->plan['reapplies'][0])->toMatchArray(['reference' => $original->reference, 'certificate_sha256' => $original->certificate_sha256])
        ->and(fn () => app(TenantDeletions::class)->approve($deletion, $this->maker, 'My own plan'))->toThrow(RuntimeException::class, 'other than the one who planned it');
    $deletion = app(TenantDeletions::class)->approve($deletion, $this->checker, 'Plan reviewed against the certificate');
    expect(app(TenantDeletions::class)->dispatchDue())->toMatchArray(['dispatched' => 0]); // cooling-off
    $this->travel(15)->days();
    app(TenantDeletions::class)->dispatchDue();

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($deletion->certificate['reapplies'][0]['reference'])->toBe($original->reference)
        ->and(Signatures::verify('deletion-certificate', $deletion->certificate, $deletion->certificate_signature))->toBeTrue();
    $outcomes = collect(app(DeletionReconciliation::class)->check()['entries'])->pluck('outcome', 'reference');
    expect($outcomes[$original->reference])->toBe(DeletionReconciliation::REAPPLIED)
        ->and($outcomes[$deletion->reference])->toBe(DeletionReconciliation::CONSISTENT)
        ->and(reconciliationCheck()['status'])->toBe(PlatformReadiness::PASS)
        ->and(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'DELETION_PLANNED')->where('metadata->deletion', $deletion->reference)->sole()->metadata['reapplies'])
        ->toBe([$original->reference]);
});

it('never acts on an entry whose tenant identity or signature is in doubt, and accepts a rotated key', function () {
    $backup = snapshotDatabase();
    $deletion = completedDeletion($this, $this->tenant);
    restoreDatabase($backup);
    $path = DeletionRegister::pathOf($deletion->reference);
    $written = Storage::disk('local')->get($path);
    $outcome = fn () => app(DeletionReconciliation::class)->check()['entries'][0]['outcome'];

    // A different customer under the same id (the slug differs): reported, never contained.
    Tenant::query()->whereKey($this->tenant->id)->update(['slug' => 'someone-else']);
    expect($outcome())->toBe(DeletionReconciliation::IDENTITY_MISMATCH);
    app(DeletionReconciliation::class)->contain(null);
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Active);
    Tenant::query()->whereKey($this->tenant->id)->update(['slug' => $this->tenant->slug]);

    // A tampered certificate: invalid, never acted on.
    $tampered = json_decode($written, true);
    $tampered['certificate']['tenant']['name'] = 'Changed';
    Storage::disk('local')->put($path, json_encode($tampered));
    expect($outcome())->toBe(DeletionReconciliation::INVALID);
    app(DeletionReconciliation::class)->contain(null);
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(reconciliationCheck()['status'])->toBe(PlatformReadiness::WARN);

    // A recorded SHA-256 that does not match the (validly signed) certificate: invalid, not "restored past a deletion".
    $altered = json_decode($written, true);
    $altered['certificate_sha256'] = str_repeat('a', 64);
    Storage::disk('local')->put($path, json_encode($altered));
    expect($outcome())->toBe(DeletionReconciliation::INVALID);
    app(DeletionReconciliation::class)->contain(null);
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Active);

    // After an application key rotation the entry stays evidence through APP_PREVIOUS_KEYS, and only through it.
    Storage::disk('local')->put($path, $written);
    $old = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
    expect($outcome())->toBe(DeletionReconciliation::RESURRECTED);
    config(['app.previous_keys' => []]);
    expect($outcome())->toBe(DeletionReconciliation::INVALID);
});

it('never acts when the database holds the deletion with another certificate than the register', function () {
    $deletion = completedDeletion($this, $this->tenant);
    TenantDeletion::query()->withoutTenancy()->whereKey($deletion->id)->update(['certificate_sha256' => str_repeat('b', 64)]);

    $entry = app(DeletionReconciliation::class)->check()['entries'][0];
    expect($entry['outcome'])->toBe(DeletionReconciliation::INVALID)->and($entry['detail'])->toContain('different certificate');
    expect(app(DeletionReconciliation::class)->contain(null))->toBe(['suspended' => 0, 'recorded' => 0]);
});

it('needs nothing when the restore predates the tenant itself', function () {
    $tenant = app(TenantContext::class)->bypass(fn () => Tenant::query()->whereKey($this->tenant->id)->firstOrFail());
    DB::table('tenants')->whereKey($tenant->id)->delete(); // simulate: the backup was taken before this tenant existed
    $backupWithout = snapshotDatabase();
    restoreDatabase($backupWithout);
    // Recreate the world after the backup: the tenant existed and was deleted (register written), then the restore.
    $this->tenant = provisionTenant('Leaving Co');
    app(TenantContext::class)->clear();
    $deletion = completedDeletion($this, $this->tenant);
    restoreDatabase($backupWithout);

    $check = app(DeletionReconciliation::class)->check();
    expect($check['entries'][0])->toMatchArray(['reference' => $deletion->reference, 'outcome' => DeletionReconciliation::NOT_PRESENT])
        ->and(DeletionReconciliation::clean($check['summary']))->toBeTrue();
});

it('reports a certified deletion missing from the register, and the offboarding tick writes it', function () {
    $deletion = completedDeletion($this, $this->tenant);
    Storage::disk('local')->delete(DeletionRegister::pathOf($deletion->reference)); // e.g. the register was unreachable at the time

    expect(app(DeletionReconciliation::class)->check()['unregistered'])->toBe([$deletion->reference])
        ->and(reconciliationCheck()['status'])->toBe(PlatformReadiness::WARN);
    $this->artisan('peopleos:offboarding:tick')->assertSuccessful();

    expect(Storage::disk('local')->exists(DeletionRegister::pathOf($deletion->reference)))->toBeTrue()
        ->and(DeletionReconciliation::clean(app(DeletionReconciliation::class)->check()['summary']))->toBeTrue();
});

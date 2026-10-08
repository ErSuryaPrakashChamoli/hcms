<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditVerificationRun;
use App\Domain\Audit\Services\AuditVerifications;
use App\Domain\Enterprise\Services\Retention;
use App\Domain\Enterprise\Services\WarehouseExport;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PlatformReadinessPage;
use App\Support\Observability\PlatformReadiness;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| SaaS.8: the audit chains are verified on a schedule and the results recorded (the readiness page re-hashed every event
| on each load and the verification was never scheduled); queue tables are pruned; the warehouse feed is opt-in and its
| snapshots expire.
*/

function readinessAudit(): array
{
    return collect(app(PlatformReadiness::class)->report(verifyAudit: false)['checks'])->firstWhere('area', 'audit');
}

beforeEach(function () {
    $this->tenant = provisionTenant('Acme');
    $this->other = provisionTenant('Other');
});

it('schedules the audit verification, the queue pruning and the notification recovery', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

    expect($commands)->toContain('peopleos:audit:verify --scheduled')
        ->toContain('peopleos:operations:prune')
        ->toContain('peopleos:notifications:recover');
});

it('records one result per chain and lets the readiness report read them instead of re-hashing', function () {
    expect(readinessAudit()['status'])->toBe(PlatformReadiness::WARN)
        ->and(readinessAudit()['detail'])->toContain('never verified');

    $this->artisan('peopleos:audit:verify --scheduled')->assertSuccessful();

    $runs = AuditVerificationRun::query()->get();
    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('chain')->sort()->values()->all())->toBe(['platform', 'tenant', 'tenant'])
        ->and($runs->every(fn ($r) => $r->valid && $r->trigger === 'schedule'))->toBeTrue();
    expect(readinessAudit()['status'])->toBe(PlatformReadiness::PASS);

    // Reading the report records nothing new: no live verification.
    app(PlatformReadiness::class)->report(verifyAudit: false);
    expect(AuditVerificationRun::query()->count())->toBe(3);

    // A result older than the window is stale.
    $this->travel(49)->hours();
    expect(readinessAudit()['status'])->toBe(PlatformReadiness::WARN)->and(readinessAudit()['detail'])->toContain('result too old');
});

it('fails readiness when the latest result of any chain is broken', function () {
    actAsTenant($this->tenant);
    $event = AuditEvent::query()->latest('id')->first();
    actAsTenant(null);
    DB::table('audit_events')->where('id', $event->id)->update(['reason' => 'tampered']);

    $this->artisan('peopleos:audit:verify')->assertFailed();

    expect(readinessAudit()['status'])->toBe(PlatformReadiness::FAIL)
        ->and(readinessAudit()['detail'])->toContain($this->tenant->slug)
        ->and(app(AuditVerifications::class)->status()['broken'])->toBe([$this->tenant->slug]);
});

it('renders the readiness page from the recorded results', function () {
    $this->artisan('peopleos:audit:verify')->assertSuccessful();
    $this->actingAs(platformAdmin());

    $this->get(PlatformReadinessPage::getUrl())->assertOk()->assertSee('events verified across 3 chains');
    // The page re-hashes nothing: no new verification is recorded by loading it.
    expect(AuditVerificationRun::query()->count())->toBe(3);
});

it('prunes failed jobs past the operational window and keeps recent ones', function () {
    foreach ([800, 10] as $hours) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHours($hours)]);
    }

    $this->artisan('peopleos:operations:prune')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('exports the warehouse feed only for tenants that enabled it', function () {
    Storage::fake('local');

    actAsTenant($this->tenant);
    expect(fn () => app(WarehouseExport::class)->run())->toThrow(RuntimeException::class, 'not enabled');
    app(SettingsRepository::class)->set('warehouse.export_enabled', true, 'Customer asked for the feed');
    actAsTenant(null);

    $this->artisan('peopleos:warehouse:export')->expectsOutputToContain("{$this->other->slug}: feed not enabled, skipped")->assertSuccessful();

    $day = now()->format('Y-m-d');
    Storage::disk('local')->assertExists("warehouse/{$this->tenant->slug}/{$day}/manifest.json");
    Storage::disk('local')->assertMissing("warehouse/{$this->other->slug}/{$day}/manifest.json");
});

it('deletes a tenant\'s warehouse snapshots past its window, and only that tenant\'s', function () {
    Storage::fake('local');
    $old = now()->subDays(45)->format('Y-m-d');
    $recent = now()->subDays(5)->format('Y-m-d');
    foreach ([$this->tenant, $this->other] as $tenant) {
        foreach ([$old, $recent] as $day) {
            Storage::disk('local')->put("warehouse/{$tenant->slug}/{$day}/manifest.json", '{}');
        }
    }

    actAsTenant($this->tenant);
    $purged = app(Retention::class)->purge();

    expect($purged['warehouse_snapshots'])->toBe(1);
    Storage::disk('local')->assertMissing("warehouse/{$this->tenant->slug}/{$old}/manifest.json");
    Storage::disk('local')->assertExists("warehouse/{$this->tenant->slug}/{$recent}/manifest.json");
    Storage::disk('local')->assertExists("warehouse/{$this->other->slug}/{$old}/manifest.json");

    // 0 keeps every snapshot.
    app(SettingsRepository::class)->set('warehouse.retention_days', 0, 'Keep');
    $this->travel(30)->days();
    expect(app(Retention::class)->purge()['warehouse_snapshots'])->toBe(0);
});

<?php

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Services\DeletionExecutor;
use App\Domain\Offboarding\Services\OffboardingDirectory;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantExports;
use App\Filament\Pages\DataExportPage;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Feature/Offboarding/OffboardingTestHelpers.php';
require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';

/*
 | SaaS.9: an export of a tenant with thousands of files has a manifest of megabytes. MySQL refuses to sort rows larger
 | than its sort buffer ("Out of sort memory"), so every list of exports or deletions, and the certificate's export lookup,
 | must leave those columns out. Found by the performance run (4,893 documents): the deletion failed at its certificate.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency").');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'mail.default' => 'array', 'peopleos.offboarding.deletion_enabled' => true]);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lists exports and deletions, and certifies a deletion, when the export manifest is larger than the sort buffer', function () {
    [$maker, $checker] = exitOperators();
    if (! ($GLOBALS['peopleos_exit_retention'] ?? false)) {
        retentionFor(null, 0, $maker, $checker);
        $GLOBALS['peopleos_exit_retention'] = true;
    }
    $tenant = provisionTenant('Large rows '.uniqid());
    app(TenantContext::class)->runAs($tenant, fn () => employeeWithUser());
    $case = offboardAndClose($tenant, $maker);
    Queue::fake();
    $export = app(TenantExports::class)->request($tenant, $maker, 'Offboarding export (large rows)', $case);
    app(TenantExports::class)->generate($export->id);
    app(TenantExports::class)->markDelivered(app(TenantExports::class)->find($export->id), $maker, 'Handed over (large rows)');
    // A manifest as a tenant with thousands of files has (about 3 MB), and a large checkpoint.
    $files = array_map(fn (int $i) => ['path' => "files/employee_documents/{$i}/document-{$i}.pdf", 'bytes' => 20480, 'sha256' => hash('sha256', (string) $i)], range(1, 20000));
    DB::table('tenant_exports')->where('id', $export->id)->update(['manifest' => json_encode(['files' => $files]), 'checkpoint' => json_encode(['files' => $files])]);
    expect(strlen((string) DB::table('tenant_exports')->where('id', $export->id)->value('manifest')))->toBeGreaterThan(2_000_000);

    $directory = app(OffboardingDirectory::class);
    expect(collect($directory->overview())->firstWhere('tenant.id', $tenant->id)['export']?->id)->toBe($export->id)
        ->and($directory->exports($tenant->id)->pluck('id')->all())->toBe([$export->id])
        ->and(app(TenantContext::class)->runAs($tenant, fn () => (new DataExportPage)->exports()->pluck('id')->all()))->toBe([$export->id]);

    $deletion = approvedDeletion($case->fresh(), $maker, $checker);
    TenantDeletion::query()->withoutTenancy()->whereKey($deletion->id)->update(['scheduled_for' => now()->subMinute()]);
    app(DeletionExecutor::class)->execute($deletion->id);
    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($deletion->certificate['export']['reference'])->toBe($export->reference)
        ->and($directory->deletions($tenant->id)->pluck('id')->all())->toBe([$deletion->id])
        ->and(TenantExport::query()->withoutTenancy()->whereKey($export->id)->value('reference'))->toBe($export->reference);
});

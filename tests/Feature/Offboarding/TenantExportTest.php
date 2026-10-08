<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Offboarding\Enums\ExportStatus;
use App\Domain\Offboarding\Jobs\GenerateTenantExport;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Services\ExportPackager;
use App\Domain\Offboarding\Services\TenantExports;
use App\Domain\Offboarding\Support\Signatures;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.9: a tenant's data export. Complete for the customer, never another tenant's rows or a secret, encrypted, signed,
| resumable, expiring, and downloadable only by the person a short-lived link was made for.
*/

/** Opens the package with its passphrase and returns entry => content. */
function openPackage(TenantExport $export): array
{
    $local = tempnam(sys_get_temp_dir(), 'pkg');
    file_put_contents($local, Storage::disk($export->disk)->get($export->path));
    $zip = new ZipArchive;
    expect($zip->open($local))->toBeTrue();
    $zip->setPassword((string) $export->passphrase);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $out[$name] = $zip->getFromName($name);
    }
    $zip->close();
    unlink($local);

    return $out;
}

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant('Leaving Ltd');
    $this->other = provisionTenant('Other Ltd');
    [$this->operator] = exitOperators();
    $tenants = app(TenantContext::class);
    $tenants->runAs($this->tenant, function () {
        $this->employee = employeeWithUser();
        EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'Holder', 'bank_name' => 'Fictional Bank', 'account_number' => '987654321012', 'ifsc' => 'FICT0000001', 'is_primary' => true]);
        app(Documents::class)->store($this->employee, UploadedFile::fake()->createWithContent('contract.pdf', 'fictional contract bytes'), DocumentType::query()->first(), 'Contract');
        WebhookEndpoint::query()->create(['name' => 'ERP', 'url' => 'https://erp.example.test/hook', 'secret' => 'super-secret-signing-value', 'events' => ['employee.created']]);
        $this->admin = tenantUser($this->tenant, ['data.export'], ['email' => 'owner@leaving.test']);
    });
    $tenants->runAs($this->other, function () {
        $this->otherEmployee = employeeWithUser();
        $this->otherUser = tenantUser($this->other, ['data.export'], ['email' => 'owner@other.test']);
    });
});

it('packages the tenant\'s data, files and manifest, encrypted, with secrets and other tenants left out', function () {
    $export = app(TenantExports::class)->request($this->tenant, $this->operator, 'Customer asked for their data');
    $export = app(TenantExports::class)->find($export->id);

    expect($export->status)->toBe(ExportStatus::Ready, (string) $export->failure)
        ->and($export->sha256)->toBe(hash('sha256', Storage::disk('local')->get($export->path)))
        ->and($export->path)->toStartWith('offboarding-exports/'.$this->tenant->id.'/')
        ->and(Signatures::verify('export-manifest', $export->manifest, $export->manifest_signature))->toBeTrue();
    $files = openPackage($export);
    expect($files)->toHaveKeys(['manifest.json', 'manifest.sig', 'README.txt', 'data/banking/employee_bank_accounts.jsonl', 'data/identity/users.csv']);
    // Every listed file is in the package with the checksum the manifest states.
    foreach ($export->manifest['files'] as $file) {
        expect(hash('sha256', $files[$file['path']]))->toBe($file['sha256'], $file['path']);
    }
    // The customer's own encrypted data is readable; secrets never appear; neither does the other tenant.
    expect($files['data/banking/employee_bank_accounts.jsonl'])->toContain('987654321012');
    $all = implode("\n", $files);
    expect($all)->not->toContain('super-secret-signing-value')
        ->not->toContain('owner@other.test')
        ->and($files['data/identity/users.jsonl'])->not->toContain('"password"')->not->toContain('remember_token')
        ->and(collect($export->manifest['files'])->pluck('path')->filter(fn ($p) => str_starts_with($p, 'files/employee_documents/')))->toHaveCount(1)
        ->and(in_array('fictional contract bytes', $files, true))->toBeTrue();
    // Without the passphrase the entries do not open.
    $local = tempnam(sys_get_temp_dir(), 'pkg');
    file_put_contents($local, Storage::disk('local')->get($export->path));
    $zip = new ZipArchive;
    $zip->open($local);
    expect($zip->getFromName('manifest.json'))->toBeFalse();
    $zip->close();
    unlink($local);

    $actions = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('metadata->export', $export->reference)->pluck('action')->map->value->all();
    expect($actions)->toContain('EXPORT_REQUESTED', 'EXPORT_STARTED', 'EXPORT_COMPLETED');
});

it('keeps one export in flight per tenant: a second request joins it instead of starting a conflicting copy', function () {
    Queue::fake();
    $first = app(TenantExports::class)->request($this->tenant, $this->operator, 'First request');
    $second = app(TenantExports::class)->request($this->tenant, $this->operator, 'Second request');

    expect($second->id)->toBe($first->id)
        ->and(TenantExport::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    // Another tenant has its own slot.
    expect(app(TenantExports::class)->request($this->other, $this->operator, 'Other request')->id)->not->toBe($first->id);
});

it('resumes a stopped export from its checkpoint, verifying what was already written', function () {
    Queue::fake();
    $export = app(TenantExports::class)->request($this->tenant, $this->operator, 'Large export');
    $export = app(TenantExports::class)->find($export->id);
    TenantExport::query()->withoutTenancy()->whereKey($export->id)->update(['status' => 'generating', 'attempts' => 1, 'heartbeat_at' => now(), 'passphrase' => encrypt(Str::password(32, symbols: false), false)]);
    $export = app(TenantExports::class)->find($export->id);

    // The worker writes some tables, then dies (no catch: nothing records a failure).
    $tables = 0;
    try {
        app(ExportPackager::class)->build($export, $this->tenant, (string) $export->passphrase, function (array $checkpoint) use ($export, &$tables) {
            TenantExport::query()->withoutTenancy()->whereKey($export->id)->update(['checkpoint' => json_encode($checkpoint), 'heartbeat_at' => now()]);
            if (++$tables === 5) {
                throw new RuntimeException('worker killed');
            }
        });
    } catch (RuntimeException) {
    }
    expect(count(app(TenantExports::class)->find($export->id)->checkpoint['tables']))->toBeGreaterThanOrEqual(4);

    $this->travel(31)->minutes();
    expect(app(TenantExports::class)->sweep())->toMatchArray(['resumed' => 1]);
    Queue::assertPushed(GenerateTenantExport::class, fn ($job) => $job->exportId === $export->id);
    // The dispatched job's step: the stale export is claimed and resumed.
    expect(app(TenantExports::class)->generate($export->id))->toBeTrue();

    $export = app(TenantExports::class)->find($export->id);
    expect($export->status)->toBe(ExportStatus::Ready, (string) $export->failure)
        ->and($export->attempts)->toBe(2);
    $files = openPackage($export);
    foreach ($export->manifest['files'] as $file) {
        expect(hash('sha256', $files[$file['path']]))->toBe($file['sha256']);
    }
});

it('fails after the maximum attempts, restarts cleanly on retry, and expires a ready package', function () {
    Queue::fake();
    config(['peopleos.offboarding.export_max_attempts' => 2]);
    $export = app(TenantExports::class)->request($this->tenant, $this->operator, 'Export');
    TenantExport::query()->withoutTenancy()->whereKey($export->id)->update(['status' => 'generating', 'attempts' => 2, 'heartbeat_at' => now()->subHour(), 'checkpoint' => json_encode(['tables' => ['x' => []]])]);

    expect(app(TenantExports::class)->sweep())->toMatchArray(['failed' => 1]);
    $export = app(TenantExports::class)->find($export->id);
    expect($export->status)->toBe(ExportStatus::Failed)->and($export->checkpoint)->toBeNull()->and($export->in_flight_key)->toBeNull();

    $export = app(TenantExports::class)->retry($export, $this->operator, 'Storage fixed');
    app(TenantExports::class)->generate($export->id);
    $export = app(TenantExports::class)->find($export->id);
    expect($export->status)->toBe(ExportStatus::Ready);

    $this->travel(8)->days();
    expect(app(TenantExports::class)->sweep())->toMatchArray(['expired' => 1])
        ->and(Storage::disk('local')->exists($export->path))->toBeFalse()
        ->and(app(TenantExports::class)->find($export->id)->status)->toBe(ExportStatus::Expired)
        ->and(app(TenantExports::class)->find($export->id)->sha256)->toBe($export->sha256);
});

it('serves a package only through a short-lived link bound to the person, and records who downloaded it', function () {
    $this->actingAs($this->admin);
    $export = app(TenantExports::class)->request($this->tenant, $this->admin, 'We are leaving');
    $export = app(TenantExports::class)->find($export->id);
    $url = app(TenantExports::class)->downloadUrl($export, $this->admin);

    $this->get($url)->assertOk()->assertDownload("peopleos-export-{$export->reference}.zip");
    $export = app(TenantExports::class)->find($export->id);
    expect($export->download_count)->toBe(1)->and($export->delivered_at)->not->toBeNull();

    // Another person of the same tenant, another tenant, an unsigned or expired link: nothing.
    app(TenantContext::class)->runAs($this->tenant, fn () => $this->colleague = tenantUser($this->tenant, ['data.export']));
    $this->actingAs($this->colleague)->get($url)->assertForbidden();
    $this->actingAs($this->otherUser)->get($url)->assertForbidden();
    $this->actingAs($this->admin)->get(route('offboarding.exports.download', ['reference' => $export->reference, 'u' => $this->admin->id]))->assertForbidden();
    $this->travel(6)->minutes();
    $this->actingAs($this->admin)->get($url)->assertForbidden();
    expect(app(TenantExports::class)->find($export->id)->download_count)->toBe(1);
    expect(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'EXPORT_DOWNLOADED')->where('metadata->export', $export->reference)->count())->toBe(1);
});

it('refuses an export to someone without data.export, to another tenant\'s person, and once the tenant is closed', function () {
    app(TenantContext::class)->runAs($this->tenant, fn () => $this->plain = tenantUser($this->tenant, ['employee.view']));
    $exports = app(TenantExports::class);

    expect(fn () => $exports->request($this->tenant, $this->plain, 'I want it all'))->toThrow(RuntimeException::class, 'cannot')
        ->and(fn () => $exports->request($this->tenant, $this->otherUser, 'Not mine'))->toThrow(RuntimeException::class, 'cannot');

    offboardAndClose($this->tenant, $this->operator);
    expect(fn () => $exports->request(Tenant::query()->find($this->tenant->id), $this->admin, 'After closure'))->toThrow(RuntimeException::class, 'cannot');
    // The operator still can (the offboarding export).
    expect($exports->request(Tenant::query()->find($this->tenant->id), $this->operator, 'Offboarding export')->id)->toBeInt();
});

it('shows the passphrase only to an authorised person, and records each reveal', function () {
    $export = app(TenantExports::class)->request($this->tenant, $this->admin, 'Our own copy');
    $export = app(TenantExports::class)->find($export->id);

    expect(app(TenantExports::class)->revealPassphrase($export, $this->admin))->toBe((string) $export->passphrase)
        ->and(fn () => app(TenantExports::class)->revealPassphrase($export, $this->otherUser))->toThrow(RuntimeException::class);
    expect(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'EXPORT_PASSPHRASE_REVEALED')->count())->toBe(1)
        ->and(json_encode(AuditEvent::query()->withoutTenancy()->where('action', 'EXPORT_PASSPHRASE_REVEALED')->get()->pluck('metadata')))->not->toContain((string) $export->passphrase);
});

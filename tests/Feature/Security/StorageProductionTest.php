<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Support\Storage\StagedUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\UnableToWriteFile;
use Livewire\Livewire;

/*
 * Production readiness closure (blocker 5): private S3-compatible object storage is a supported
 * production backend. No production bucket is reachable here: these tests prove the capability with
 * the real S3 driver offline (adapter, private disk, pre-signed URLs computed locally, failure
 * handling against an unreachable endpoint), plus every storage role on an S3-named disk.
 */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = Employee::factory()->create();
});

it('ships the official S3 adapter with a private, failure-raising s3 disk and offline pre-signed URLs', function () {
    config(['filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), ['key' => 'AKIATESTKEY', 'secret' => 'test-secret', 'region' => 'ap-south-1', 'bucket' => 'peopleos-private'])]);
    expect(class_exists(AwsS3V3Adapter::class))->toBeTrue()
        ->and(config('filesystems.disks.s3.visibility'))->toBe('private')
        ->and(config('filesystems.disks.s3.throw'))->toBeTrue();

    $disk = Storage::disk('s3');
    expect($disk->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class);
    $url = $disk->temporaryUrl('tenants/1/employees/1/doc.pdf', now()->addMinutes(5));
    expect($url)->toContain('X-Amz-Signature=')->toContain('X-Amz-Expires=300')->not->toContain('test-secret');
});

it('fails loudly, and records nothing, when the object store is unreachable', function () {
    config(['filesystems.disks.s3-down' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'ap-south-1', 'bucket' => 'b', 'endpoint' => 'http://127.0.0.1:1',
        'use_path_style_endpoint' => true, 'visibility' => 'private', 'throw' => true, 'retries' => 0, 'http' => ['connect_timeout' => 1, 'timeout' => 2]],
        'peopleos.documents.disk' => 's3-down']);

    expect(fn () => app(Documents::class)->store($this->employee, UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf')))->toThrow(UnableToWriteFile::class);
    expect(EmployeeDocument::query()->count())->toBe(0);
});

it('runs every storage role on object storage: staged upload, tenant-prefixed private object, signed and audited download', function () {
    Storage::fake('s3');
    config(['peopleos.documents.disk' => 's3', 'peopleos.storage.staging_disk' => 's3', 'peopleos.storage.compliance_disk' => 's3']);
    $type = DocumentType::query()->where('code', 'PAN')->first();

    $staged = UploadedFile::fake()->createWithContent('pan.pdf', "%PDF-1.4\n%closure test\n")->store('tmp-uploads', 's3');
    $copy = StagedUpload::toUploadedFile($staged);
    $doc = app(Documents::class)->store($this->employee, $copy, $type);
    StagedUpload::discard($staged, $copy);

    expect($doc->disk)->toBe('s3')->and($doc->path)->toStartWith("tenants/{$this->tenant->id}/employees/{$this->employee->id}/");
    Storage::disk('s3')->assertExists($doc->path);
    Storage::disk('s3')->assertMissing($staged);
    expect(file_exists($copy->getPathname()))->toBeFalse();

    $this->get(route('documents.download', ['document' => $doc]))->assertForbidden();
    $this->get(app(Documents::class)->downloadUrl($doc))->assertOk()->assertDownload();
    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('entity_id', (string) $doc->id)->exists())->toBeTrue();

    // Another tenant's user never reaches it, even with a valid signature.
    $other = provisionTenant('Beta');
    actAsTenant($other);
    $this->actingAs(tenantUser($other, ['*']));
    $this->get(app(Documents::class)->downloadUrl($doc))->assertNotFound();
});

it('uploads from the employee page through the staging disk without any local path', function () {
    Storage::fake('s3');
    config(['peopleos.documents.disk' => 's3', 'peopleos.storage.staging_disk' => 's3']);
    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->callTableAction('upload', data: ['document_type_id' => DocumentType::query()->where('code', 'PAN')->value('id'), 'file' => UploadedFile::fake()->createWithContent('pan.pdf', "%PDF-1.4\n%closure test\n")])
        ->assertHasNoTableActionErrors();

    $doc = EmployeeDocument::query()->sole();
    expect($doc->disk)->toBe('s3');
    Storage::disk('s3')->assertExists($doc->path);
    expect(Storage::disk('s3')->allFiles('tmp-uploads'))->toBe([]);
});

it('refuses disallowed types, active content under an allowed extension, empty and oversized files on the server', function () {
    Storage::fake('local');
    $store = fn (UploadedFile $f) => app(Documents::class)->store($this->employee, $f);

    expect(fn () => $store(UploadedFile::fake()->create('tool.exe', 10)))->toThrow(RuntimeException::class, 'not accepted')
        ->and(fn () => $store(UploadedFile::fake()->createWithContent('invoice.pdf', '<html><script>alert(1)</script></html>')))->toThrow(RuntimeException::class, 'not an accepted document type')
        ->and(fn () => $store(UploadedFile::fake()->createWithContent('vector.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>x</script></svg>')))->toThrow(RuntimeException::class)
        ->and(fn () => $store(UploadedFile::fake()->createWithContent('empty.pdf', '')))->toThrow(RuntimeException::class, 'empty')
        ->and(fn () => $store(UploadedFile::fake()->create('huge.pdf', 1024 * 11, 'application/pdf')))->toThrow(RuntimeException::class, 'too large');
    expect(EmployeeDocument::query()->count())->toBe(0);
});

it('keeps every persistent store on a configured disk: no domain code pins the local disk', function () {
    $pinned = collect(explode("\n", trim((string) shell_exec('grep -rln "disk(\'local\')" '.escapeshellarg(app_path())))))->filter()->values()->all();
    expect($pinned)->toBe([]);
});

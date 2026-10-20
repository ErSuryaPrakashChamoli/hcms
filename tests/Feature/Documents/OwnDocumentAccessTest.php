<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Filament\Pages\MyHr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
 | Phase 12 §25 / closeout §16: the employee's own-document path end to end.
 | Employee → own authorised document (document.own) → private storage → temporary signed link →
 | DOWNLOAD audit. A known document id or a valid link for someone else's document gives nothing, and
 | another tenant gets 404.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->employee = activeEmployee(null, ['document.own', 'task.view']);
    $this->colleague = activeEmployee(null, ['document.own', 'task.view']);
    $pan = DocumentType::query()->where('code', 'PAN')->first();
    $this->mine = app(Documents::class)->store($this->employee, UploadedFile::fake()->create('my-pan.pdf', 10, 'application/pdf'), $pan);
    $this->theirs = app(Documents::class)->store($this->colleague, UploadedFile::fake()->create('their-pan.pdf', 10, 'application/pdf'), $pan);
});

it('lets an employee download their own document through a short-lived signed link, privately stored and audited', function () {
    expect($this->mine->path)->toStartWith("tenants/{$this->tenant->id}/employees/{$this->employee->id}/")
        ->and(Storage::disk('local')->exists($this->mine->path))->toBeTrue();

    $this->actingAs($this->employee->user);
    $this->get(MyHr::getUrl(['tab' => 'documents']))->assertOk()->assertSee('PAN card')->assertDontSee('their-pan.pdf');
    $this->get(app(Documents::class)->downloadUrl($this->mine))->assertOk()->assertDownload('my-pan.pdf');
    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('entity_type', EmployeeDocument::class)->where('entity_id', (string) $this->mine->id)->where('actor_id', $this->employee->user->id)->exists())->toBeTrue();

    // Unsigned, tampered or expired links never serve the file.
    $this->get(route('documents.download', ['document' => $this->mine]))->assertForbidden();
    $url = app(Documents::class)->downloadUrl($this->mine);
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
});

it('never serves another employee\'s document by id, even with a valid signed link for it, nor to another tenant', function () {
    $theirUrl = app(Documents::class)->downloadUrl($this->theirs);

    $this->actingAs($this->employee->user);
    expect($this->employee->user->can('view', $this->theirs))->toBeFalse();
    $this->get($theirUrl)->assertForbidden();
    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('entity_id', (string) $this->theirs->id)->exists())->toBeFalse();

    // Without document.own, not even one's own document (a fresh login: permissions are cached per user instance).
    $this->employee->user->roles()->detach();
    $this->actingAs($this->employee->user->fresh());
    $this->get(app(Documents::class)->downloadUrl($this->mine))->assertForbidden();

    $other = provisionTenant('Other');
    $this->actingAs(tenantUser($other, ['*']));
    $this->get(app(Documents::class)->downloadUrl($this->mine))->assertNotFound();
});

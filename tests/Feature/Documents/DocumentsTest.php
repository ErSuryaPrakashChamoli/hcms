<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Events\DocumentExpiring;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = Employee::factory()->create();
    $this->pan = DocumentType::query()->where('code', 'PAN')->first();
    $this->documents = app(Documents::class);
});

it('stores files privately, versions per type and archives the previous version', function () {
    $v1 = $this->documents->store($this->employee, UploadedFile::fake()->create('pan.pdf', 120, 'application/pdf'), $this->pan, null, null, null, 'Onboarding');
    $v2 = $this->documents->store($this->employee, UploadedFile::fake()->create('pan-new.pdf', 80, 'application/pdf'), $this->pan);

    Storage::disk('local')->assertExists($v1->path);
    expect($v1->path)->toStartWith("tenants/{$this->tenant->id}/employees/{$this->employee->id}/")
        ->and($v1->title)->toBe('PAN card')
        ->and($v1->version)->toBe(1)
        ->and($v2->version)->toBe(2)
        ->and($v1->fresh()->status)->toBe('archived')
        ->and($v2->status)->toBe('pending')
        ->and($v1->auditEvents()->where('action', 'CREATE')->value('reason'))->toBe('Onboarding')
        ->and($v1->auditEvents()->where('action', 'CREATE')->first()->fieldChanges->pluck('field')->all())->not->toContain('path');
});

it('verifies or rejects with a timeline entry', function () {
    $doc = $this->documents->store($this->employee, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $this->pan);

    // Phase 12: the uploader does not verify their own upload; a second person does.
    expect(fn () => $this->documents->review($doc, true, 'Mine'))->toThrow(RuntimeException::class, 'uploaded a document cannot verify it');
    $verifier = tenantUser($this->tenant, ['document.view', 'document.verify']);
    $this->documents->review($doc, true, 'Matches', $verifier);
    expect($doc->fresh()->status)->toBe('verified')
        ->and($doc->fresh()->verified_by)->toBe($verifier->id)
        ->and($this->employee->timelineEntries()->where('category', 'document')->value('title'))->toBe('PAN card verified');

    $other = $this->documents->store($this->employee, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), null, 'Random');
    $this->documents->review($other, false, 'Blurry', $verifier);
    expect($other->fresh()->status)->toBe('rejected')->and($other->fresh()->review_note)->toBe('Blurry');
});

it('serves downloads only through valid signed links to authorised users, auditing sensitive access', function () {
    $doc = $this->documents->store($this->employee, UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf'), $this->pan);
    $url = $this->documents->downloadUrl($doc);

    $this->get(route('documents.download', ['document' => $doc]))->assertForbidden();
    $this->get($url)->assertOk()->assertDownload('pan.pdf');

    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('entity_type', EmployeeDocument::class)->where('entity_id', (string) $doc->id)->exists())->toBeTrue();

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get($url)->assertForbidden();

    $this->travel(11)->minutes();
    $this->actingAs($this->hr);
    $this->get($url)->assertForbidden();
});

it('does not audit views of non-sensitive documents', function () {
    $doc = $this->documents->store($this->employee, UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'), DocumentType::query()->where('code', 'DEGREE')->first());
    $this->get($this->documents->downloadUrl($doc))->assertOk();

    expect(AuditEvent::query()->where('action', 'VIEW')->where('entity_id', (string) $doc->id)->exists())->toBeFalse();
});

it('finds expiring documents and the reminder sweep announces them', function () {
    Event::fake([DocumentExpiring::class]);
    $soon = $this->documents->store($this->employee, UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'), DocumentType::query()->where('code', 'PASSPORT')->first(), null, now()->addDays(10)->toDateString());
    $this->documents->store($this->employee, UploadedFile::fake()->create('q.pdf', 10, 'application/pdf'), DocumentType::query()->where('code', 'DEGREE')->first(), null, now()->addYears(3)->toDateString());

    expect($this->documents->expiringWithin(30)->pluck('id')->all())->toBe([$soon->id]);

    $this->artisan('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    Event::assertDispatched(DocumentExpiring::class, fn ($e) => $e->document->id === $soon->id);
});

it('deletes the file with the record', function () {
    $doc = $this->documents->store($this->employee, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), null, 'X');
    $path = $doc->path;

    $this->documents->delete($doc, 'Wrong upload');

    Storage::disk('local')->assertMissing($path);
    expect(EmployeeDocument::query()->find($doc->id))->toBeNull();
});

<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);

    $this->tenant = provisionTenant();
    $this->tenantB = provisionTenant('Other');
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->agent = tenantUser($this->tenant, ['servicedesk.view', 'employee.view']);
    $this->actingAs($this->hr);
    $this->employee = activeEmployee(null, ['servicedesk.request', 'task.view']);
    $this->stranger = activeEmployee(null, ['servicedesk.request', 'task.view']);
    $this->desk = app(ServiceDesk::class);

    $this->ticket = $this->desk->open($this->employee, TicketCategory::query()->first(), 'Payslip copy', 'Need last payslip');
    Storage::disk('local')->put('servicedesk/payslip.pdf', '%PDF-1.4 test');
    $this->comment = $this->desk->comment($this->ticket, $this->agent, 'Attached.', false, 'servicedesk/payslip.pdf', 'payslip.pdf');
    Storage::disk('local')->put('servicedesk/internal.txt', 'internal');
    $this->internal = $this->desk->comment($this->ticket, $this->agent, 'Internal note', true, 'servicedesk/internal.txt', 'internal.txt');
    $this->url = $this->desk->attachmentUrl($this->comment);
});

it('serves the attachment to the agent and to the employee who owns the ticket, and audits the download', function () {
    $this->actingAs($this->agent)->get($this->url)->assertOk()->assertDownload('payslip.pdf');
    $this->actingAs($this->employee->user)->get($this->url)->assertOk()->assertDownload('payslip.pdf');

    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('entity_type', TicketComment::class)->where('entity_id', (string) $this->comment->id)->count())->toBe(2);
});

it('refuses users who cannot view the ticket and hides internal notes from the employee', function () {
    $this->actingAs($this->stranger->user)->get($this->url)->assertForbidden();
    $this->actingAs($this->employee->user)->get($this->desk->attachmentUrl($this->internal))->assertForbidden();
    $this->actingAs($this->agent)->get($this->desk->attachmentUrl($this->internal))->assertOk();

    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('entity_id', (string) $this->internal->id)->count())->toBe(1);
});

it('fails closed for another tenant, for unsigned or expired links, and for guessed storage paths', function () {
    $other = tenantUser($this->tenantB, ['*']);
    $this->actingAs($other)->get($this->url)->assertNotFound();

    $this->actingAs($this->agent)->get(route('tickets.attachment', ['ticket' => $this->ticket->id, 'comment' => $this->comment->id]))->assertForbidden();
    $this->actingAs($this->agent)->get(route('tickets.attachment', ['ticket' => $this->ticket->id, 'comment' => $this->comment->id, 'signature' => str_repeat('0', 64)]))->assertForbidden();

    // Guessed storage paths: the private disk is never served without a signature (403 from the
    // framework's local-file route in development, 404 where no such route exists).
    expect($this->get('/storage/servicedesk/payslip.pdf')->status())->toBeIn([403, 404]);
    $this->get('/servicedesk/payslip.pdf')->assertNotFound();

    $this->travel(16)->minutes();
    $this->actingAs($this->agent)->get($this->url)->assertForbidden();

    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('entity_type', TicketComment::class)->count())->toBe(0);
});

it('does not link attachments through the storage url helper anywhere in the application', function () {
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/Storage::(disk\([^)]*\)->)?url\(/', file_get_contents($file->getPathname()))) {
            $hits[] = $file->getPathname();
        }
    }

    expect($hits)->toBe([]);
});

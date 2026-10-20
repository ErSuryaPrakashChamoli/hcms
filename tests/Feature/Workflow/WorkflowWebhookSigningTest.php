<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Integration\Support\Signature;
use App\Domain\Workflow\Jobs\SendWebhook;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Services\WorkflowWebhookSigning;
use App\Filament\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Resources\Workflows\WorkflowResource;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/WorkflowTestHelpers.php';

/*
| SaaS.2 §15: workflow webhook nodes sign every request (same scheme as PeopleOS outbound webhooks), keep one
| delivery id across retries, cannot be made to send a forged signature header, and keep a per-workflow,
| encrypted, audited, rotatable secret.
*/

beforeEach(function () {
    $this->travelTo('2026-10-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = employeeWithUser();
    [$nodes, $edges] = linear([['id' => 'hook', 'type' => 'webhook', 'name' => 'Hook', 'config' => ['url' => 'https://it.example.test/hooks', 'method' => 'POST', 'headers' => ['X-Key' => 'abc', 'X-PeopleOS-Signature' => 'sha256=forged']]]]);
    $this->workflow = publishWorkflow($nodes, $edges);
});

it('signs the exact body it sends, so a receiver can verify it with the workflow secret', function () {
    Http::fake(['it.example.test/*' => Http::response(['ok' => true], 200)]);
    app(WorkflowEngine::class)->start($this->workflow, $this->employee);
    $secret = app(WorkflowWebhookSigning::class)->secretFor($this->workflow);

    Http::assertSent(function (Request $request) use ($secret) {
        return $request->hasHeader('X-Key', 'abc')
            && $request->header('X-PeopleOS-Event')[0] === 'workflow.webhook'
            && strlen($request->header('X-PeopleOS-Delivery')[0]) === 26
            && $request->header('X-PeopleOS-Signature')[0] !== 'sha256=forged'
            && Signature::check($secret, $request->header('X-PeopleOS-Timestamp')[0], $request->body(), $request->header('X-PeopleOS-Signature')[0]) === 'valid'
            && $request['employee']['code'] === $this->employee->employee_code;
    });
});

it('keeps one delivery id across retries, with a fresh timestamp and signature each time', function () {
    Queue::fake([SendWebhook::class]);
    $instance = app(WorkflowEngine::class)->start($this->workflow, $this->employee);
    Queue::assertPushed(SendWebhook::class, 1);
    // The job as a worker runs it: the first attempt fails, the retry runs the same job instance again.
    Http::fakeSequence('it.example.test/*')->push(['error' => true], 500)->push(['ok' => true], 200);
    $job = new SendWebhook($instance, 'hook', 'https://it.example.test/hooks', 'POST', [], ['event' => 'workflow.hook']);

    expect(fn () => $job->handle())->toThrow(RequestException::class);
    $this->travel(70)->seconds();
    $job->handle();

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect($sent->map(fn (Request $r) => $r->header('X-PeopleOS-Delivery')[0])->unique()->all())->toBe([$job->deliveryId])
        ->and($sent->map(fn (Request $r) => $r->header('X-PeopleOS-Timestamp')[0])->unique())->toHaveCount(2);
});

it('keeps the secret encrypted, per workflow and per tenant, and creates it once', function () {
    $signing = app(WorkflowWebhookSigning::class);
    $first = $signing->secretFor($this->workflow);
    expect($signing->secretFor($this->workflow->fresh()))->toBe($first)
        ->and(DB::table('workflows')->where('id', $this->workflow->id)->value('webhook_signing_secret'))->not->toBe($first)
        ->and($this->workflow->fresh()->toArray())->not->toHaveKey('webhook_signing_secret');

    [$nodes, $edges] = linear([['id' => 'hook', 'type' => 'webhook', 'name' => 'Hook', 'config' => ['url' => 'https://b.example.test/x', 'method' => 'POST']]]);
    $other = publishWorkflow($nodes, $edges, ['key' => 'second_flow']);
    expect($signing->secretFor($other))->not->toBe($first);

    $generated = AuditEvent::query()->withoutTenancy()->where('action', 'SIGNING_SECRET_ROTATED')->get();
    expect($generated)->toHaveCount(2)->and($generated->toJson())->not->toContain($first);
});

it('rotates the secret with a reason, audits who saw or changed it, and refuses people who cannot edit workflows', function () {
    $signing = app(WorkflowWebhookSigning::class);
    $old = $signing->secretFor($this->workflow);
    $body = '{"x":1}';
    $oldSignature = Signature::sign($old, '1760000000', $body);

    // Opening the secret (the modal's content) goes through reveal(), which is audited.
    Livewire::test(EditWorkflow::class, ['record' => $this->workflow->getRouteKey()])->assertActionVisible('webhookSigningSecret');
    expect($signing->reveal($this->workflow, $this->hr))->toBe($old);
    Livewire::test(EditWorkflow::class, ['record' => $this->workflow->getRouteKey()])
        ->callAction('rotateWebhookSigningSecret', data: ['audit_reason' => 'Receiver compromised'])->assertHasNoActionErrors();

    $new = $signing->secretFor($this->workflow->fresh());
    expect($new)->not->toBe($old)
        ->and(Signature::check($new, '1760000000', $body, $oldSignature, 10 ** 9))->toBe('invalid_signature');
    $events = AuditEvent::query()->withoutTenancy()->whereIn('action', ['VIEW', 'SIGNING_SECRET_ROTATED'])->where('entity_type', Workflow::class)->get();
    expect($events->firstWhere(fn ($e) => ($e->metadata['event'] ?? null) === 'revealed')?->actor_id)->toBe($this->hr->id)
        ->and($events->firstWhere(fn ($e) => ($e->metadata['event'] ?? null) === 'rotated')?->reason)->toBe('Receiver compromised');

    // Someone who can only view workflows never reaches the editor that holds these actions.
    $this->actingAs(tenantUser($this->tenant, ['workflow.view']));
    $this->get(WorkflowResource::getUrl('edit', ['record' => $this->workflow]))->assertForbidden();
});

it('keeps another tenant away from a workflow and its secret', function () {
    $secret = app(WorkflowWebhookSigning::class)->secretFor($this->workflow);
    $other = provisionTenant('Other');
    actAsTenant($other);
    $this->actingAs(tenantUser($other, ['*']));

    expect(Workflow::query()->find($this->workflow->id))->toBeNull();
    $this->get(WorkflowResource::getUrl('edit', ['record' => $this->workflow]))->assertNotFound();
    expect(AuditEvent::query()->withoutTenancy()->where('tenant_id', $other->id)->get()->toJson())->not->toContain($secret);
});

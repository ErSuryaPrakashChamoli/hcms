<?php

use App\Domain\Organisation\Models\Company;
use App\Domain\Workflow\Jobs\SendWebhook;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Tenancy\Exceptions\MissingTenantException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\TenantAwareTestJob;
use Tests\Support\UnboundTestJob;

require_once __DIR__.'/WorkflowTestHelpers.php';

/* Phase 0.2 queue verification: a queued job re-binds the tenant it was dispatched from. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
});

it('re-binds the dispatching tenant inside a worker so tenant-owned writes are stamped and scoped', function () {
    $job = new TenantAwareTestJob('Created by worker');
    expect($job->tenantId)->toBe($this->tenant->id);

    // Simulate a worker: serialise through the sync queue and run with no tenant bound.
    actAsTenant(null);
    auth()->logout();
    Bus::dispatchSync($job);

    expect(app(TenantContext::class)->has())->toBeFalse();

    actAsTenant($this->tenant);
    expect(Company::query()->where('name', 'Created by worker')->value('tenant_id'))->toBe($this->tenant->id);
});

it('captures the tenant on the webhook job, survives serialisation, and logs the outcome on the right tenant', function () {
    Http::fake(['https://it.example.test/*' => Http::response(['ok' => true], 200)]);

    [$nodes, $edges] = linear([['id' => 'hook', 'type' => 'webhook', 'name' => 'Hook', 'config' => ['url' => 'https://it.example.test/hooks', 'method' => 'POST']]]);
    $workflow = publishWorkflow($nodes, $edges);
    $instance = app(WorkflowEngine::class)->start($workflow, null, ['note' => 'x']);

    $job = new SendWebhook($instance, 'hook', 'https://it.example.test/hooks', 'POST', [], ['event' => 'replay']);
    expect($job->tenantId)->toBe($this->tenant->id);

    // Round-trip through the serialiser exactly as the database queue would, then run it unbound.
    $restored = unserialize(serialize($job));
    actAsTenant(null);
    auth()->logout();
    Bus::dispatchSync($restored);
    expect(app(TenantContext::class)->has())->toBeFalse();

    actAsTenant($this->tenant);
    $sent = WorkflowAction::query()->where('workflow_instance_id', $instance->id)->where('action', 'webhook.sent')->get();
    expect($sent)->toHaveCount(2)->and($sent->pluck('tenant_id')->unique()->all())->toBe([$this->tenant->id]);
});

it('fails closed when a job without tenant context touches tenant-owned data', function () {
    actAsTenant(null);
    auth()->logout();

    expect(fn () => Bus::dispatchSync(new UnboundTestJob))->toThrow(MissingTenantException::class);

    actAsTenant($this->tenant);
    expect(Company::query()->where('name', 'Should not exist')->exists())->toBeFalse();
});

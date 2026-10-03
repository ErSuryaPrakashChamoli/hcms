<?php

namespace App\Domain\Workflow\Jobs;

use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Support\Http\SafeHttp;
use App\Support\Http\UnsafeOutboundUrl;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Webhook node: POST/PUT JSON to an external system; the outcome is logged on the run. */
/**
 * Production readiness closure: encrypted on the queue (ShouldBeEncrypted), because the payload carries
 * the configured URL, headers (possibly Authorization) and body.
 */
class SendWebhook implements ShouldBeEncrypted, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> Phase 14: retry with backoff instead of hammering a failing dependency. */
    public array $backoff = [60, 300];

    /** Tenant captured at dispatch; re-bound by BindTenantContext inside the worker. */
    public ?int $tenantId;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly string $nodeId,
        public readonly string $url,
        public readonly string $method,
        public readonly array $headers,
        public readonly array $payload,
    ) {
        $this->tenantId = $instance->tenant_id ?? app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(): void
    {
        // Production readiness closure: SSRF guard after DNS, connection pinned, no redirects. A blocked
        // destination is logged on the run and never retried.
        try {
            $request = app(SafeHttp::class)->to($this->url);
        } catch (UnsafeOutboundUrl $e) {
            WorkflowAction::create(['workflow_instance_id' => $this->instance->id, 'node_id' => $this->nodeId, 'action' => 'webhook.blocked',
                'payload' => ['reason' => $e->reason, 'url' => SafeHttp::redact($this->url)], 'created_at' => now()]);

            return;
        }
        $response = $request->withHeaders($this->headers)->timeout(15)->send(strtoupper($this->method), $this->url, ['json' => $this->payload]);

        WorkflowAction::create([
            'workflow_instance_id' => $this->instance->id,
            'node_id' => $this->nodeId,
            'action' => $response->successful() ? 'webhook.sent' : 'webhook.failed',
            // The configured URL is never stored in full: paths and queries can carry tokens.
            'payload' => ['status' => $response->status(), 'url' => SafeHttp::redact($this->url)],
            'created_at' => now(),
        ]);

        if (! $response->successful()) {
            $response->throw();
        }
    }
}

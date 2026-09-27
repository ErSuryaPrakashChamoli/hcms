<?php

namespace App\Domain\Workflow\Jobs;

use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** Webhook node: POST/PUT JSON to an external system; the outcome is logged on the run. */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

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

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(): void
    {
        $response = Http::withHeaders($this->headers)->timeout(15)->send(strtoupper($this->method), $this->url, ['json' => $this->payload]);

        WorkflowAction::create([
            'workflow_instance_id' => $this->instance->id,
            'node_id' => $this->nodeId,
            'action' => $response->successful() ? 'webhook.sent' : 'webhook.failed',
            'payload' => ['status' => $response->status(), 'url' => $this->url],
            'created_at' => now(),
        ]);

        if (! $response->successful()) {
            $response->throw();
        }
    }
}

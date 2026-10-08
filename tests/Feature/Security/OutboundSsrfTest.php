<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\Sso;
use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Workflow\Jobs\SendWebhook;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Http\HostResolver;
use App\Support\Http\OutboundUrlGuard;
use App\Support\Http\SafeHttp;
use App\Support\Http\UnsafeOutboundUrl;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\Support\FakeHostResolver;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
 * Production readiness closure (blocker 2): every outbound request to a tenant-configured destination
 * (webhook endpoints, workflow webhook nodes, SSO token / userinfo endpoints) passes the SSRF guard
 * after DNS, is pinned to the validated address, never follows redirects, and leaks no secret.
 */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->dns = new FakeHostResolver([
        'hooks.example.test' => [FakeHostResolver::PUBLIC_IP],
        'internal-alias.example.test' => ['10.0.0.8'],
        'mixed.example.test' => ['93.184.215.14', '192.168.1.20'],
        'v6-private.example.test' => ['fd00::12'],
        'nowhere.example.test' => [],
        'rebind.example.test' => [[FakeHostResolver::PUBLIC_IP], ['127.0.0.1']],
    ]);
    $this->app->instance(HostResolver::class, $this->dns);
    $this->guard = app(OutboundUrlGuard::class);
});

function refused(string $url): ?string
{
    try {
        app(OutboundUrlGuard::class)->inspect($url);

        return null;
    } catch (UnsafeOutboundUrl $e) {
        return $e->reason;
    }
}

it('refuses loopback, private, link-local, metadata, unspecified and reserved destinations, IPv4 and IPv6', function () {
    expect(refused('https://localhost/hook'))->toBe('internal_host')
        ->and(refused('https://sub.localhost/hook'))->toBe('internal_host')
        ->and(refused('https://127.0.0.1/hook'))->toBe('private_address')
        ->and(refused('https://127.9.9.9/hook'))->toBe('private_address')
        ->and(refused('https://[::1]/hook'))->toBe('private_address')
        ->and(refused('https://10.1.2.3/hook'))->toBe('private_address')
        ->and(refused('https://172.16.5.4/hook'))->toBe('private_address')
        ->and(refused('https://192.168.0.10/hook'))->toBe('private_address')
        ->and(refused('https://100.64.0.1/hook'))->toBe('private_address')
        ->and(refused('https://[fd12:3456::1]/hook'))->toBe('private_address')
        ->and(refused('https://169.254.10.10/hook'))->toBe('private_address')
        ->and(refused('https://[fe80::1]/hook'))->toBe('private_address')
        ->and(refused('https://169.254.169.254/latest/meta-data/'))->toBe('private_address')
        ->and(refused('https://[fd00:ec2::254]/latest/meta-data/'))->toBe('private_address')
        ->and(refused('https://metadata.google.internal/computeMetadata/v1/'))->toBe('internal_host')
        ->and(refused('https://0.0.0.0/hook'))->toBe('private_address')
        ->and(refused('https://[::]/hook'))->toBe('private_address')
        ->and(refused('https://[::ffff:127.0.0.1]/hook'))->toBe('private_address')
        ->and(refused('https://[64:ff9b::a00:1]/hook'))->toBe('private_address')
        ->and(refused('https://[2002:a00:1::1]/hook'))->toBe('private_address')
        ->and(refused('https://224.0.0.1/hook'))->toBe('private_address')
        ->and(refused('https://2130706433/hook'))->toBe('numeric_host')
        ->and(refused('https://127.1/hook'))->toBe('numeric_host')
        ->and(refused('https://0x7f.0.0.1/hook'))->toBe('numeric_host')
        ->and(refused('https://intranet/hook'))->toBe('internal_host')
        ->and(refused('https://printer.local/hook'))->toBe('internal_host');
});

it('refuses unsafe schemes, credentials and ports, and allows a public HTTPS destination', function () {
    expect(refused('http://hooks.example.test/hook'))->toBe('scheme')
        ->and(refused('ftp://hooks.example.test/x'))->toBe('scheme')
        ->and(refused('gopher://hooks.example.test/x'))->toBe('scheme')
        ->and(refused('file:///etc/passwd'))->toBe('invalid_url')
        ->and(refused('https://user:pass@hooks.example.test/hook'))->toBe('credentials')
        ->and(refused('https://hooks.example.test:22/hook'))->toBe('port')
        ->and(refused('not a url'))->toBe('invalid_url')
        ->and(refused('https://hooks.example.test/hook'))->toBeNull()
        ->and(refused('https://93.184.215.14:8443/hook'))->toBeNull()
        ->and(refused('https://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]/hook'))->toBeNull();
    config(['peopleos.outbound.allow_http' => true]);
    expect(refused('http://hooks.example.test/hook'))->toBeNull();
});

it('checks every address the name resolves to, after DNS', function () {
    expect(refused('https://internal-alias.example.test/hook'))->toBe('private_address')
        ->and(refused('https://mixed.example.test/hook'))->toBe('private_address')
        ->and(refused('https://v6-private.example.test/hook'))->toBe('private_address')
        ->and(refused('https://nowhere.example.test/hook'))->toBe('unresolvable');

    // An operator (never a tenant) may exempt an exact host name, for example an on-premises receiver.
    config(['peopleos.outbound.allowed_hosts' => ['internal-alias.example.test']]);
    expect(refused('https://internal-alias.example.test/hook'))->toBeNull();
});

it('pins the connection to the validated address so DNS rebinding cannot redirect it', function () {
    $target = $this->guard->inspect('https://rebind.example.test/hook');
    $options = app(SafeHttp::class)->options($target);

    expect($target['ip'])->toBe(FakeHostResolver::PUBLIC_IP)
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['rebind.example.test:443:'.FakeHostResolver::PUBLIC_IP])
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($this->dns->lookups['rebind.example.test'])->toBe(1);
    // The next lookup of the same name answers 127.0.0.1: a second check refuses it, and a pinned request never asks again.
    expect(refused('https://rebind.example.test/hook'))->toBe('private_address');
});

it('never follows a redirect, including one into a private network', function () {
    $endpoint = WebhookEndpoint::create(['name' => 'Sink', 'url' => 'https://hooks.example.test/in', 'secret' => 'endpoint-secret-xyz', 'events' => ['*']]);
    Http::fakeSequence('hooks.example.test/*')->push('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])->push('ok', 200);
    app(Webhooks::class)->publish('employee.updated', ['code' => 'E1']);
    $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();

    expect(app(Webhooks::class)->deliver($delivery->id))->toBe('retrying')
        ->and($delivery->refresh()->status)->toBe('pending')->and($delivery->response_code)->toBe(302);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
});

it('blocks webhook delivery to a name that resolves privately, sends nothing, audits the block and keeps secrets out of records and logs', function () {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    Http::fake();
    $endpoint = WebhookEndpoint::create(['name' => 'Alias', 'url' => 'https://internal-alias.example.test/in?token=path-secret-123', 'secret' => 'endpoint-secret-xyz', 'events' => ['*']]);
    app(Webhooks::class)->publish('employee.updated', ['code' => 'E1']);
    $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();
    app(Webhooks::class)->deliver($delivery->id);

    Http::assertNothingSent();
    $audit = AuditEvent::query()->where('action', 'OUTBOUND_DESTINATION_BLOCKED')->sole();
    expect($delivery->refresh()->response_excerpt)->toStartWith('Blocked:')
        ->and($audit->metadata['reason'])->toBe('private_address')
        ->and(json_encode($audit->metadata).$delivery->response_excerpt)->not->toContain('path-secret-123')->not->toContain('endpoint-secret-xyz')->not->toContain('10.0.0.8');
    expect(collect($handler->getRecords())->map(fn ($r) => $r->message.json_encode($r->context))->implode("\n"))
        ->not->toContain('endpoint-secret-xyz')->not->toContain('path-secret-123')->not->toContain('X-PeopleOS-Signature');
});

it('keeps endpoint secrets, signatures and URL tokens out of delivery records when the receiver or transport fails', function () {
    $endpoint = WebhookEndpoint::create(['name' => 'Sink', 'url' => 'https://hooks.example.test/in/path-secret-456', 'secret' => 'endpoint-secret-xyz', 'events' => ['*']]);
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout for https://hooks.example.test/in/path-secret-456'));
    app(Webhooks::class)->publish('employee.updated', ['code' => 'E1']);
    $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();
    app(Webhooks::class)->deliver($delivery->id);

    expect($delivery->refresh()->response_excerpt)->not->toContain('path-secret-456')->not->toContain('endpoint-secret-xyz')->toContain('hooks.example.test')
        ->and(json_encode($delivery->payload))->not->toContain('endpoint-secret-xyz');
});

it('refuses unsafe destinations when they are saved, not only when they are called', function () {
    expect(fn () => WebhookEndpoint::create(['name' => 'Meta', 'url' => 'https://169.254.169.254/x', 'secret' => 's', 'events' => ['*']]))->toThrow(UnsafeOutboundUrl::class)
        ->and(fn () => WebhookEndpoint::create(['name' => 'Local', 'url' => 'https://localhost:8443/x', 'secret' => 's', 'events' => ['*']]))->toThrow(UnsafeOutboundUrl::class)
        ->and(fn () => SsoConnection::create(['name' => 'IdP', 'client_id' => 'x', 'client_secret' => 'y', 'authorization_url' => 'https://idp.example.test/a', 'token_url' => 'https://10.0.0.1/token', 'userinfo_url' => 'https://idp.example.test/u']))->toThrow(UnsafeOutboundUrl::class);
});

it('blocks a workflow webhook node to a private destination without sending, retrying or storing the URL token, and encrypts the job', function () {
    Http::fake();
    [$nodes, $edges] = linear([['id' => 'wait', 'type' => 'wait', 'name' => 'Wait', 'config' => ['days' => 1]]]);
    $instance = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), Employee::factory()->create());
    (new SendWebhook($instance, 'node-1', 'https://internal-alias.example.test/hook?key=wf-secret-789', 'post', ['Authorization' => 'Bearer wf-token-000'], ['a' => 1]))->handle();

    Http::assertNothingSent();
    $action = WorkflowAction::query()->where('workflow_instance_id', $instance->id)->where('node_id', 'node-1')->sole();
    expect($action->action)->toBe('webhook.blocked')->and(json_encode($action->payload))->not->toContain('wf-secret-789')->not->toContain('wf-token-000')
        ->and(is_subclass_of(SendWebhook::class, ShouldBeEncrypted::class))->toBeTrue();

    Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
    (new SendWebhook($instance, 'node-2', 'https://hooks.example.test/hook?key=wf-secret-789', 'post', [], ['a' => 1]))->handle();
    expect(WorkflowAction::query()->where('node_id', 'node-2')->sole()->action)->toBe('webhook.sent')
        ->and(json_encode(WorkflowAction::query()->where('node_id', 'node-2')->sole()->payload))->not->toContain('wf-secret-789');
});

it('blocks SSO token exchange against an IdP endpoint that resolves privately', function () {
    Http::fake();
    $connection = SsoConnection::create(['name' => 'IdP', 'client_id' => 'x', 'client_secret' => 'client-secret-abc', 'authorization_url' => 'https://idp.example.test/a', 'token_url' => 'https://internal-alias.example.test/token', 'userinfo_url' => 'https://idp.example.test/u']);

    expect(fn () => app(Sso::class)->identity($connection, 'code', 'https://app.example.test/callback'))->toThrow(RuntimeException::class, 'not an allowed destination');
    Http::assertNothingSent();
});

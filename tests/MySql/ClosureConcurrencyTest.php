<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Employment\Models\Employee;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\IntegrationSystems;
use App\Domain\Integration\Support\Signature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Production readiness closure: real MySQL concurrency for the signed BGV callback. Same harness and
 | opt-in as the earlier suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency").
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant('Closure race '.uniqid());
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->case = app(Bgv::class)->initiate(Employee::factory()->create(), ['identity', 'address'], 'manual', true);
    $this->case->update(['external_reference' => 'RACE-'.$this->case->id]);
    $this->key = app(ApiKeys::class)->issue('BGV race', ['bgv.write']);
    $this->secret = app(IntegrationSystems::class)->create(['code' => 'bgv'.random_int(100, 999), 'name' => 'BGV', 'kind' => 'bgv', 'api_key_id' => $this->key['key']->id], tenantUser($this->tenant, ['integration.manage']))['secret'];
    auth()->logout();
    $this->post = function (string $body, array $extra = []) {
        $ts = (string) now()->getTimestamp();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_API_KEY' => $this->key['plaintext'],
            'HTTP_X_PEOPLEOS_TIMESTAMP' => $ts, 'HTTP_X_PEOPLEOS_SIGNATURE' => Signature::sign($this->secret, $ts, $body)] + $extra;
        actAsTenant(null);

        return $this->call('POST', '/api/v1/bgv/cases/'.$this->case->external_reference.'/checks', [], [], [], $server, $body);
    };
});

afterEach(function () {
    if (isset($this->tenant)) {
        expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
    }
});

function closureSlowEvents(): array
{
    return ['eloquent.creating: '.InboundEvent::class, 'eloquent.updating: '.InboundEvent::class, 'eloquent.updating: '.BgvCheck::class];
}

it('C1. applies one signed BGV callback once when the same request arrives twice at once (replay race)', function () {
    $body = json_encode(['checks' => [['type' => 'identity', 'status' => 'clear'], ['type' => 'address', 'status' => 'clear']]]);
    $send = fn () => tap(($this->post)($body), fn ($r) => in_array($r->status(), [200, 202], true) || throw new RuntimeException('status '.$r->status().' '.$r->content()));

    expect(race([$send, $send], slow: closureSlowEvents()))->toBe(['ok', 'ok'])
        ->and(InboundEvent::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('tenant_id', $this->tenant->id)->where('action', 'INTEGRATION_EVENT_PROCESSED')->count())->toBe(1)
        ->and(BgvCheck::query()->withoutGlobalScopes()->where('bgv_case_id', $this->case->id)->pluck('status')->unique()->all())->toBe(['clear']);
});

it('C2. never applies two different bodies under one Idempotency-Key, even at the same moment', function () {
    $clear = json_encode(['checks' => [['type' => 'identity', 'status' => 'clear']]]);
    $failed = json_encode(['checks' => [['type' => 'identity', 'status' => 'failed']]]);
    $send = fn (string $body) => fn () => tap(($this->post)($body, ['HTTP_IDEMPOTENCY_KEY' => 'same-key-1']), fn ($r) => $r->status() === 200 || throw new RuntimeException((string) $r->status()));
    $results = race([$send($clear), $send($failed)], slow: closureSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->reject(fn ($r) => $r === 'ok')->first())->toBe('409')
        ->and(InboundEvent::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

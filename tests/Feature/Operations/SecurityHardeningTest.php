<?php

use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Workflow\Jobs\SendWebhook;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| SaaS.8 security hardening: browser security headers, an API rate limit that only a verified key can claim, workflow
| webhook payloads cleaned like every outbound payload, and payroll four-eyes without an exception for operators.
*/

it('sends the security headers on panel, API and health responses, and HSTS only over HTTPS', function () {
    foreach (['/admin/login', '/api/v1/employees', '/health/live'] as $path) {
        $response = $this->get($path);
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    $this->get('https://localhost/health/live')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    config(['peopleos.security.hsts_max_age' => 0]);
    $this->get('https://localhost/health/live')->assertHeaderMissing('Strict-Transport-Security');
});

it('gives a key its own rate-limit bucket only once verified, so a known prefix cannot exhaust a tenant\'s limit', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $issued = app(ApiKeys::class)->issue('Reader', ['employees.read']);
    actAsTenant(null);
    [$prefix] = explode('.', $issued['plaintext'], 2);
    config(['peopleos.api.rate_limit_per_minute' => 2]);

    // An attacker who saw the prefix sends garbage secrets from another address: their own IP bucket runs out, not the key's.
    foreach ([401, 401, 429] as $status) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeader('X-Api-Key', $prefix.'.'.Str::random(40))->getJson('/api/v1/employees')->assertStatus($status);
    }
    $this->flushHeaders();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeader('X-Api-Key', $issued['plaintext'])->getJson('/api/v1/employees')->assertOk();

    // Fresh random tokens no longer get fresh buckets: they share the caller's IP bucket.
    $this->flushHeaders();
    foreach ([401, 401, 429] as $status) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])->withHeader('X-Api-Key', 'pk_'.Str::lower(Str::random(10)).'.'.Str::random(40))->getJson('/api/v1/employees')->assertStatus($status);
    }
});

it('cleans the workflow webhook payload like every payload that leaves PeopleOS', function () {
    Queue::fake([SendWebhook::class]);
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $this->actingAs(tenantUser($tenant, ['*']));
    $employee = employeeWithUser();
    [$nodes, $edges] = linear([['id' => 'hook', 'type' => 'webhook', 'name' => 'Hook', 'config' => ['url' => 'https://it.example.test/hooks', 'method' => 'POST']]]);

    app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $employee, ['laptop' => 'MacBook', 'salary' => 1200000, 'bank' => ['account_number' => '1234567890', 'ifsc' => 'HDFC0000001']]);

    Queue::assertPushed(SendWebhook::class, function (SendWebhook $job) {
        return $job->payload['context']['laptop'] === 'MacBook'
            && ! array_key_exists('salary', $job->payload['context'])
            && $job->payload['context']['bank'] === []
            && $job->payload['employee']['code'] !== null;
    });
});

it('refuses a platform operator who prepared a payroll run the approval of that run', function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $operator = platformAdmin();
    $this->actingAs($operator);
    $company = payrollCompany();
    salariedEmployee(600000);
    $runs = app(PayrollRuns::class);

    $run = $runs->validate($runs->calculate($runs->open($company, 2026, 9, $operator), $operator));

    expect(fn () => $runs->approve($run, $operator))->toThrow(RuntimeException::class, 'cannot approve')
        ->and($run->fresh()->status)->toBe('validated');
    expect($runs->approve($run->fresh(), platformAdmin(), 'Second operator')->status)->toBe('approved');
});

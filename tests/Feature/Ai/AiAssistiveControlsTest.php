<?php

use App\Domain\Ai\Policies\AiInteractionPolicy;
use App\Domain\Ai\Providers\AiProvider;
use App\Domain\Ai\Services\AiDataPolicy;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
 * Phase 14.5 AI assistive controls: the data boundary (allowed / restricted / prohibited), the
 * tenant external-data policy, AI-generated marking, proposals that only link to existing screens,
 * the rate limit, the governance log restricted to ai.admin, domain gates in the HR Copilot, and
 * invariant 19 (AI cannot directly mutate protected domains).
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['ai.use', 'kb.view', 'task.view'], '2025-01-01');
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    app(LeaveAccrual::class)->accrue($this->employee);
    RateLimiter::clear('ai-gateway:'.$this->tenant->id.':'.$this->employee->user_id);
});

function enableLanguageModel(): void
{
    config(['peopleos.ai.provider' => 'anthropic', 'peopleos.ai.anthropic.key' => 'test-key']);
    app()->forgetInstance(AiProvider::class);
    TenantFeature::query()->updateOrCreate(['feature' => 'ai.llm'], ['enabled' => true]);
    app(FeatureFlags::class)->forget();
}

it('classifies data as allowed, restricted or prohibited by key and by value', function () {
    $policy = app(AiDataPolicy::class);
    expect($policy->classifyKey('password'))->toBe('prohibited')->and($policy->classifyKey('apiKey'))->toBe('prohibited')->and($policy->classifyKey('access_token'))->toBe('prohibited')
        ->and($policy->classifyKey('bank_account_number'))->toBe('restricted')->and($policy->classifyKey('pan'))->toBe('restricted')->and($policy->classifyKey('people_cost_series'))->toBe('restricted')
        ->and($policy->classifyKey('company'))->toBe('allowed')->and($policy->classifyKey('pending'))->toBe('allowed')->and($policy->classifyKey('balances'))->toBe('allowed');
    expect($policy->classifyText('key pk_live_abcdef123456'))->toBe('prohibited')->and($policy->classifyText('password: hunter2'))->toBe('prohibited')
        ->and($policy->classifyText('PAN ABCDE1234F'))->toBe('restricted')->and($policy->classifyText('IFSC HDFC0001234'))->toBe('restricted')
        ->and($policy->classifyText('24 days of earned leave'))->toBe('allowed');

    $facts = ['balances' => ['EL' => 24], 'token' => 'abc', 'pan' => 'ABCDE1234F', 'note' => 'call 9876543210', 'team' => ['Asha: whsec_abcdefghijklmnop']];
    $allowed = $policy->prepare($facts, 'allowed');
    expect($allowed['facts'])->not->toHaveKey('token')->and($allowed['facts']['pan'])->toBe(AiDataPolicy::REDACTED)->and($allowed['facts']['balances'])->toBe(['EL' => 24])
        ->and(json_encode($allowed['facts']))->not->toContain('whsec_')->not->toContain('9876543210')
        ->and($allowed['removed'])->toBe(2)->and($allowed['redacted'])->toBeGreaterThanOrEqual(2);
    $restricted = $policy->prepare($facts, 'restricted');
    expect($restricted['facts']['pan'])->toBe('ABCDE1234F')->and($restricted['facts'])->not->toHaveKey('token')->and(json_encode($restricted['facts']))->not->toContain('whsec_');

    // An unknown tenant value fails closed to "none".
    app(SettingsRepository::class)->set('ai.external_data_policy', 'everything');
    expect($policy->tenantPolicy())->toBe('none');
});

it('sends only policy-cleared facts to the provider, marks the text AI-generated and audits the call without content', function () {
    enableLanguageModel();
    Http::fake([config('peopleos.ai.anthropic.endpoint') => Http::response(['model' => 'claude-sonnet-5', 'content' => [['type' => 'text', 'text' => 'You have 24 days.']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 4]])]);

    $interaction = app(AiGateway::class)->ask($this->employee->user, 'employee', 'My PAN is ABCDE1234F and password: hunter2 — how many leaves do I have?');
    Http::assertSent(fn ($request) => ! str_contains($request->body(), 'ABCDE1234F') && ! str_contains($request->body(), 'hunter2') && str_contains($request->body(), 'balances'));
    expect($interaction->ai_generated)->toBeTrue()->and($interaction->provider)->toBe('anthropic')
        ->and($interaction->data_policy)->toMatchArray(['policy' => 'allowed', 'sent' => true])
        ->and($interaction->data_policy['removed'])->toBeGreaterThanOrEqual(1)->and($interaction->data_policy['redacted'])->toBeGreaterThanOrEqual(1)
        // Prohibited values are never stored in the AI log either.
        ->and($interaction->question)->not->toContain('hunter2');

    $audit = AuditEvent::query()->where('action', 'AI_EXTERNAL_REQUEST')->sole();
    expect($audit->metadata)->toMatchArray(['assistant' => 'employee', 'provider' => 'anthropic', 'policy' => 'allowed', 'answered' => true])
        ->and(json_encode($audit->metadata))->not->toContain('ABCDE1234F')->not->toContain('leaves');

    // Tenant policy "none": nothing leaves; the deterministic answer stands and is not marked AI-generated.
    app(SettingsRepository::class)->set('ai.external_data_policy', 'none');
    Http::fake();
    $local = app(AiGateway::class)->ask($this->employee->user, 'employee', 'How many leaves do I have?');
    Http::assertNothingSent();
    expect($local->ai_generated)->toBeFalse()->and($local->provider)->toBe('deterministic')->and($local->data_policy)->toMatchArray(['policy' => 'none', 'sent' => false])
        ->and($local->answer)->toContain('Earned leave: 24.0 available');
});

it('rate-limits each user and keeps suggested actions as confirm-on-screen proposals', function () {
    config(['peopleos.ai.rate_limit_per_minute' => 2]);
    $gateway = app(AiGateway::class);
    $first = $gateway->ask($this->employee->user, 'employee', 'what should I do next?');
    $gateway->ask($this->employee->user, 'employee', 'How many leaves do I have?');
    expect(fn () => $gateway->ask($this->employee->user, 'employee', 'again'))->toThrow(RuntimeException::class, 'Too many questions');
    // Another user has their own budget.
    expect($gateway->ask($this->hr, 'hr', 'how many employees do we have?')->intent)->toBe('metrics');

    expect($first->actions)->not->toBeEmpty();
    foreach ($first->actions as $action) {
        expect($action)->toMatchArray(['kind' => 'open_screen', 'requires_confirmation' => true]);
    }
    $proposals = $gateway->proposals([
        ['label' => 'Leave', 'url' => url('/admin/leave-requests')], ['label' => 'Relative', 'url' => '/admin/my-day'],
        ['label' => 'Elsewhere', 'url' => 'https://evil.example/steal'], ['label' => 'Script', 'url' => 'javascript:alert(1)'], ['label' => 'Proto', 'url' => '//evil.example/x'], ['url' => '/admin/x'],
    ]);
    expect(collect($proposals)->pluck('label')->all())->toBe(['Leave', 'Relative']);
});

it('opens the AI log only to AI governance, plus each user their own conversations', function () {
    $mine = app(AiGateway::class)->ask($this->employee->user, 'employee', 'How many leaves do I have?');
    $policy = new AiInteractionPolicy;
    $auditor = tenantUser($this->tenant, ['audit.view']);
    $governance = tenantUser($this->tenant, ['ai.admin']);

    expect($policy->viewAny($auditor))->toBeFalse()->and($policy->view($auditor, $mine))->toBeFalse()
        ->and($policy->viewAny($governance))->toBeTrue()->and($policy->view($governance, $mine))->toBeTrue()
        ->and($policy->view($this->employee->user, $mine))->toBeTrue()
        ->and($policy->update($governance, $mine))->toBeFalse()->and($policy->delete($governance, $mine))->toBeFalse();
});

it('gates every HR Copilot topic by its domain and summarises records and changes through the existing read surfaces', function () {
    $gateway = app(AiGateway::class);
    $copilotOnly = tenantUser($this->tenant, ['ai.hr']);
    expect($gateway->ask($copilotOnly, 'hr', 'Documents expiring soon')->intent)->toBe('not_permitted')
        ->and($gateway->ask($copilotOnly, 'hr', 'Summarise '.$this->employee->employee_code)->intent)->toBe('not_permitted')
        ->and($gateway->ask($copilotOnly, 'hr', 'What changed this week?')->intent)->toBe('not_permitted');

    $viewer = tenantUser($this->tenant, ['ai.hr', 'employee.view']);
    $summary = $gateway->ask($viewer, 'hr', 'Summarise '.$this->employee->employee_code);
    expect($summary->intent)->toBe('summary')->and($summary->answer)->toContain('Employment:')->and($summary->answer)->not->toContain('Payroll:')->not->toContain('Compensation:');
    expect($gateway->ask($this->hr, 'hr', 'Summarise '.$this->employee->employee_code)->answer)->toContain('Payroll:')->toContain('Compensation:');

    $changes = $gateway->ask($this->hr, 'hr', 'What changed this week?');
    expect($changes->intent)->toBe('changes')->and($changes->answer)->toContain('Changes in your scope');
});

it('keeps the AI domain read-only: no domain actions, no writes except its own interaction log (invariant 19)', function () {
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Ai'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        $relative = str_replace(app_path().'/', '', $file->getPathname());
        if (preg_match('~use App\\\\Domain\\\\(?!Ai\\\\)\w+\\\\Actions\\\\~', $code)) {
            $offenders[] = "{$relative}: imports a domain action";
        }
        foreach (explode("\n", $code) as $n => $line) {
            if (preg_match('~(->|::)(create|update|save|delete|forceFill|insert|upsert|increment|decrement|forceDelete|restore|sync|attach|detach)\(|DB::(table|statement|insert|update|delete)~', $line)
                && ! str_contains($line, 'AiInteraction::create(') && ! str_contains($line, '$interaction->update(')
                && ! preg_match('~public function (create|update|delete)\(~', $line)) {
                $offenders[] = "{$relative}:".($n + 1).': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

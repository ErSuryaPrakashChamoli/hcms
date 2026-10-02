<?php

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Providers\AiProvider;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Ai\Services\AttritionRisk;
use App\Domain\Ai\Services\ConfigurationSearch;
use App\Domain\Ai\Services\PayrollAnomalyDetector;
use App\Domain\Ai\Services\PeopleQuery;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->manager = activeEmployee(null, ['ai.use', 'ai.manager', 'task.view']);
    $this->employee = salariedEmployee(600000, ['ai.use', 'kb.view', 'task.view'], '2025-01-01', ['CONV' => 1600], $this->manager);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->gateway = app(AiGateway::class);
});

it('gates assistants by permission and feature, logs every interaction, and takes feedback', function () {
    expect(array_keys($this->gateway->assistantsFor($this->employee->user)))->toBe(['employee', 'policy'])
        ->and(array_keys($this->gateway->assistantsFor($this->manager->user)))->toBe(['employee', 'policy', 'manager'])
        ->and(array_keys($this->gateway->assistantsFor($this->hr)))->toHaveCount(5); // hr has everything but is not a manager

    expect(fn () => $this->gateway->ask($this->employee->user, 'hr', 'headcount'))->toThrow(RuntimeException::class, 'do not have access');
    expect(fn () => $this->gateway->ask($this->employee->user, 'employee', ''))->toThrow(RuntimeException::class, 'up to 1000');

    $interaction = $this->gateway->ask($this->employee->user, 'employee', 'How many leaves do I have?');
    expect($interaction->intent)->toBe('leave_balance')->and($interaction->answer)->toContain('Earned leave: 24.0 available')->and($interaction->provider)->toBe('deterministic')
        ->and(collect($interaction->sources)->pluck('label')->all())->toContain('Leave ledger')
        ->and(AiInteraction::query()->where('user_id', $this->employee->user_id)->count())->toBe(1);
    $this->gateway->feedback($interaction, 'up');
    expect($interaction->refresh()->feedback)->toBe('up');

    TenantFeature::query()->updateOrCreate(['feature' => 'ai.assistants'], ['enabled' => false]);
    app(FeatureFlags::class)->forget();
    expect(fn () => $this->gateway->ask($this->employee->user, 'employee', 'hi'))->toThrow(RuntimeException::class, 'switched off');
});

it('answers employee, policy, manager and HR questions from live data only', function () {
    $ask = fn ($user, $assistant, $q) => $this->gateway->ask($user, $assistant, $q)->answer;

    expect($ask($this->employee->user, 'employee', 'show my latest payslip'))->toContain('No payslip has been issued');
    expect($ask($this->employee->user, 'employee', 'what should I do next?'))->toContain('Bank account missing');
    expect($ask($this->employee->user, 'employee', 'tell me about rockets'))->toContain('I can help with');

    $article = Article::create(['title' => 'Work from home guidelines', 'category' => 'wfh', 'body' => "# WFH\n\nAgree remote days with your manager and mark them in attendance."]);
    kbPublishForTests($article);
    $policy = $this->gateway->ask($this->employee->user, 'policy', 'How does work from home work?');
    expect($policy->intent)->toBe('kb_match')->and($policy->answer)->toContain('Agree remote days')->and(collect($policy->sources)->pluck('label')->all())->toContain('Work from home guidelines');
    expect($ask($this->employee->user, 'policy', 'What is the notice period?'))->toContain('30 days');
    expect($ask($this->employee->user, 'employee', 'how do I apply for work from home'))->toContain('Agree remote days'); // employee assistant delegates policy questions

    expect($ask($this->manager->user, 'manager', 'who is on leave today?'))->toContain('1 in your team');
    expect($ask($this->manager->user, 'manager', 'who in my team is at risk?'))->toContain('inference');
    $risky = $this->gateway->ask($this->manager->user, 'manager', 'who in my team is at risk?');
    expect($risky->is_inference)->toBeTrue();

    $delhi = Location::factory()->create(['name' => 'Delhi', 'code' => 'DEL']);
    $this->employee->currentPosition()->update(['location_id' => $delhi->id]);
    $search = $this->gateway->ask($this->hr, 'hr', 'employees in Delhi who joined last year');
    expect($search->intent)->toBe('people_search')->and($search->answer)->toContain('1 employee(s) match Location = Delhi, Joined last year')->and($search->answer)->toContain($this->employee->employee_code);
    expect($ask($this->hr, 'hr', 'how many employees do we have?'))->toContain('Headcount: 2');
    expect($ask($this->hr, 'hr', 'whose probation ends this month?'))->toContain('No probations');

    $q = app(PeopleQuery::class)->search($this->hr, 'people in Delhi on probation');
    expect($q['filters'])->toHaveCount(2)->and($q['total'])->toBe(0);
});

it('flags payroll anomalies with evidence and never changes the run', function () {
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'A', 'bank_name' => 'HDFC', 'account_number' => '999', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $twin = salariedEmployee(1200000, ['task.view']);
    EmployeeBankAccount::create(['employee_id' => $twin->id, 'account_holder_name' => 'B', 'bank_name' => 'HDFC', 'account_number' => '999', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    compensate($twin, 2400000, '2026-09-10', ['CONV' => 1600], 'revision', 'Retention');

    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($this->company, 2026, 9));
    $findings = app(PayrollAnomalyDetector::class)->audit($run);
    $types = collect($findings)->pluck('type');

    expect($types)->toContain('duplicate_bank', 'salary_revision')
        ->and(collect($findings)->where('type', 'duplicate_bank'))->toHaveCount(2)
        ->and(collect($findings)->firstWhere('type', 'salary_revision')['evidence']['reason'])->toBe('Retention')
        ->and($findings[0]['severity'])->toBe('high')
        ->and($run->refresh()->status)->toBe('calculated');

    $answer = $this->gateway->ask($this->hr, 'payroll_auditor', 'Audit the latest payroll');
    expect($answer->is_inference)->toBeTrue()->and($answer->answer)->toContain('finding(s)')->and($answer->answer)->toContain('duplicate payment');
    expect($this->gateway->ask($this->hr, 'payroll_auditor', 'Any duplicate bank accounts?')->answer)->not->toContain('Salary revised');
});

it('scores attrition risk transparently and searches the configuration map', function () {
    $risk = app(AttritionRisk::class);
    $baseline = $risk->score($this->employee);
    expect($baseline['signals'])->toContain('No one-on-one in 90 days')->and($baseline['band'])->toBe('low');

    $this->employee->update(['joining_date' => now()->subMonths(3)]);
    Grievance::create(['number' => 'GRV-1', 'grievance_category_id' => GrievanceCategory::query()->value('id'), 'employee_id' => $this->employee->id, 'subject' => 'x', 'details' => 'y', 'status' => 'under_review']);
    $scored = $risk->score($this->employee->refresh());
    expect($scored['score'])->toBe(4)->and($scored['band'])->toBe('medium')->and($scored['signals'])->toContain('Less than 12 months of tenure', 'Has an open grievance');
    expect($risk->rank(1)->first()['employee_id'])->toBe($this->employee->id);

    $workforce = $this->gateway->ask($this->hr, 'workforce', 'Give me a workforce summary');
    expect($workforce->answer)->toContain('Headcount is 2');
    expect($this->gateway->ask($this->hr, 'workforce', 'who is at risk of leaving?')->is_inference)->toBeTrue();

    $results = app(ConfigurationSearch::class)->search('working hours');
    expect(collect($results)->pluck('label')->take(3)->all())->toContain('Shifts', 'Work schedules')
        ->and(app(ConfigurationSearch::class)->search('designation')[0]['label'])->toBe('Designations')
        ->and(app(ConfigurationSearch::class)->search('zzz'))->toBe([]);
});

it('uses the language model only when enabled and falls back when it fails', function () {
    config(['peopleos.ai.provider' => 'anthropic', 'peopleos.ai.anthropic.key' => 'test-key']);
    app()->forgetInstance(AiProvider::class);
    TenantFeature::query()->updateOrCreate(['feature' => 'ai.llm'], ['enabled' => true]);
    app(FeatureFlags::class)->forget();
    Http::fakeSequence(config('peopleos.ai.anthropic.endpoint'))
        ->push(['model' => 'claude-sonnet-5', 'content' => [['type' => 'text', 'text' => 'You have 24 days of earned leave available.']], 'usage' => ['input_tokens' => 120, 'output_tokens' => 15]])
        ->push(['error' => 'overloaded'], 529);

    $gateway = app(AiGateway::class);
    $interaction = $gateway->ask($this->employee->user, 'employee', 'How many leaves do I have?');
    expect($interaction->provider)->toBe('anthropic')->and($interaction->model)->toBe('claude-sonnet-5')->and($interaction->answer)->toBe('You have 24 days of earned leave available.')->and($interaction->input_tokens)->toBe(120);
    Http::assertSent(fn ($request) => str_contains($request->body(), 'balances') && ! str_contains($request->body(), $this->employee->employee_code) && $request->hasHeader('x-api-key', 'test-key'));

    $fallback = $gateway->ask($this->employee->user, 'employee', 'How many leaves do I have?');
    expect($fallback->provider)->toBe('deterministic')->and($fallback->answer)->toContain('Earned leave: 24.0 available');
});

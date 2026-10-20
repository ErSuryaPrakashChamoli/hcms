<?php

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Pages\AnnouncementsFeed;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\MyCompensation;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\People;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\OnboardingPlans\Pages\ListOnboardingPlans;
use App\Livewire\Experience\AiAssistant;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| UX.19 (G13 and the links around it): a reminder, an AI suggestion or a row action only offers a screen the person
| may open, decided by that screen's own check, and the screen still authorises itself when opened. Reminders send
| people to where the work is done (acknowledging a policy in My HR, an announcement in the feed, a team decision in
| the Approval Center); a manager reminder appears only to someone who may act on it; links follow permission
| changes because they are worked out on every request. A refused page answers 403, never 500.
*/

function ux19LinkUser(Tenant $tenant, array $roles, ?Employee $employee = null): User
{
    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $roles, $employee) {
        $user = User::factory()->forTenant($tenant)->create();
        $user->roles()->attach(Role::query()->whereIn('slug', $roles)->pluck('id'));
        if ($employee !== null) {
            LifecycleEngine::unguarded(fn () => $employee->forceFill(['user_id' => $user->id])->save());
        }

        return $user;
    });
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['name' => 'Acme Tech']);
    $hire = fn (string $first, ?Employee $manager = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Links'], ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test'],
        ['company_id' => $this->company->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->managerEmployee = $hire('Maya');
    $this->report = $hire('Ravi', $this->managerEmployee);
    $this->hrEmployee = $hire('Hema');

    $this->employee = ux19LinkUser($this->tenant, ['employee'], $this->report);
    $this->manager = ux19LinkUser($this->tenant, ['employee', 'manager'], $this->managerEmployee);
    $this->hr = ux19LinkUser($this->tenant, ['employee', 'hr-manager'], $this->hrEmployee);
    $this->executive = ux19LinkUser($this->tenant, ['executive']);
    $this->payroll = ux19LinkUser($this->tenant, ['payroll-admin']);
    $this->admin = ux19LinkUser($this->tenant, ['tenant-hr-admin']);
});

it('sends every reminder to the screen where the work is done, and only when the person may open it', function () {
    $checked = 0;
    foreach ([$this->employee, $this->manager, $this->hr, $this->executive, $this->payroll, $this->admin] as $user) {
        $this->actingAs($user);
        foreach (app(NeedsAttention::class)->destinations($user) as $key => $url) {
            if ($url === null) {
                continue;
            }
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            expect($status)->toBe(200, "{$key} → {$url} answered {$status}");
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThan(10);

    // Acknowledging happens in My HR (policies) and the announcements feed, not on screens most people cannot open.
    $this->actingAs($this->employee);
    $to = app(NeedsAttention::class)->destinations($this->employee);
    expect($to['kb_ack'])->toBe(MyHr::getUrl(['tab' => 'policies']))
        ->and($to['announcement_ack'])->toBe(AnnouncementsFeed::getUrl())
        ->and(collect($to)->filter()->implode(' '))->not->toContain('/admin/kb');
});

it('gives a fresh hire\'s bank, PAN and tax reminders a next step they can take', function () {
    $this->actingAs($this->employee);
    $items = app(NeedsAttention::class)->forEmployee($this->report, $this->employee)->keyBy('key');

    expect($items->keys()->all())->toContain('bank', 'pan')
        ->and($items['bank']['url'])->toBe(MyHr::getUrl(['tab' => 'services']))
        ->and($items['pan']['url'])->toBe(MyHr::getUrl(['tab' => 'services']));
    foreach ($items as $item) {
        expect($item['url'])->not->toBeNull();
        $this->get($item['url'])->assertOk();
    }
});

it('offers a manager reminder only to someone who may act on it, and follows permission changes', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    app(LeaveAccrual::class)->accrue($this->report);
    app(Leaves::class)->request($this->report, LeaveType::query()->where('code', 'EL')->first(), '2026-10-12', '2026-10-12', 'Dentist');

    $this->actingAs($this->manager);
    $leave = app(NeedsAttention::class)->forManager($this->managerEmployee, $this->manager)->firstWhere('key', 'leave');
    expect($leave)->not->toBeNull()->and($leave['url'])->toBe(Approvals::getUrl());

    // The same reports, but the person may no longer decide leave: the reminder goes, and the screen still refuses
    // on its own (the backend decides, not the link).
    $this->manager->roles()->detach();
    $this->manager->roles()->attach(Role::query()->where('slug', 'employee')->pluck('id'));
    $after = User::query()->find($this->manager->id);
    $this->actingAs($after);
    expect(app(NeedsAttention::class)->forManager($this->managerEmployee, $after)->firstWhere('key', 'leave'))->toBeNull();
});

it('offers AI suggestions only where the person may go, when answered and again when shown', function () {
    $gateway = app(AiGateway::class);
    $actions = [['label' => 'Workforce pulse', 'url' => WorkforceCommandCentre::getUrl()], ['label' => 'People', 'url' => People::getUrl()],
        ['label' => 'Elsewhere', 'url' => 'https://example.com/x'], ['label' => 'Script', 'url' => 'javascript:alert(1)']];

    // HR cannot open the Workforce pulse (it needs analytics.executive): it is not offered. The executive can.
    $this->actingAs($this->hr);
    expect(array_column($gateway->proposals($actions, $this->hr), 'label'))->toBe(['People']);
    $this->actingAs($this->executive);
    expect(array_column($gateway->proposals($actions, $this->executive), 'label'))->toContain('Workforce pulse')->not->toContain('Elsewhere', 'Script');

    // A suggestion stored earlier is checked again when the conversation is shown (permissions may have changed).
    $this->actingAs($this->hr);
    $stored = AiInteraction::query()->create(['user_id' => $this->hr->id, 'assistant' => 'hr', 'question' => 'What is our attrition?', 'answer' => 'Stable.', 'sources' => [],
        'actions' => [['label' => 'Workforce pulse', 'url' => WorkforceCommandCentre::getUrl(), 'kind' => 'open_screen', 'requires_confirmation' => true],
            ['label' => 'People', 'url' => People::getUrl(), 'kind' => 'open_screen', 'requires_confirmation' => true]],
        'intent' => 'attrition', 'provider' => 'deterministic']);
    expect(array_column($stored->openActions(), 'label'))->toBe(['People']);
    Livewire::test(AiAssistant::class)->set('assistant', 'hr')->set('thread', [$stored->id])
        ->assertSee(People::getUrl(), false)->assertDontSee(WorkforceCommandCentre::getUrl(), false);

    // The employee's "What should I do next?" only suggests screens that open for them.
    $this->actingAs($this->employee);
    $answer = $gateway->ask($this->employee, 'employee', 'What should I do next?');
    foreach (array_column($answer->actions ?? [], 'url') as $url) {
        $this->get($url)->assertOk();
    }
});

it('refuses My compensation with 403, not an error, for someone without an employee record', function () {
    $this->actingAs(tenantUser($this->tenant, ['compensation.self']));
    $this->get(MyCompensation::getUrl())->assertForbidden();
});

it('offers the onboarding plan\'s Open only to someone who may open that Employee 360', function () {
    $plan = OnboardingPlan::query()->create(['employee_id' => $this->report->id, 'status' => 'in_progress', 'anchor_date' => '2026-10-01', 'started_at' => now()]);

    // Onboarding without employee access: the plan is listed, but "Open" would be refused, so it is not offered.
    $this->actingAs(tenantUser($this->tenant, ['onboarding.view']));
    Livewire::test(ListOnboardingPlans::class)->assertActionHidden(TestAction::make('open')->table($plan));

    $this->actingAs($this->hr);
    Livewire::test(ListOnboardingPlans::class)->assertActionVisible(TestAction::make('open')->table($plan));
});

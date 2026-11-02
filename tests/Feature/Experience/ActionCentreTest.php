<?php

use App\Domain\Experience\Services\ActionCatalogue;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\QuickActions;
use App\Domain\Experience\Services\RoleLens;
use App\Filament\Pages\ActionsPage;
use App\Filament\Pages\Home;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Livewire\Experience\ActionCentre;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| HROne parity AC-1: the Actions panel lists every HR action the person may run, by module, with search, recent and
| pinned actions; each action opens the module's own screen or form. Recruitment is out of scope.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    $this->admin = tenantUser($this->tenant, ['*']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function actionKeys(array $actions): array
{
    return array_column($actions, 'key');
}

it('lists every HR action by module for an administrator, without recruitment', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->admin);

    $sections = app(ActionCatalogue::class)->sections($this->admin);

    expect(array_column($sections, 'label'))->toBe(['Workforce', 'Time office', 'Payroll', 'Expense & travel', 'Performance', 'Engage', 'Assets', 'Helpdesk']);
    $keys = actionKeys(app(ActionCatalogue::class)->for($this->admin));
    expect($keys)->toContain('add_business_partner', 'add_employee', 'add_new_hire', 'change_manager', 'generate_letter', 'invite_to_sign_in', 'suggest_improvement', 'share_documents', 'transfer_request',
        'carry_forward_leaves', 'change_attendance_status', 'change_holiday', 'change_shift', 'run_attendance', 'leave_for_employee', 'regularisation_for_employee',
        'assign_salary_structure', 'process_bonus', 'encash_leaves', 'generate_challan', 'increment_calculator', 'run_payroll', 'loan_for_employee', 'variable_pay_for_employee',
        'pay_expenses', 'recover_advance', 'travel_for_employee', 'expense_for_employee',
        'add_pip', 'create_company_goal', 'extend_goal', 'extend_review', 'initiate_360', 'normalise_performance_rating', 'publish_performance_rating',
        'add_announcement', 'add_event', 'initiate_fundraiser', 'add_handbook', 'add_survey', 'add_asset', 'asset_request_for_employee', 'ticket_for_employee')
        ->and(count($keys))->toBe(count(array_unique($keys)));
    $words = mb_strtolower(json_encode(app(ActionCatalogue::class)->for($this->admin)));
    expect($words)->not->toContain('candidate')->not->toContain('requisition')->not->toContain('interview');
});

it('opens every action’s screen for the administrator', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->admin);
    $urls = array_unique(array_map(fn (array $a) => $a['url'], app(ActionCatalogue::class)->for($this->admin)));
    actAsTenant(null);

    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }
});

it('offers an HR operator only the actions their role allows', function () {
    $leaveDesk = tenantUser($this->tenant, ['leave.view', 'leave.manage', 'employee.view']);
    actAsTenant($this->tenant);
    $this->actingAs($leaveDesk);

    $keys = actionKeys(app(ActionCatalogue::class)->for($leaveDesk));

    expect($keys)->toContain('leave_for_employee')
        ->not->toContain('run_payroll')->not->toContain('add_employee')->not->toContain('generate_challan')->not->toContain('add_asset');
});

it('has nothing for an employee who only serves themselves, and keeps the page and button from them', function () {
    $employee = tenantUser($this->tenant, ['employee.self', 'leave.apply', 'attendance.regularise', 'payroll.request', 'servicedesk.request']);
    actAsTenant($this->tenant);
    $this->actingAs($employee);

    expect(app(ActionCatalogue::class)->for($employee))->toBe([])
        ->and(ActionsPage::canAccess())->toBeFalse();
    actAsTenant(null);
    $this->get(ActionsPage::getUrl())->assertForbidden();
    expect($this->get(Home::getUrl())->getContent())->not->toContain('pos-actions-launch')->not->toContain('data-pos-actions="panel"');
});

it('answers only for the signed-in person', function () {
    $other = tenantUser($this->tenant, ['*']);
    actAsTenant($this->tenant);
    $this->actingAs($this->admin);

    expect(app(ActionCatalogue::class)->for($other))->toBe([]);
});

it('shows the Actions button and panel in the top bar, and the page lists the modules', function () {
    $this->actingAs($this->admin);

    $home = $this->get(Home::getUrl())->assertOk()->getContent();
    expect($home)->toContain('pos-actions-launch')->toContain('data-pos-actions="panel"');

    $this->get(ActionsPage::getUrl())->assertOk()
        ->assertSee('Workforce')->assertSee('Time office')->assertSee('Run payroll')->assertSee('Generate challan')->assertSee('Search actions');
});

it('runs an action: remembers it as recent and opens its screen', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->admin);
    $target = app(ActionCatalogue::class)->find($this->admin, 'add_employee');

    Livewire::test(ActionCentre::class)->call('opened')
        ->assertSee('Add employee')
        ->call('run', 'add_employee')->assertRedirect($target['url']);

    expect(app(ExperiencePreferences::class)->for($this->admin)['recent_actions'])->toBe(['add_employee']);

    // A key that is not one of the person's actions opens nothing and is not remembered.
    Livewire::test(ActionCentre::class)->call('opened')->call('run', 'add_candidate')->assertNoRedirect();
    expect(app(ExperiencePreferences::class)->for($this->admin)['recent_actions'])->toBe(['add_employee']);
});

it('pins and unpins actions, and shows recent and pinned actions first', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->admin);
    app(ExperiencePreferences::class)->pushRecentAction($this->admin, 'run_payroll');

    $panel = Livewire::test(ActionCentre::class)->call('opened')
        ->call('togglePin', 'generate_challan')
        ->call('togglePin', 'not_an_action');

    expect(app(ExperiencePreferences::class)->for($this->admin)['pinned_actions'])->toBe(['generate_challan']);
    $panel->assertSeeHtml('data-kind="recent"')->assertSeeHtml('data-kind="pinned"')->assertSee('Unpin Generate challan');

    $panel->call('togglePin', 'generate_challan');
    expect(app(ExperiencePreferences::class)->for($this->admin)['pinned_actions'])->toBe([]);
});

it('keeps pinned and recent actions the person can no longer run out of the panel', function () {
    $narrow = tenantUser($this->tenant, ['leave.view', 'leave.manage', 'employee.view']);
    actAsTenant($this->tenant);
    $this->actingAs($narrow);
    app(ExperiencePreferences::class)->update($narrow, ['pinned_actions' => ['run_payroll'], 'recent_actions' => ['run_payroll']]);

    Livewire::test(ActionCentre::class)->call('opened')
        ->assertDontSee('Run payroll')->assertDontSeeHtml('data-kind="pinned"')->assertDontSeeHtml('data-kind="recent"');
});

it('lets the Command Center find the HR actions without featuring them on Home', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->admin);

    $quick = actionKeys(app(QuickActions::class)->for($this->admin));
    expect($quick)->toContain('all_actions', 'hr_action.generate_challan', 'hr_action.run_attendance');
    foreach (RoleLens::ALL as $lens) {
        expect(actionKeys(app(QuickActions::class)->forLens($this->admin, $lens, 50)))->each->not->toStartWith('hr_action.')
            ->and(actionKeys(app(QuickActions::class)->forLens($this->admin, $lens, 50)))->not->toContain('all_actions');
    }
});

it('starts the hire wizard for someone joining later', function () {
    $this->actingAs($this->admin);

    $this->get(EmployeeResource::getUrl('create', ['upcoming' => 1]))->assertOk()
        ->assertSee('Add new hire (joining later)')->assertSee('the new hire stays in pre-boarding until then');
    $this->get(EmployeeResource::getUrl('create'))->assertOk()->assertDontSee('Add new hire (joining later)');
});

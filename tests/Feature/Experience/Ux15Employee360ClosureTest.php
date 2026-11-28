<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\Employees\EmployeeResource;
use Illuminate\Support\Str;

/*
| UX.15 closure P1-04: the Employee 360 as one coherent person workspace. Now holds the core (state and what is
| next in one panel, the latest changes, intelligence, snapshot); Profile, Journey, Work, Time, Growth, Rewards,
| Documents and Activity are views one step away. Owner, 2026-11-27: each view is followed by its own lists (no
| Records view repeating them). Views only change what is shown at once: everything is rendered by the same gates,
| so a hidden view never carries what the viewer may not see.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::factory()->create();
    $this->person = tap(app(HireEmployeeAction::class)->handle(['first_name' => 'Priya', 'last_name' => 'Views'], ['joining_date' => '2023-04-03'], ['company_id' => $company->id]),
        fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->person->id, 'occurred_on' => now(), 'category' => 'compensation', 'title' => 'Salary revised to 9,99,999']);
    foreach (['Mentor assigned', 'Promotion', 'Transfer', 'Team changed'] as $i => $title) {
        EmployeeTimelineEntry::query()->create(['employee_id' => $this->person->id, 'occurred_on' => now()->subDays($i + 1), 'category' => 'reporting', 'title' => $title]);
    }
    $this->hr = tenantUser($this->tenant, ['employee.view']);
    actAsTenant(null);
});

it('opens on Now with the core in a few panels and the other views one step away', function () {
    $html = $this->actingAs($this->hr)->get(EmployeeResource::getUrl('view', ['record' => $this->person]))->assertOk()
        ->assertSee('What’s next')->assertSee('Recently')->assertSee('Full journey')->assertSee('People snapshot')
        ->assertSee('x-init="$store.pos360?.fromHash(false)"', false)
        ->getContent();

    foreach (['journey', 'work', 'time', 'growth', 'rewards', 'documents', 'activity'] as $view) {
        expect($html)->toContain('id="'.$view.'" class="pos-360-sec"')->toContain("\$store.pos360.view === '".$view."'");
    }
    // No Records view any more: each list follows the view it belongs to (the timeline under Journey).
    expect($html)->not->toContain('id="records"')->not->toContain('pos-360-deep')
        ->toContain('data-pos360-of="journey"')->toContain('id="list-TimelineRelationManager"');
    // Now shows only the three latest changes; every change is in the Timeline list under Journey.
    $now = Str::between($html, '<section id="now"', '<section id="journey"');
    expect($now)->toContain('Mentor assigned')->toContain('Promotion')->toContain('Transfer')->not->toContain('Team changed');
});

it('never puts what the viewer may not see into a hidden view', function () {
    $html = $this->actingAs($this->hr)->get(EmployeeResource::getUrl('view', ['record' => $this->person]))->assertOk()->getContent();

    expect($html)->toContain('Mentor assigned')
        ->and($html)->not->toContain('Salary revised to 9,99,999')
        ->and($html)->toContain('Nothing here you can see.');
});

it('still lets nobody without the 360 open it', function () {
    $stranger = tenantUser($this->tenant, ['leave.apply']);
    $this->actingAs($stranger)->get(EmployeeResource::getUrl('view', ['record' => $this->person]))->assertForbidden();
});

<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use Livewire\Livewire;

/*
| UX.15.14: complex changes feel like workflows: context → change → review (Before → After on its
| effective date) → confirm, on the same actions, fields, validation and domain services.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->employee = tap(app(HireEmployeeAction::class)->handle(['first_name' => 'Guide', 'last_name' => 'Flow'], ['joining_date' => '2024-01-01'], ['company_id' => Company::factory()->create()->id]),
        fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->actingAs(tenantUser($this->tenant, ['employee.view', 'employee.position', 'employee.update']));
});

it('turns transfer / promote and change manager into guided step flows', function () {
    $page = Livewire::test(ViewEmployee::class, ['record' => $this->employee->id]);

    foreach (['assignPosition' => 'Confirm change', 'changeManager' => 'Confirm reporting change'] as $name => $submit) {
        $page->mountAction($name)->assertActionMounted($name);
        $action = $page->instance()->getMountedAction();
        $steps = (fn () => $this->schema)->call($action);
        expect($action->isWizard())->toBeTrue()
            ->and(collect($steps)->map(fn ($s) => $s->getLabel())->all())->toBe(['Context', 'Change', 'Review'])
            ->and($action->getModalSubmitActionLabel())->toBe($submit);
        $page->unmountAction();
    }
});

it('never leaves a transform on page containers, so modals stay positioned against the viewport', function () {
    // Regression: .fi-page and .pos-enter* animated with fill-mode "both" kept transform: translateY(0) after
    // the animation, which made the page the containing block for position: fixed modals.
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    preg_match_all('/^\.(fi-page|pos-enter(?:-\d)?) \{ animation: [^}]*\}/m', $css, $rules);

    expect($rules[0])->not->toBeEmpty();
    foreach ($rules[0] as $rule) {
        expect($rule)->toContain('backwards')->not->toMatch('/\b(both|forwards)\b/');
    }
});

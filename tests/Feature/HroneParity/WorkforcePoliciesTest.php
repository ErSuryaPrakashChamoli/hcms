<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Lifecycle\Services\ConfirmationReviews;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity — Workforce: separation (exit) policy wired, confirmation policy and reviews. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
});

function workforcePolicy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => $type, 'code' => strtoupper($type).'_W']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('takes the notice from the exit policy by probation status and keeps backdating within its limits', function () {
    workforcePolicy('exit', ['notice_period_days' => 60, 'notice_days_probation' => 15, 'employee_backdate_days' => 3, 'hr_backdate_days' => 10]);
    $probationer = employeeWithUser(null, ['exit.resign']);
    forceLifecycle($probationer, LifecycleState::Probation, ['joining_date' => '2026-06-01']);
    $confirmed = employeeWithUser(null, ['exit.resign']);
    forceLifecycle($confirmed, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $exits = app(Exits::class);

    expect(fn () => $exits->resign($probationer, 'Moving city', resignedOn: '2026-09-15'))->toThrow(RuntimeException::class, 'at most 3 day(s) back');
    $short = $exits->resign($probationer, 'Moving city', resignedOn: '2026-09-19');
    expect($short->notice_days)->toBe(15)->and($short->last_working_day->toDateString())->toBe('2026-10-04');

    expect(fn () => $exits->initiate($confirmed, 'resignation', 'Higher studies', '2026-09-01'))->toThrow(RuntimeException::class, 'at most 10 day(s)');
    expect($exits->initiate($confirmed, 'resignation', 'Higher studies', '2026-09-15')->notice_days)->toBe(60);
});

it('sets the probation from the confirmation policy at hire', function () {
    workforcePolicy('confirmation', ['probation_months' => 3]);
    $company = Company::factory()->create();
    $location = Location::factory()->create(['company_id' => $company->id]);
    $employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Ira', 'last_name' => 'Sen'], ['joining_date' => '2026-09-01'], ['company_id' => $company->id, 'location_id' => $location->id]);

    expect($employee->probation_end_date->toDateString())->toBe('2026-12-01');
});

it('opens confirmation reviews ahead of the probation end and confirms, extends within limits, or terminates', function () {
    workforcePolicy('confirmation', ['review_lead_days' => 10, 'allow_extend' => true, 'extension_months' => 3, 'max_extensions' => 1, 'allow_terminate' => true]);
    $due = employeeWithUser();
    forceLifecycle($due, LifecycleState::Probation, ['joining_date' => '2026-03-25', 'probation_end_date' => '2026-09-25']);
    $later = employeeWithUser();
    forceLifecycle($later, LifecycleState::Probation, ['joining_date' => '2026-04-25', 'probation_end_date' => '2026-10-25']);
    $third = employeeWithUser();
    forceLifecycle($third, LifecycleState::Probation, ['joining_date' => '2026-03-28', 'probation_end_date' => '2026-09-28']);
    $reviews = app(ConfirmationReviews::class);

    expect($reviews->openDue())->toBe(2)->and($reviews->openDue())->toBe(0);
    $review = ConfirmationReview::query()->where('employee_id', $due->id)->firstOrFail();

    expect(fn () => $reviews->extend($review, 6, 'More time', $this->hr))->toThrow(RuntimeException::class, '1 to 3 month(s)');
    $reviews->extend($review, 2, 'Needs more time on the product', $this->hr);
    expect($due->refresh()->probation_end_date->toDateString())->toBe('2026-11-25');

    $this->travelTo('2026-11-20 10:00:00');
    $reviews->openDue();
    $second = ConfirmationReview::query()->where('employee_id', $due->id)->where('status', 'pending')->firstOrFail();
    expect($second->extension_number)->toBe(1)
        ->and(fn () => $reviews->extend($second, 1, 'Again', $this->hr))->toThrow(RuntimeException::class, 'the most the policy allows');
    $reviews->confirm($second, 'Good progress', $this->hr);
    expect($due->refresh()->lifecycle_state)->toBe(LifecycleState::Confirmed)->and($due->confirmation_date->toDateString())->toBe('2026-11-25');

    $thirdReview = ConfirmationReview::query()->where('employee_id', $third->id)->firstOrFail();
    $reviews->terminate($thirdReview, 'Did not meet expectations', $this->hr);
    expect($thirdReview->refresh()->status)->toBe('terminated')->and(ExitCase::query()->where('employee_id', $third->id)->value('type'))->toBe('termination')
        ->and(fn () => $reviews->confirm(ConfirmationReview::query()->where('employee_id', $later->id)->firstOrFail(), null, $later->user))->toThrow(RuntimeException::class, 'cannot decide');
});

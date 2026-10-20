<?php

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Alumni\Services\Alumni;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Services\Assets;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\ExitInterviews;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Lifecycle\Enums\LifecycleState;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->manager = activeEmployee(null, ['exit.clear', 'exit.resign', 'task.view']);
    $this->employee = salariedEmployee(600000, ['exit.resign', 'exit.clear', 'alumni.portal', 'payroll.payslip', 'task.view'], '2025-01-01', ['CONV' => 1600], $this->manager);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    LeaveType::query()->where('code', 'EL')->update(['is_encashable' => true]);
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->exits = app(Exits::class);
});

it('runs resignation through notice, clearance with asset recovery, settlement, completion and alumni', function () {
    $laptop = app(Assets::class)->receive(['asset_category_id' => AssetCategory::query()->where('code', 'LAPTOP')->value('id'), 'asset_tag' => 'LT-1', 'name' => 'Laptop']);
    app(Assets::class)->assign($laptop, $this->employee);

    $case = $this->exits->resign($this->employee, 'Relocating', null, $this->employee->user);
    expect($case->number)->toBe('EXIT-2026-00001')->and($case->status)->toBe('notice')->and($case->notice_days)->toBe(30)
        ->and($case->last_working_day->toDateString())->toBe('2026-10-21')
        ->and($case->manager_id)->toBe($this->manager->id)
        ->and($case->clearances()->count())->toBe(5)
        ->and($case->clearances()->where('stage', 'manager')->value('owner_user_id'))->toBe($this->manager->user_id)
        ->and($case->clearances()->where('stage', 'asset')->first()->items[0]['item'])->toContain('LT-1')
        ->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::NoticePeriod)
        ->and($this->employee->exit_date->toDateString())->toBe('2026-10-21')
        ->and($this->manager->user->notifications()->count())->toBe(1);
    expect(fn () => $this->exits->resign($this->employee, 'again'))->toThrow(RuntimeException::class, 'already in progress');

    // Clearance starts automatically inside the lead window.
    expect(fn () => $this->exits->clearStage($case->clearances()->first(), $this->hr))->toThrow(RuntimeException::class, 'not started');
    $this->travelTo('2026-10-15 09:00:00');
    expect($this->exits->tick())->toBe(1)->and($case->refresh()->status)->toBe('clearance');

    $asset = $case->clearances()->where('stage', 'asset')->first();
    expect(fn () => $this->exits->clearStage($asset, $this->hr, 'ok'))->toThrow(RuntimeException::class, 'still in custody');
    $this->exits->clearStage($asset, $this->hr, 'Laptop lost; charged', 15000);
    expect($asset->refresh()->status)->toBe('cleared')->and((float) $asset->recoverable_amount)->toBe(15000.0);

    $managerStage = $case->clearances()->where('stage', 'manager')->first();
    expect($managerStage->isOwnedBy($this->manager->user))->toBeTrue()->and($this->manager->user->can('view', $case))->toBeTrue();
    $this->exits->blockStage($managerStage, $this->manager->user, 'KT incomplete');
    expect($managerStage->refresh()->status)->toBe('blocked')->and($case->refresh()->status)->toBe('clearance');
    $managerStage->update(['status' => 'pending']);
    $this->exits->clearStage($managerStage, $this->manager->user, 'KT done', 0, [true, true, true]);

    foreach ($case->clearances()->where('status', 'pending')->get() as $stage) {
        $this->exits->clearStage($stage, $this->hr, 'ok');
    }
    expect($case->refresh()->status)->toBe('settlement')->and($case->settlement)->not->toBeNull();
    expect(fn () => $this->exits->complete($case, $this->hr))->toThrow(RuntimeException::class, 'settlement must be approved');

    // Settlement: October salary through the payroll calculator (21 paid days), EL encashment, asset recovery.
    $settlements = app(FinalSettlements::class);
    $settlement = $settlements->calculate($case, $this->hr);
    $codes = $settlement->lines->pluck('code');
    expect($settlement->status)->toBe('calculated')
        ->and($codes)->toContain('SAL_BASIC', 'SAL_PF_EE', 'ENCASH_EL', 'ASSET_RECOVERY')
        ->and((float) $settlement->lines->firstWhere('code', 'ASSET_RECOVERY')->amount)->toBe(15000.0)
        ->and((float) $settlement->lines->firstWhere('code', 'SAL_BASIC')->basis['paid_days'])->toBe(21.0)
        ->and((float) $settlement->lines->firstWhere('code', 'ENCASH_EL')->amount)->toBe(round(24 * round(20000 / 30, 2), 2))
        ->and($codes)->not->toContain('NOTICE_RECOVERY')
        ->and((float) $settlement->net_amount)->toBe(round((float) $settlement->total_earnings - (float) $settlement->total_deductions, 2));

    $settlements->addLine($settlement, 'deduction', 'Salary advance', 2000, 'Sept advance');
    $settlement = $settlements->calculate($case, $this->hr); // manual lines survive recalculation
    expect($settlement->lines->where('source', 'manual'))->toHaveCount(1);

    $before = app(LeaveBalances::class)->balance($this->employee, LeaveType::query()->where('code', 'EL')->first(), app(LeaveYear::class)->periodFor('2026-10-21'))->available();
    $settlements->approve($settlement, $this->hr, 'Reviewed');
    expect($settlement->refresh()->status)->toBe('approved')
        ->and(app(LeaveBalances::class)->balance($this->employee, LeaveType::query()->where('code', 'EL')->first(), app(LeaveYear::class)->periodFor('2026-10-21'))->available())->toBe($before - 24.0);
    expect(fn () => $settlements->addLine($settlement, 'earning', 'x', 1))->toThrow(RuntimeException::class, 'approved');
    $settlements->markPaid($settlement, 'NEFT-123', $this->hr);

    app(ExitInterviews::class)->submit($case, ['reason_for_leaving' => 'relocation', 'ratings' => ['manager_experience' => 4, 'culture' => 5], 'would_recommend' => true, 'would_rejoin' => true, 'suggestions' => 'More remote roles'], $this->employee->user, true);
    $this->exits->complete($case->refresh(), $this->hr);
    expect($case->refresh()->status)->toBe('completed')
        ->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::Exited)
        ->and($this->employee->user->refresh()->isActive())->toBeFalse();

    $alumni = $this->exits->createAlumni($case, $this->hr, ['personal_email' => 'me@example.test']);
    expect($alumni)->toBeInstanceOf(AlumniProfile::class)
        ->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::Alumni)
        ->and($this->employee->user->refresh()->isActive())->toBeTrue()
        ->and($this->employee->user->roles()->pluck('slug')->all())->toBe(['alumni'])
        ->and($alumni->last_designation)->toBeNull(); // test employee has no designation
    expect(app(ExitInterviews::class)->analytics()['by_reason']['relocation'])->toBe(['employee' => 1, 'hr_inferred' => 0]);
});

it('recovers notice shortfall, withdraws resignations and treats terminations as immediate', function () {
    $case = $this->exits->initiate($this->employee, 'resignation', 'Short notice', '2026-09-21', '2026-10-01', 30, $this->hr);
    $this->exits->startClearance($case);
    foreach ($case->clearances()->get() as $stage) {
        $this->exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    $settlement = app(FinalSettlements::class)->calculate($case->refresh(), $this->hr);
    $recovery = $settlement->lines->firstWhere('code', 'NOTICE_RECOVERY');
    expect($recovery)->not->toBeNull()->and($recovery->basis['served_days'])->toBe(10)->and((float) $recovery->amount)->toBe(round(20 * round(47600 / 30, 2), 2)); // Phase 7: September 2026 gross is 47,600 under draft EPF v2 (higher employer PF in the CTC balance)

    $this->exits->withdraw($case, 'Counter-offer accepted', $this->hr);
    expect($case->refresh()->status)->toBe('withdrawn')->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::Active)->and($this->employee->exit_date)->toBeNull();

    $termination = $this->exits->initiate($this->employee, 'termination', 'Policy breach', null, null, null, $this->hr);
    expect($termination->notice_days)->toBe(0)->and($termination->last_working_day->toDateString())->toBe('2026-09-21')->and($termination->status)->toBe('clearance')->and($termination->is_rehire_eligible)->toBeFalse();
    expect(fn () => $this->exits->withdraw($termination, 'x'))->toThrow(RuntimeException::class, 'Only a resignation');
});

it('generates, approves and issues letters as documents, and serves alumni requests end to end', function () {
    $letters = app(Letters::class);
    expect(LetterTemplate::query()->count())->toBe(9);

    $cert = $letters->generate('employment_certificate', $this->employee, [], $this->hr);
    expect($cert->status)->toBe('approved')->and($cert->number)->toBe('LTR-2026-00001')->and($cert->body)->toContain($this->employee->person->full_name)->toContain($this->employee->employee_code);

    $experience = $letters->generate('experience', $this->employee, [], $this->hr);
    expect($experience->status)->toBe('pending_approval');
    expect(fn () => $letters->issue($experience, $this->hr))->toThrow(RuntimeException::class, 'approved');
    // Phase 12: the requester does not approve their own letter; a second person does.
    expect(fn () => $letters->approve($experience, $this->hr, 'mine'))->toThrow(RuntimeException::class, 'requested a letter cannot approve it');
    $letters->approve($experience, tenantUser($this->tenant, ['letter.issue']), 'ok');
    $letters->issue($experience->refresh(), $this->hr);
    expect($experience->refresh()->status)->toBe('issued')->and($experience->document_id)->not->toBeNull()
        ->and($experience->document->title)->toBe($experience->subject)
        ->and($this->employee->user->can('view', $experience))->toBeTrue()
        ->and($this->employee->user->can('view', $cert))->toBeFalse()
        ->and($this->employee->user->notifications()->count())->toBe(1)
        ->and(AuditEvent::query()->where('module', 'letters')->count())->toBeGreaterThanOrEqual(4);

    forceLifecycle($this->employee, 'exited', ['exit_date' => '2026-09-21']);
    $case = ExitCase::create(['number' => 'EXIT-2026-00009', 'employee_id' => $this->employee->id, 'type' => 'resignation', 'status' => 'completed', 'initiated_on' => '2026-08-01', 'last_working_day' => '2026-09-21', 'completed_at' => now()]);
    $profile = $this->exits->createAlumni($case, $this->hr);

    $alumni = app(Alumni::class);
    $request = $alumni->request($profile, 'relieving_letter', 'For a new employer', $this->employee->user);
    expect($request->number)->toBe('ALR-2026-00001')->and($this->hr->notifications()->count())->toBeGreaterThan(0);
    $alumni->verify($request, $this->hr, 'ID checked');
    $request = $alumni->approve($request, $this->hr);
    expect($request->status)->toBe('generated')->and(Letter::query()->find($request->letter_id)->status)->toBe('issued');
    $alumni->deliver($request, $this->hr, 'Emailed');
    expect($request->refresh()->status)->toBe('delivered')->and($this->employee->user->can('view', $request))->toBeTrue();

    $other = activeEmployee(null, ['alumni.portal']);
    expect($other->user->can('view', $request))->toBeFalse();
    expect(fn () => $alumni->request($profile->refresh(), 'bogus'))->toThrow(RuntimeException::class, 'Unknown');
});

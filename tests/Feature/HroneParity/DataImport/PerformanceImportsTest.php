<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Performance\Imports\GoalBankImports;
use App\Domain\Performance\Imports\GoalImports;
use App\Domain\Performance\Imports\ImprovementPlanImports;
use App\Domain\Performance\Imports\InitiativeImports;
use App\Domain\Performance\Imports\OkrFinalImports;
use App\Domain\Performance\Imports\OkrTotalImports;
use App\Domain\Performance\Imports\RatingNormalisationImports;
use App\Domain\Performance\Imports\ReviewNormalisationImports;
use App\Domain\Performance\Imports\ReviewRatingImports;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\CalibrationAdjustment;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\ImprovementPlanCheckpoint;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Di35Helpers.php';
require_once __DIR__.'/../../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../../Performance/PerformanceTestHelpers.php';

/*
 | Data import DI-35, Performance head: goal bank (KRA library), goals, initiatives, improvement plans, periodic review
 | ratings, normalised final and review ratings, OKR final (key result check-ins) and total achievement (the GO-4 override,
 | behind performance.goals.achievement_override) — each row through the service the performance screens use.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals']);
    $this->a = activeEmployee($this->manager);
    $this->b = activeEmployee($this->manager);
});

/** The one performance policy, for everyone. */
function di35PerformancePolicy(array $settings): Policy
{
    PolicyAssignmentRule::query()->where('policy_type', 'performance')->delete();
    $policy = Policy::create(['type' => 'performance', 'name' => 'Performance', 'code' => 'DI35_'.uniqid()]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'performance', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    app(PerformancePolicyRules::class)->forget();

    return $policy;
}

/** A launched cycle with only a final stage (open now), everyone employed in it. */
function di35Cycle(string $code = 'DI35'): PerformanceCycle
{
    return app(Appraisals::class)->launch(draftCycle(['code' => $code, 'name' => "Cycle {$code}", 'stages' => [['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]));
}

it('registers the nine performance uploads under Performance with the rights their screens use', function () {
    $expected = [GoalBankImports::TYPE => 'performance.manage', GoalImports::TYPE => 'performance.manage', InitiativeImports::TYPE => 'performance.initiatives_manage',
        ImprovementPlanImports::TYPE => 'performance.pip', ReviewRatingImports::TYPE => 'performance.manage', RatingNormalisationImports::TYPE => 'performance.calibrate',
        ReviewNormalisationImports::TYPE => 'performance.calibrate', OkrFinalImports::TYPE => 'performance.manage', OkrTotalImports::TYPE => 'performance.manage'];
    foreach ($expected as $type => $permission) {
        expect(RowImportTypes::TYPES[$type][2])->toBe($permission, $type)->and(RowImportTypes::TYPES[$type][3])->toBe('performance');
    }
    expect(ImportTypes::modes(GoalImports::TYPE))->not->toBeNull()->and(ImportTypes::modes(RatingNormalisationImports::TYPE))->toBeNull()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'performance.view']), GoalImports::TYPE))->toBeFalse();
});

it('loads the goal bank as KRAs with suggested KPIs and updates them by code', function () {
    $csv = "Code,Name,Category,Default weight (%),Perspective code,Suggested KPIs\nkra-sales,Grow revenue,Business,30,FINANCIAL,Revenue growth | % over last year | 15 | %; New accounts\n"
        ."KRA-PEOPLE,Build the team,People,20,,\nKRA-BAD,Bad one,,120,NOPE,\n";
    $import = di35Stage(GoalBankImports::TYPE, $csv);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(1)
        ->and(di35Errors($import)[3])->toContain('Default weight is a whole number from 0 to 100.')
        ->and(di35Errors($import)[3])->toContain("Perspective code 'NOPE' is not an active perspective (Performance → Masters).");
    di35Run($import);

    $sales = Kra::query()->where('code', 'KRA-SALES')->sole();
    expect($sales->name)->toBe('Grow revenue')->and($sales->default_weight)->toBe(30)->and($sales->perspective?->code)->toBe('FINANCIAL')
        ->and(array_column($sales->kpis, 'name'))->toBe(['Revenue growth', 'New accounts'])->and($sales->kpis[0]['target'])->toBe('15');

    // "New only" leaves the KRA on file; "update" changes it and leaves blank cells alone.
    expect(di35Stage(GoalBankImports::TYPE, "Code,Name,Default weight (%)\nKRA-SALES,Grow revenue,40\n", 'new')->skip_count)->toBe(1);
    $update = di35Stage(GoalBankImports::TYPE, "Code,Name,Default weight (%)\nKRA-SALES,Grow revenue,40\nKRA-NEW,New one,10\n", 'update');
    expect($update->update_count)->toBe(1)->and($update->skip_count)->toBe(1);
    di35Run($update);
    expect($sales->refresh()->default_weight)->toBe(40)->and($sales->category)->toBe('Business')->and(Kra::query()->where('code', 'KRA-NEW')->exists())->toBeFalse();
});

it('creates and updates goals through the goals service, within the goal sheet\'s weight', function () {
    $cycle = draftCycle(['code' => 'FY26', 'settings' => ['required_goal_weight' => 100]]);
    $a = $this->a->employee_code;
    $b = $this->b->employee_code;
    $csv = "Employee code,Cycle code,Goal,Measure,Target,Weight (%),Due date\n{$a},FY26,Grow revenue,percentage,15,60,2027-03-31\n{$a},FY26,Cut costs,numeric,3,50,\n"
        ."{$b},FY26,Hire two engineers,numeric,2,40,\nNOPE,FY26,Unknown,,,10,\n{$b},NOPE,Wrong cycle,,,10,\n";
    $import = di35Stage(GoalImports::TYPE, $csv);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and(di35Errors($import)[2])->toBe(['Goal weights of this goal sheet would add up to 110 (with the rows above); the cycle allows 100.'])
        ->and(di35Errors($import)[5])->toBe(["Cycle code 'NOPE' does not exist."]);
    di35Run($import);

    $goal = Goal::query()->where('employee_id', $this->a->id)->sole();
    expect($goal->title)->toBe('Grow revenue')->and($goal->performance_cycle_id)->toBe($cycle->id)->and($goal->level)->toBe('employee')
        ->and($goal->source)->toBe('import')->and((float) $goal->weight)->toBe(60.0)->and((float) $goal->target_value)->toBe(15.0)
        ->and($goal->measure_type)->toBe('percentage')->and($goal->due_date->toDateString())->toBe('2027-03-31')->and($goal->status)->toBe('active');

    // Matched on employee + cycle + title: "new only" skips it, "update" changes the target.
    expect(di35Stage(GoalImports::TYPE, "Employee code,Cycle code,Goal,Target\n{$a},FY26,grow revenue,18\n", 'new')->skip_count)->toBe(1);
    $update = di35Stage(GoalImports::TYPE, "Employee code,Cycle code,Goal,Target\n{$a},FY26,grow revenue,18\n", 'update');
    expect($update->update_count)->toBe(1);
    di35Run($update);
    expect((float) $goal->refresh()->target_value)->toBe(18.0)->and(Goal::query()->where('employee_id', $this->a->id)->count())->toBe(1);

    // A goal locked for review keeps its definition.
    $goal->forceFill(['is_locked' => true])->saveQuietly();
    expect(di35Errors(di35Stage(GoalImports::TYPE, "Employee code,Cycle code,Goal,Target\n{$a},FY26,Grow revenue,20\n"))[1])->toBe(['This goal is locked for review; its definition cannot change.']);
});

it('creates initiatives through the initiatives service and updates their progress', function () {
    $a = $this->a->employee_code;
    $csv = "Employee code,Initiative,Initiative type code,Start date,Due date,Progress (%)\n{$a},Buddy for the joiners,BUDDY,2026-09-21,2026-12-31,0\n"
        ."{$a},Without a type,,,,\n{$a},Bad type,NOPE,,,\n";
    $import = di35Stage(InitiativeImports::TYPE, $csv);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[2])->toBe(['Choose the initiative type (Initiative type code).']);
    di35Run($import);
    $initiative = Initiative::query()->sole();
    expect($initiative->employee_id)->toBe($this->a->id)->and($initiative->status)->toBe('planned')->and($initiative->type?->code)->toBe('BUDDY');

    expect(di35Stage(InitiativeImports::TYPE, "Employee code,Initiative,Progress (%)\n{$a},Buddy for the joiners,40\n", 'new')->skip_count)->toBe(1);
    di35Run(di35Stage(InitiativeImports::TYPE, "Employee code,Initiative,Progress (%)\n{$a},Buddy for the joiners,40\n", 'update'));
    expect($initiative->refresh()->progress)->toBe(40)->and($initiative->status)->toBe('in_progress');
});

it('opens improvement plans as drafts with checkpoints and skips an employee with an open plan', function () {
    $a = $this->a->employee_code;
    $m = $this->manager->employee_code;
    $csv = "Employee code,Reviewer employee code,Reason,Reason codes,Objectives,Start date,End date,Checkpoints\n"
        ."{$a},{$m},Targets missed,PERFORMANCE,Close 10 renewals a month; Reply within a day,2026-10-01,2026-11-30,2026-10-31 Mid-plan review\n"
        ."{$this->b->employee_code},,No objectives,,,2026-10-01,,\n{$this->b->employee_code},,Bad reason,NOPE,One,2026-10-01,,\n";
    $import = di35Stage(ImprovementPlanImports::TYPE, $csv);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[2])->toBe(['A plan needs at least one objective.'])
        ->and(di35Errors($import)[3][0])->toContain('Unknown PIP reason(s): NOPE.');
    di35Run($import);

    $plan = ImprovementPlan::query()->sole();
    expect($plan->status)->toBe('draft')->and($plan->manager_id)->toBe($this->manager->id)->and($plan->reason_codes)->toBe(['PERFORMANCE'])
        ->and(array_column($plan->objectives, 'objective'))->toBe(['Close 10 renewals a month', 'Reply within a day'])
        ->and(ImprovementPlanCheckpoint::query()->where('improvement_plan_id', $plan->id)->value('title'))->toBe('Mid-plan review');

    $again = di35Stage(ImprovementPlanImports::TYPE, "Employee code,Reason,Objectives,Start date\n{$a},Again,One,2026-10-01\n");
    expect($again->skip_count)->toBe(1)->and(ImportTypes::modes(ImprovementPlanImports::TYPE))->toBeNull();
});

it('starts uploaded plans at once when asked, and the PIP policy\'s approval still applies (decision D35-8)', function () {
    $b = $this->b->employee_code;
    $m = $this->manager->employee_code;
    di35Run(di35Stage(ImprovementPlanImports::TYPE, "Employee code,Reviewer employee code,Reason,Objectives,Start date,End date,Checkpoints,Start now\n"
        ."{$b},{$m},Targets missed,Close 10 renewals,2026-09-21,2026-11-20,2026-10-20 Mid-plan review,yes\n"));
    $plan = ImprovementPlan::query()->where('employee_id', $this->b->id)->sole();
    expect($plan->status)->toBe('active')->and(ImprovementPlanCheckpoint::query()->where('improvement_plan_id', $plan->id)->where('title', 'Mid-plan review')->exists())->toBeTrue();

    // Under a PIP policy that asks for approval, "start now" sends the plan for approval — never past it.
    $policy = Policy::create(['type' => 'pip', 'name' => 'PIP', 'code' => 'DI35_PIP']);
    app(Policies::class)->draft($policy, ['approval' => 'workflow']);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'pip', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    $a = $this->a->employee_code;
    $import = di35Run(di35Stage(ImprovementPlanImports::TYPE, "Employee code,Reason,Objectives,Start date,Start now\n{$a},Quality,Fewer reopened tickets,2026-09-21,yes\n{$this->manager->employee_code},Quality,One,2026-09-21,maybe\n"));
    expect(ImprovementPlan::query()->where('employee_id', $this->a->id)->value('status'))->toBe('pending_approval')
        ->and(di35Results($import)[1])->toStartWith('Plan waiting for approval')
        ->and(di35Errors($import)[2])->toBe(['Start now must be yes or no.']);
});

it('submits periodic review ratings and normalises them through the periodic review service', function () {
    di35PerformancePolicy(['review_cycle' => 'quarterly', 'review_window_days' => 15, 'appraisal_basis' => 'average']);
    $cycle = di35Cycle();
    $this->travelTo('2026-10-01 09:00:00');
    app(PeriodicReviews::class)->runSchedule();
    [$a, $b] = [$this->a->employee_code, $this->b->employee_code];

    $import = di35Stage(ReviewRatingImports::TYPE, "Employee code,Cycle code,Review number,Rating,Comment\n{$a},DI35,2,4,Strong quarter\n{$b},DI35,2,9,\n{$b},DI35,3,3,\n");
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[2])->toBe(['Ratings must be between 1 and 5.'])
        ->and(di35Errors($import)[3])->toBe(['This periodic review is not open.']);
    di35Run($import);
    $review = PerformancePeriodReview::query()->where('employee_id', $this->a->id)->where('sequence', 2)->sole();
    expect($review->status)->toBe('submitted')->and((float) $review->rating)->toBe(4.0)->and($review->comment)->toBe('Strong quarter')
        ->and((float) Appraisal::query()->where('employee_id', $this->a->id)->value('computed_rating'))->toBe(4.0);

    // Submitted: the same rating is skipped, another is refused.
    expect(di35Stage(ReviewRatingImports::TYPE, "Employee code,Cycle code,Review number,Rating\n{$a},DI35,2,4\n{$a},di35,2,5\n")->skip_count)->toBe(1);
    expect(di35Errors(di35Stage(ReviewRatingImports::TYPE, "Employee code,Cycle code,Review number,Rating\n{$a},di35,2,5\n"))[1][0])->toContain('was already submitted');

    // Normalised by HR with a reason; the roll-up reads it.
    $normalise = di35Stage(ReviewNormalisationImports::TYPE, "Employee code,Cycle code,Review number,Normalised rating,Reason,Current rating\n{$a},DI35,2,3,Quarter moderation,4\n{$b},DI35,2,3,Panel,\n{$a},DI35,2,2,,\n");
    expect($normalise->update_count)->toBe(1)->and($normalise->error_count)->toBe(2)
        ->and(di35Errors($normalise)[2])->toBe(['Only a submitted periodic review can be normalised.'])
        ->and(di35Errors($normalise)[3][0])->toBe('Say why the ratings are normalised.');
    di35Run($normalise);
    expect((float) $review->refresh()->normalised_rating)->toBe(3.0)->and($review->normalisation_note)->toBe('Quarter moderation')
        ->and((float) Appraisal::query()->where('employee_id', $this->a->id)->value('computed_rating'))->toBe(3.0);
    expect(di35Stage(ReviewNormalisationImports::TYPE, "Employee code,Cycle code,Review number,Normalised rating,Reason\n{$a},DI35,2,3,Again\n")->skip_count)->toBe(1);
});

it('normalises final ratings of a cycle through the normalisation path, with the grid\'s rules', function () {
    $cycle = di35Cycle();
    Appraisal::query()->where('performance_cycle_id', $cycle->id)->update(['computed_rating' => 4]);
    [$a, $b] = [$this->a->employee_code, $this->b->employee_code];
    $csv = "Employee code,Cycle code,Normalised rating,Reason,Current rating\n{$a},DI35,3,Panel moderation,4\n{$b},DI35,7,Panel,\n{$this->manager->employee_code},DI35,3,Panel,2\n";
    $import = di35Stage(RatingNormalisationImports::TYPE, $csv);
    expect($import->update_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[2])->toBe(['Ratings must be between 1 and 5.'])
        ->and(di35Errors($import)[3][0])->toContain('it was changed since the file was made');
    di35Run($import);

    $appraisal = Appraisal::query()->where('employee_id', $this->a->id)->sole();
    expect((float) $appraisal->calibrated_rating)->toBe(3.0)->and($appraisal->calibration_note)->toBe('Panel moderation')
        ->and(CalibrationAdjustment::query()->where('appraisal_id', $appraisal->id)->value('reason'))->toBe('Panel moderation');
    expect(di35Stage(RatingNormalisationImports::TYPE, "Employee code,Cycle code,Normalised rating,Reason\n{$a},DI35,3,Again\n")->skip_count)->toBe(1);

    // Without the calibrate right the upload is not offered at all.
    expect(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'performance.manage']), RatingNormalisationImports::TYPE))->toBeFalse();
});

it('records the final value of key results as check-ins that roll up to the objective', function () {
    $cycle = draftCycle(['code' => 'FY26']);
    $goal = app(Goals::class)->create(['employee_id' => $this->a->id, 'performance_cycle_id' => $cycle->id, 'type' => 'okr', 'title' => 'Delight our customers', 'weight' => 0],
        [['title' => 'Raise NPS to 60', 'measure_type' => 'numeric', 'start_value' => 40, 'target_value' => 60, 'weight' => 50],
            ['title' => 'Answer every ticket within a day', 'measure_type' => 'percentage', 'start_value' => 0, 'target_value' => 100, 'weight' => 50]], $this->hr);
    $a = $this->a->employee_code;
    $csv = "Employee code,Cycle code,Objective,Key result,Achieved value,Note\n{$a},FY26,Delight our customers,Raise NPS to 60,50,Year-end survey\n"
        ."{$a},FY26,Delight our customers,Answer every ticket within a day,100,\n{$a},FY26,Delight our customers,Unknown KR,1,\n{$a},FY26,Other objective,Raise NPS to 60,1,\n";
    $import = di35Stage(OkrFinalImports::TYPE, $csv);
    expect($import->update_count)->toBe(2)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[3])->toBe(["The objective has no key result 'Unknown KR'."]);
    di35Run($import);

    $nps = KeyResult::query()->where('title', 'Raise NPS to 60')->sole();
    expect((float) $nps->current_value)->toBe(50.0)->and((float) $nps->progress)->toBe(50.0)
        ->and(GoalCheckIn::query()->where('goal_id', $goal->id)->where('source', 'import')->count())->toBe(2)
        ->and((float) $goal->refresh()->progress)->toBe(75.0);
    expect(di35Stage(OkrFinalImports::TYPE, "Employee code,Cycle code,Objective,Key result,Achieved value\n{$a},FY26,Delight our customers,Raise NPS to 60,50\n")->skip_count)->toBe(1);
});

it('sets the total achievement of objectives only while the achievement override is on', function () {
    $goal = app(Goals::class)->create(['employee_id' => $this->a->id, 'title' => 'Delight our customers', 'measure_type' => 'percentage', 'target_value' => 100], [], $this->hr);
    $a = $this->a->employee_code;
    $csv = "Employee code,Objective,Total achievement (%),Reason\n{$a},Delight our customers,92,Year-end panel\n";

    $off = di35Stage(OkrTotalImports::TYPE, $csv);
    expect($off->error_count)->toBe(1)->and(di35Errors($off)[1][0])->toContain('performance.goals.achievement_override');

    app(SettingsRepository::class)->set('performance.goals.achievement_override', true, 'test');
    $on = di35Stage(OkrTotalImports::TYPE, $csv."{$a},Delight our customers,95,Twice\n{$a},No such goal,50,x\n{$this->b->employee_code},Anything,1200,x\n");
    expect($on->update_count)->toBe(1)->and($on->error_count)->toBe(3)
        ->and(di35Errors($on)[2])->toBe(['The same objective is in row 1.'])
        ->and(di35Errors($on)[4][0])->toContain('between 0 and 1000');
    di35Run($on);
    expect((float) $goal->refresh()->achievement_override)->toBe(92.0)->and($goal->achievement_override_reason)->toBe('Year-end panel')
        ->and($goal->achievement_overridden_by)->toBe($this->hr->id);
    expect(di35Stage(OkrTotalImports::TYPE, $csv)->skip_count)->toBe(1);
});

it('validates the sample file of every performance process against the organisation\'s own data', function () {
    $c = activeEmployee($this->manager);
    di35PerformancePolicy(['review_cycle' => 'quarterly', 'review_window_days' => 15, 'appraisal_basis' => 'average']);
    $cycle = di35Cycle();
    $this->travelTo('2026-10-01 09:00:00');
    $reviews = app(PeriodicReviews::class);
    $reviews->runSchedule();
    foreach ([$this->a, $this->b] as $employee) {
        $reviews->submit(PerformancePeriodReview::query()->where('employee_id', $employee->id)->where('sequence', 2)->sole(), 4, null, $this->hr);
    }
    app(Goals::class)->create(['employee_id' => $this->a->id, 'performance_cycle_id' => $cycle->id, 'type' => 'okr', 'title' => 'Delight our customers'],
        [['title' => 'Raise NPS to 60', 'measure_type' => 'numeric', 'start_value' => 40, 'target_value' => 60, 'weight' => 50],
            ['title' => 'Answer every ticket within a day', 'measure_type' => 'percentage', 'start_value' => 0, 'target_value' => 100, 'weight' => 50]], $this->hr);
    app(Goals::class)->create(['employee_id' => $c->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Ship the mobile app'], [], $this->hr);
    app(SettingsRepository::class)->set('performance.goals.achievement_override', true, 'test');

    foreach (['performance.goal_bank', 'performance.goals', 'performance.initiatives', 'performance.improvement_plans', 'performance.review_ratings',
        'performance.normalise_final', 'performance.normalise_review', 'performance.okr_final', 'performance.okr_total'] as $process) {
        $import = di35StageSample($process);
        expect($import->error_count)->toBe(0, $process.': '.json_encode(di35Errors($import)))->and($import->row_count)->toBeGreaterThanOrEqual(2, $process);
    }
});

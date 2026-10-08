<?php

use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\CareerTrack;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Career\Services\CareerMovements;
use App\Domain\Career\Services\CareerProfiles;
use App\Domain\Career\Services\RoleGaps;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Learning\Services\Learning;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\CareerAspiration;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Skills\Services\SkillAssessments;
use App\Domain\Skills\Services\SkillProfiles;
use App\Domain\Talent\Services\TalentAccess;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Learning/LearningTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['career.self', 'career.team', 'skills.assess', 'development.team']);
    $this->employee = activeEmployee($this->manager, ['career.self', 'skills.self', 'learning.learn']);
    $this->colleague = activeEmployee(null, ['career.self']);
    $this->architecture = app(CareerArchitecture::class);
    $this->profiles = app(CareerProfiles::class);
    $this->lead = Designation::query()->create(['name' => 'Team Leader', 'code' => 'TL']);
});

it('publishes immutable career path versions on configurable tracks', function () {
    $track = CareerTrack::create(['code' => 'mgr', 'name' => 'People manager', 'track_type' => 'people_manager']);
    $specialist = CareerTrack::create(['code' => 'ic', 'name' => 'Specialist', 'track_type' => 'technical_specialist']);
    expect(fn () => CareerTrack::create(['code' => 'x', 'name' => 'X', 'track_type' => 'astronaut']))->toThrow(RuntimeException::class, 'Unknown career track type');

    $caller = Designation::query()->create(['name' => 'Caller', 'code' => 'CALLER']);
    $path = CareerPath::create(['name' => 'Contact centre', 'code' => 'cc', 'career_track_id' => $track->id]);
    $path->steps()->create(['designation_id' => $caller->id, 'sort_order' => 10]);
    $path->steps()->create(['designation_id' => $this->lead->id, 'sort_order' => 20, 'typical_years' => 2]);

    expect(fn () => $this->architecture->publishPath($path, null, $this->employee->user))->toThrow(RuntimeException::class, 'career.manage');
    $v1 = $this->architecture->publishPath($path, '2026-10-01', $this->admin);
    expect($v1->version)->toBe(1)->and(array_column($v1->steps, 'designation'))->toBe(['Caller', 'Team Leader'])
        ->and($v1->scope['career_track_id'])->toBe($track->id)
        ->and(fn () => $v1->update(['name' => 'x']))->toThrow(RuntimeException::class, 'immutable')
        ->and($path->refresh()->current_version_id)->toBe($v1->id)
        ->and($specialist->track_type)->toBe('technical_specialist');
});

it('versions role requirements on pinned skill-scale versions and closes the previous window', function () {
    $skill = Skill::create(['name' => 'Coaching', 'code' => 'COACH']);
    $v1 = $this->architecture->publishRequirements($this->lead, null, ['skills' => [['skill_id' => $skill->id, 'level' => 3]], 'min_experience_years' => 2], '2026-01-01', $this->admin);
    expect($v1->skills[0]['skill_scale_version_id'])->not->toBeNull()
        ->and(fn () => $this->architecture->publishRequirements($this->lead, null, ['skills' => [['skill_id' => $skill->id, 'level' => 9]]], '2026-06-01', $this->admin))->toThrow(RuntimeException::class, 'not on the scale')
        ->and(fn () => $this->architecture->publishRequirements($this->lead, null, ['skills' => []], '2025-12-01', $this->admin))->toThrow(RuntimeException::class, 'must start after');

    $v2 = $this->architecture->publishRequirements($this->lead, null, ['skills' => [['skill_id' => $skill->id, 'level' => 4]]], '2026-07-01', $this->admin);
    expect($v1->refresh()->effective_to->toDateString())->toBe('2026-06-30')
        ->and($this->architecture->requirementsFor($this->lead->id, null, '2026-03-01')->id)->toBe($v1->id)
        ->and($this->architecture->requirementsFor($this->lead->id, null, '2026-10-01')->id)->toBe($v2->id)
        ->and(fn () => $v2->update(['skills' => []]))->toThrow(RuntimeException::class, 'immutable')
        ->and(RoleRequirementVersion::query()->count())->toBe(2);
});

it('reports skill, learning and experience gaps as facts with the evidence basis, never a score', function () {
    $skill = Skill::create(['name' => 'Coaching', 'code' => 'COACH']);
    $course = publishedCourse(['code' => 'LEAD101', 'title' => 'Leading teams', 'validity_months' => null], false);
    $cert = publishedCourse(['code' => 'CERTL', 'title' => 'Leadership certificate', 'validity_months' => 24], false);
    $this->architecture->publishRequirements($this->lead, null, ['skills' => [['skill_id' => $skill->id, 'level' => 4]], 'learning' => [['type' => 'course', 'id' => $course->id]], 'certifications' => [['course_id' => $cert->id]], 'min_experience_years' => 1], '2026-01-01', $this->admin);

    app(SkillProfiles::class)->declare($this->employee, $skill->id, 4, null, $this->employee->user); // self-declared, unverified
    $assessment = app(SkillAssessments::class)->draft($this->employee, $skill->id, 'formal', ['level' => 2], $this->admin);
    app(SkillAssessments::class)->finalize($assessment, $this->admin);
    app(Learning::class)->enrol($this->employee, $course);

    $gaps = app(RoleGaps::class)->for($this->employee, $this->lead->id);
    expect($gaps['skills'][0])->toMatchArray(['skill' => 'Coaching', 'required_level' => 4.0, 'current_level' => 2.0, 'basis' => 'verified', 'verified' => true, 'self_declared' => 4.0, 'gap' => 2.0, 'comparable' => true])
        ->and($gaps['learning'][0]['status'])->toBe('in_progress')
        ->and($gaps['certifications'][0]['status'])->toBe('missing')
        ->and($gaps['experience']['required_years'])->toBe(1.0)
        ->and(array_keys($gaps))->not->toContain('score', 'rank', 'recommendation');
});

it('keeps aspirations as effective-dated history and mirrors the newest into the legacy snapshot', function () {
    $first = $this->profiles->recordAspiration($this->employee, 'medium', ['target_designation_id' => $this->lead->id, 'aspiration' => 'Lead a team'], $this->employee->user);
    $this->travelTo('2026-11-01 09:00:00');
    $second = $this->profiles->recordAspiration($this->employee, 'medium', ['aspiration' => 'Move into analytics'], $this->employee->user);

    expect($first->refresh()->status)->toBe('superseded')->and($first->effective_to->toDateString())->toBe('2026-10-31')
        ->and($first->aspiration)->toBe('Lead a team')
        ->and($second->status)->toBe('current')
        ->and(CareerAspirationEntry::query()->where('employee_id', $this->employee->id)->count())->toBe(2)
        ->and(CareerAspiration::query()->where('employee_id', $this->employee->id)->value('aspirations'))->toBe('Move into analytics')
        ->and(fn () => $second->update(['aspiration' => 'rewrite']))->toThrow(RuntimeException::class, 'history')
        ->and(fn () => $this->profiles->recordAspiration($this->colleague, 'short', ['aspiration' => 'x'], $this->employee->user))->toThrow(RuntimeException::class, 'kept by the employee');
});

it('keeps career goals separate, closes them through transitions and never changes employment', function () {
    $goal = $this->profiles->createGoal($this->employee, 'Become Team Leader', 'role', ['target_designation_id' => $this->lead->id, 'target_date' => '2027-06-30'], $this->employee->user);
    $positionsBefore = $this->employee->positions()->count();

    $this->profiles->transitionGoal($goal, 'achieved', 'Promoted through the normal process', $this->employee->user);
    expect($goal->refresh()->status)->toBe('achieved')->and($goal->closed_at)->not->toBeNull()
        ->and($this->employee->positions()->count())->toBe($positionsBefore)
        ->and(fn () => $this->profiles->transitionGoal($goal, 'active', null, $this->employee->user))->toThrow(RuntimeException::class)
        ->and(fn () => $goal->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => $this->profiles->createGoal($this->employee, 'X', 'telepathy', [], $this->employee->user))->toThrow(RuntimeException::class, 'Unknown career goal type')
        ->and(CareerGoal::query()->count())->toBe(1);
});

it('shares career details with managers only as the employee chooses, and never with mentors', function () {
    $access = app(TalentAccess::class);
    $mentor = activeEmployee(null, ['career.team']);
    ReportingRelationship::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $mentor->id, 'type' => 'mentor', 'is_primary' => false, 'effective_from' => '2026-01-01']);

    expect($access->mayViewCareer($this->manager->user, $this->employee->id, 'aspirations'))->toBeFalse()
        ->and($access->mayViewCareer($this->manager->user, $this->employee->id, 'goals'))->toBeTrue()
        ->and($access->mayViewCareer($mentor->user, $this->employee->id, 'goals'))->toBeFalse()
        ->and($access->mayViewCareer($this->colleague->user, $this->employee->id, 'profile'))->toBeFalse();

    $profile = $this->profiles->updateProfile($this->employee, ['share_aspirations_with_manager' => true, 'mobility' => ['relocation' => true]], 0, $this->employee->user);
    expect($access->mayViewCareer($this->manager->user, $this->employee->id, 'aspirations'))->toBeTrue()
        ->and(fn () => $this->profiles->updateProfile($this->employee, ['development_priorities' => 'x'], 0, $this->employee->user))->toThrow(RuntimeException::class, 'changed meanwhile')
        ->and(fn () => $this->profiles->updateProfile($this->employee, ['mobility' => ['teleport' => true]], null, $this->employee->user))->toThrow(RuntimeException::class, 'Unknown mobility option');

    // An HR career administrator cannot change the employee's sharing choices.
    $this->profiles->updateProfile($this->employee, ['share_aspirations_with_manager' => false, 'development_priorities' => 'Finance'], null, $this->admin);
    expect($profile->refresh()->share_aspirations_with_manager)->toBeTrue()->and($profile->development_priorities)->toBe('Finance');
});

it('records mobility interest without any recruitment object and reads movement history from employment', function () {
    $interest = $this->profiles->addMobilityInterest($this->employee, 'position', ['designation_id' => $this->lead->id, 'notes' => 'Open to TL roles'], $this->employee->user);
    expect(fn () => $this->profiles->addMobilityInterest($this->employee, 'position', [], $this->employee->user))->toThrow(RuntimeException::class, 'names what')
        ->and(fn () => $interest->update(['notes' => 'edited']))->toThrow(RuntimeException::class, 'withdrawn, never edited');
    $this->profiles->withdrawMobilityInterest($interest, $this->employee->user);
    expect($interest->refresh()->status)->toBe('withdrawn')->and(MobilityInterest::query()->count())->toBe(1);

    $history = app(CareerMovements::class)->for($this->employee);
    expect($history)->not->toBeEmpty()->and($history[0])->toHaveKeys(['effective_from', 'change_type', 'designation']);
});

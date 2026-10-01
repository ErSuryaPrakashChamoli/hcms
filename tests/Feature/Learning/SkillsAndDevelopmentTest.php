<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Services\Certificates;
use App\Domain\Learning\Services\Learning;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Services\DevelopmentNeeds;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Services\SkillAssessments;
use App\Domain\Skills\Services\SkillProfiles;
use App\Domain\Skills\Services\SkillScales;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/LearningTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['learning.learn', 'learning.assign', 'skills.self', 'skills.assess', 'development.own', 'development.team']);
    $this->employee = activeEmployee($this->manager, ['learning.learn', 'skills.self', 'development.own']);
    $this->stranger = activeEmployee(null, ['skills.assess', 'development.team']);
    $this->skill = Skill::create(['name' => 'SQL', 'code' => 'SQL', 'skill_type' => 'technical']);
    $this->profiles = app(SkillProfiles::class);
    $this->assessments = app(SkillAssessments::class);
});

it('keeps skill scale versions immutable and validates levels against them', function () {
    $scale = SkillScale::create(['code' => 'FOUR', 'name' => 'Four levels']);
    expect(fn () => app(SkillScales::class)->publish($scale, [['value' => 1, 'label' => 'Only']]))->toThrow(RuntimeException::class, 'at least two')
        ->and(fn () => app(SkillScales::class)->publish($scale, [['value' => 1, 'label' => 'A'], ['value' => 1, 'label' => 'B']]))->toThrow(RuntimeException::class, 'unique');

    $v1 = app(SkillScales::class)->publish($scale, [['value' => 3, 'label' => 'Strong'], ['value' => 1, 'label' => 'Basic']]);
    expect(array_column($v1->levels, 'label'))->toBe(['Basic', 'Strong'])
        ->and(fn () => $v1->update(['levels' => []]))->toThrow(RuntimeException::class, 'immutable');

    $this->skill->update(['skill_scale_id' => $scale->id]);
    expect(fn () => $this->profiles->declare($this->employee, $this->skill->id, 2, null, $this->employee->user))->toThrow(RuntimeException::class, 'not on the skill scale');
});

it('keeps sourced skill history: self-declared never overrides a verified assessment', function () {
    $declared = $this->profiles->declare($this->employee, $this->skill->id, 4, 'Ten years of SQL', $this->employee->user);
    expect($declared->is_verified)->toBeFalse()->and($declared->source)->toBe('self')
        ->and(fn () => $this->profiles->declare($this->manager, $this->skill->id, 4, null, $this->employee->user))->toThrow(RuntimeException::class, 'their own skill')
        ->and(fn () => $declared->update(['current_level' => 1]))->toThrow(RuntimeException::class, 'immutable');

    $formal = $this->assessments->draft($this->employee, $this->skill->id, 'formal', ['level' => 2, 'target_level' => 4, 'comments' => 'Solid joins, weak tuning', 'private_notes' => 'Discuss tuning course'], $this->admin);
    $this->assessments->finalize($formal, $this->admin, recordNeed: true);

    $row = collect($this->profiles->profile($this->employee))->firstWhere('skill_id', $this->skill->id);
    expect($row)->toMatchArray(['level' => 2.0, 'basis' => 'verified', 'verified' => true, 'self_declared' => 4.0, 'target' => 4.0, 'gap' => 2.0])
        ->and(DevelopmentNeed::query()->where('employee_id', $this->employee->id)->where('source_type', 'skill_assessment')->value('target_level'))->toEqual(4.0);

    // A later self-declaration supersedes only the previous self-declaration.
    $this->profiles->declare($this->employee, $this->skill->id, 3, null, $this->employee->user);
    expect(collect($this->profiles->profile($this->employee))->firstWhere('skill_id', $this->skill->id)['level'])->toBe(2.0)
        ->and(EmployeeSkill::query()->where('employee_id', $this->employee->id)->where('source', 'self')->where('status', 'superseded')->count())->toBe(1);
});

it('authorises, finalizes, corrects and protects skill assessments', function () {
    expect(fn () => $this->assessments->draft($this->employee, $this->skill->id, 'manager', ['level' => 3], $this->stranger->user))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => $this->assessments->draft($this->employee, $this->skill->id, 'manager', ['level' => 3], $this->employee->user))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => $this->assessments->draft($this->employee, $this->skill->id, 'formal', ['level' => 3], $this->manager->user))->toThrow(RuntimeException::class, 'not allowed');

    $assessment = $this->assessments->draft($this->employee, $this->skill->id, 'manager', ['level' => 3, 'comments' => 'Good', 'private_notes' => 'Ready for lead role soon'], $this->manager->user);
    expect($assessment->toArray())->not->toHaveKey('private_notes')
        ->and(DB::table('skill_assessments')->where('id', $assessment->id)->value('private_notes'))->not->toContain('lead role');

    $this->assessments->finalize($assessment, $this->manager->user);
    expect(fn () => $this->assessments->finalize($assessment->refresh(), $this->manager->user))->toThrow(RuntimeException::class, 'already finalized')
        ->and(fn () => $assessment->refresh()->update(['level' => 4]))->toThrow(RuntimeException::class, 'immutable')
        ->and($this->assessments->privateNotesFor($assessment, $this->employee->user))->toBeNull()
        ->and($this->assessments->privateNotesFor($assessment, $this->manager->user))->toBe('Ready for lead role soon');

    $before = AuditEvent::query()->where('action', 'VIEW')->count();
    expect($this->assessments->privateNotesFor($assessment, $this->admin))->toBe('Ready for lead role soon')
        ->and(AuditEvent::query()->where('action', 'VIEW')->count())->toBe($before + 1);

    $correction = $this->assessments->correct($assessment->refresh(), 2, 'Mis-keyed level', $this->manager->user);
    $this->assessments->finalize($correction, $this->manager->user);
    expect($assessment->refresh()->status)->toBe('superseded')->and((float) $assessment->level)->toBe(3.0)
        ->and(collect($this->profiles->profile($this->employee))->firstWhere('skill_id', $this->skill->id)['level'])->toBe(2.0);
});

it('records skills gained from a completed course version and issues a revocable certificate with a private document', function () {
    Storage::fake('local');
    $course = publishedCourse(['code' => 'SQL201', 'title' => 'SQL tuning', 'validity_months' => 12, 'skill_outcomes' => [['skill_id' => $this->skill->id, 'level' => 3]]], false);
    $enrolment = app(Learning::class)->enrol($this->employee, $course);
    app(Learning::class)->complete($enrolment, 90.0, $this->admin);

    $certificate = LearningCertificate::query()->where('learning_enrolment_id', $enrolment->id)->sole();
    $skill = EmployeeSkill::query()->where('employee_id', $this->employee->id)->where('source', 'learning')->sole();
    expect($skill->is_verified)->toBeTrue()->and((float) $skill->current_level)->toBe(3.0)
        ->and($certificate->course_version_id)->toBe($enrolment->refresh()->course_version_id)
        ->and($certificate->verification_code)->toHaveLength(40)
        ->and($certificate->toArray())->not->toHaveKey('verification_code')
        ->and(fn () => $certificate->update(['expires_on' => '2030-01-01']))->toThrow(RuntimeException::class, 'never extended');

    $certificates = app(Certificates::class);
    $certificates->attachDocument($certificate, '%PDF-1.4 cert', 'cert.pdf', $this->admin);
    Storage::disk('local')->assertExists($certificate->refresh()->document_path);
    expect($certificate->document_sha256)->toBe(hash('sha256', '%PDF-1.4 cert'))
        ->and(fn () => $certificates->attachDocument($certificate, 'other', 'x.pdf'))->toThrow(RuntimeException::class, 'never replaced')
        ->and(fn () => $certificates->revoke($certificate, 'Fraud', $this->employee->user))->toThrow(RuntimeException::class, 'learning.certificates');

    $certificates->revoke($certificate, 'Assessment integrity breach', $this->admin);
    expect($certificate->refresh()->status)->toBe('revoked')
        ->and(fn () => $certificate->update(['status' => 'valid']))->toThrow(RuntimeException::class, 'read-only');
});

it('expires certificates without extending them and creates recertification for mandatory learning', function () {
    $course = publishedCourse(['code' => 'POSH26', 'title' => 'POSH', 'validity_months' => 12], false);
    $enrolment = app(Learning::class)->enrol($this->employee, $course, null, null, null, null, true);
    app(Learning::class)->complete($enrolment);
    $certificate = LearningCertificate::query()->where('learning_enrolment_id', $enrolment->id)->sole();
    expect($certificate->recertification_due_on->toDateString())->toBe('2027-09-21');

    $this->travelTo('2027-08-01 06:00:00');
    $result = app(Certificates::class)->tick();
    expect($result['recertification'])->toBe(1)
        ->and(LearningEnrolment::query()->where('employee_id', $this->employee->id)->where('course_id', $course->id)->where('status', 'assigned')->value('reason'))->toContain('Recertification');

    $this->travelTo('2027-09-25 06:00:00');
    app(Certificates::class)->tick();
    expect($certificate->refresh()->status)->toBe('expired')->and($certificate->expires_on->toDateString())->toBe('2027-09-21')
        ->and($enrolment->refresh()->status)->toBe('expired');
});

it('builds development plans from needs, recommends learning without auto-enrolling and keeps closed plans read-only', function () {
    $course = publishedCourse(['code' => 'SQL301', 'title' => 'Advanced SQL', 'validity_months' => null, 'skill_outcomes' => [['skill_id' => $this->skill->id, 'level' => 4]]], false);
    app(DevelopmentNeeds::class)->record($this->employee, 'Query performance', 'manager', null, null, 'high', null, $this->manager->user, ['skill_id' => $this->skill->id, 'current_level' => 2, 'target_level' => 4]);
    $plans = app(DevelopmentPlans::class);

    expect(fn () => $plans->create($this->employee, 'Plan', [], $this->stranger->user))->toThrow(RuntimeException::class, 'their manager or L&D');
    $plan = $plans->create($this->employee, 'Data skills 2026', ['target_date' => '2027-03-31', 'private_notes' => 'Promotion case later'], $this->manager->user);
    $items = $plans->addOpenNeeds($plan, $this->manager->user);
    $item = $items->first();

    expect($items)->toHaveCount(1)->and($item->item_type)->toBe('skill_gap')->and((float) $item->target_level)->toBe(4.0)
        ->and($plans->recommendations($item)->pluck('code')->all())->toBe(['SQL301'])
        ->and(LearningEnrolment::query()->where('employee_id', $this->employee->id)->where('course_id', $course->id)->exists())->toBeFalse()
        ->and($plans->privateNotesFor($plan, $this->employee->user))->toBeNull()
        ->and($plans->privateNotesFor($plan, $this->manager->user))->toBe('Promotion case later');

    $item->update(['course_id' => $course->id]);
    expect(fn () => $plans->enrolItem($item->refresh(), $this->manager->user))->toThrow(RuntimeException::class, 'Activate the plan');
    $plans->transition($plan, 'active', null, $this->manager->user);
    $enrolment = $plans->enrolItem($item->refresh(), $this->manager->user);
    expect($enrolment->status)->toBe('assigned');

    expect(fn () => $plans->transition($plan->refresh(), 'completed', null, $this->manager->user))->toThrow(RuntimeException::class, 'open item')
        ->and(fn () => $plans->completeItem($item->refresh(), null, $this->manager->user))->toThrow(RuntimeException::class, 'not completed yet');
    app(Learning::class)->complete($enrolment);
    $plans->completeItem($item->refresh(), 'Done', $this->manager->user);
    $plans->transition($plan->refresh(), 'completed', null, $this->manager->user);

    expect($plan->refresh()->status)->toBe('completed')
        ->and(fn () => $plan->update(['title' => 'Rewrite']))->toThrow(RuntimeException::class, 'read-only history')
        ->and(fn () => $plans->addItem($plan, 'milestone', 'Late', [], $this->manager->user))->toThrow(RuntimeException::class, 'read-only history')
        ->and(DevelopmentPlan::query()->whereKey($plan->id)->value('lock_version'))->toBeGreaterThan(0);
});

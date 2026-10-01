<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Policies\EnrolmentPolicy;
use App\Domain\Learning\Services\Certificates;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\LearningAnalytics;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Services\SkillAssessments;
use App\Domain\Skills\Services\SkillProfiles;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
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
    $this->manager = activeEmployee(null, ['learning.learn', 'learning.assign', 'learning.team', 'learning.approve', 'skills.assess', 'development.team']);
    $this->employee = activeEmployee($this->manager, ['learning.learn', 'skills.self', 'development.own']);
    $this->colleague = activeEmployee(null, ['learning.learn', 'skills.self', 'development.own']);
    $this->course = publishedCourse(['code' => 'SEC1', 'title' => 'Security basics', 'validity_months' => 12], false);
    $this->learning = app(Learning::class);
    $this->read = app(ApiKeys::class)->issue('BI', ['learning.read']);
    $this->write = app(ApiKeys::class)->issue('LMS sync', ['learning.read', 'learning.write']);
    $this->costs = app(ApiKeys::class)->issue('Finance', ['learning.read', 'learning.costs']);
});

it('isolates employees, scopes managers by configured relationships and never lets mentors see learning', function () {
    $mine = $this->learning->enrol($this->employee, $this->course);
    $theirs = $this->learning->enrol($this->colleague, $this->course);
    $mentor = activeEmployee(null, ['learning.team', 'learning.assign']);
    ReportingRelationship::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $mentor->id, 'type' => 'mentor', 'is_primary' => false, 'effective_from' => '2026-01-01']);

    expect(EnrolmentPolicy::isOwn($this->employee->user, $mine))->toBeTrue()
        ->and($this->employee->user->can('view', $theirs))->toBeFalse()
        ->and($this->manager->user->can('view', $mine))->toBeTrue()
        ->and($this->manager->user->can('view', $theirs))->toBeFalse()
        ->and($mentor->user->can('view', $mine))->toBeFalse();

    // Managers with learning.assign no longer list the whole tenant.
    $this->actingAs($this->manager->user);
    expect(LearningEnrolmentResource::getEloquentQuery()->pluck('employee_id')->unique()->values()->all())->toBe([$this->employee->id]);
    $this->actingAs($this->employee->user);
    expect(LearningEnrolmentResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine->id]);
});

it('refuses employees altering finalized records, other profiles or private assessment notes', function () {
    $enrolment = $this->learning->enrol($this->employee, $this->course);
    $this->learning->complete($enrolment, 80.0, $this->admin);
    $skill = Skill::create(['name' => 'Threat modelling', 'code' => 'THREAT']);

    expect(fn () => $this->learning->updateProgress($enrolment->refresh(), 50, $this->employee->user))->toThrow(RuntimeException::class)
        ->and(fn () => app(SkillProfiles::class)->declare($this->colleague, $skill->id, 3, null, $this->employee->user))->toThrow(RuntimeException::class, 'their own')
        ->and(fn () => app(SkillAssessments::class)->draft($this->colleague, $skill->id, 'manager', ['level' => 2], $this->employee->user))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => app(DevelopmentPlans::class)->create($this->colleague, 'Not mine', [], $this->employee->user))->toThrow(RuntimeException::class);

    $assessment = app(SkillAssessments::class)->draft($this->employee, $skill->id, 'manager', ['level' => 2, 'private_notes' => 'Needs supervision'], $this->manager->user);
    app(SkillAssessments::class)->finalize($assessment, $this->manager->user);
    expect(app(SkillAssessments::class)->privateNotesFor($assessment->refresh(), $this->employee->user))->toBeNull()
        ->and(fn () => app(SkillAssessments::class)->finalize($assessment, $this->manager->user))->toThrow(RuntimeException::class);
});

it('authorises and audits certificate downloads through signed, tenant-bound URLs', function () {
    Storage::fake('local');
    $enrolment = $this->learning->enrol($this->employee, $this->course);
    $this->learning->complete($enrolment);
    $certificate = LearningCertificate::query()->where('learning_enrolment_id', $enrolment->id)->sole();
    app(Certificates::class)->attachDocument($certificate, '%PDF cert', 'cert.pdf', $this->admin);
    $url = app(Certificates::class)->downloadUrl($certificate->refresh());
    actAsTenant(null);

    $this->actingAs($this->employee->user)->get($url)->assertOk();
    $this->actingAs($this->colleague->user)->get($url)->assertForbidden();
    $this->actingAs($this->employee->user)->get(route('learning.certificates.download', ['certificate' => $certificate->id]))->assertForbidden(); // unsigned

    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('metadata->event', 'certificate_downloaded')->count())->toBe(1);
});

it('serves the learning API with field filtering, idempotent writes and no cross-tenant ids', function () {
    $this->course->update(['cost' => 1500, 'currency' => 'INR']);
    $enrolment = $this->learning->enrol($this->employee, $this->course);
    $this->learning->complete($enrolment, 91.0, $this->admin);
    $skill = Skill::create(['name' => 'Incident response', 'code' => 'IR']);
    $assessment = app(SkillAssessments::class)->draft($this->employee, $skill->id, 'manager', ['level' => 3, 'comments' => 'Calm under pressure', 'evidence' => 'Ran the May drill', 'private_notes' => 'Flight risk'], $this->manager->user);
    app(SkillAssessments::class)->finalize($assessment, $this->manager->user);
    $plan = app(DevelopmentPlans::class)->create($this->employee, 'Security path', ['summary' => 'Confidential summary', 'private_notes' => 'Private plan note'], $this->manager->user);

    $key = ['X-Api-Key' => $this->read['plaintext']];
    $body = collect(['catalogue', 'courses', 'enrolments', 'assignments', 'completions', 'certificates', 'skills', 'employee-skills', 'assessments', 'development-plans', 'analytics', 'learning-paths', 'programs'])
        ->map(fn ($path) => $this->withHeaders($key)->getJson("/api/v1/learning/{$path}")->assertOk()->getContent())->implode("\n");

    foreach (['Calm under pressure', 'Ran the May drill', 'Flight risk', 'Confidential summary', 'Private plan note', 'verification_code', 'document_path', '"employee_id"', '1500'] as $secret) {
        expect($body)->not->toContain($secret);
    }
    expect($this->withHeaders(['X-Api-Key' => $this->costs['plaintext']])->getJson('/api/v1/learning/catalogue')->json('data.0.cost'))->toEqual(1500)
        ->and($this->withHeaders($key)->getJson('/api/v1/learning/certificates')->json('data.0.course_version'))->toBe(1)
        ->and($this->withHeaders($key)->getJson('/api/v1/learning/assessments')->json('data.0.level'))->toEqual(3);

    // Writes: scope required; enrolment idempotent; progress absolute.
    $other = publishedCourse(['code' => 'SEC2', 'title' => 'Phishing', 'validity_months' => null, 'delivery_mode' => 'self_paced'], false);
    $payload = ['employee_code' => $this->colleague->employee_code, 'course_code' => 'sec2'];
    $this->withHeaders($key)->postJson('/api/v1/learning/enrolments', $payload)->assertForbidden();
    $first = $this->withHeaders(['X-Api-Key' => $this->write['plaintext'], 'Idempotency-Key' => 'lms-1'])->postJson('/api/v1/learning/enrolments', $payload)->assertCreated();
    $again = $this->withHeaders(['X-Api-Key' => $this->write['plaintext'], 'Idempotency-Key' => 'lms-1'])->postJson('/api/v1/learning/enrolments', $payload)->assertOk();
    expect($first->json('data.id'))->toBe($again->json('data.id'))->and($first->json('data.status'))->toBe('assigned');

    $this->withHeaders(['X-Api-Key' => $this->write['plaintext']])->postJson("/api/v1/learning/enrolments/{$first->json('data.id')}/progress", ['progress' => 40])->assertOk()->assertJsonPath('data.progress', 40);
    $this->withHeaders(['X-Api-Key' => $this->write['plaintext']])->postJson("/api/v1/learning/enrolments/{$first->json('data.id')}/progress", ['progress' => 140])->assertStatus(422);

    // An id from another tenant is invisible to this key (404, not 403).
    $otherTenant = provisionTenant();
    actAsTenant($otherTenant);
    $foreign = LearningEnrolment::query()->create(['employee_id' => activeEmployee()->id, 'course_id' => publishedCourse(['code' => 'X1'], false)->id, 'status' => 'enrolled']);
    actAsTenant($this->tenant);
    $this->withHeaders(['X-Api-Key' => $this->write['plaintext']])->postJson("/api/v1/learning/enrolments/{$foreign->id}/progress", ['progress' => 10])->assertNotFound();
});

it('keeps learning analytics aggregate and suppresses small groups', function () {
    $skill = Skill::create(['name' => 'Kotlin', 'code' => 'KOTLIN']);
    app(SkillProfiles::class)->setTarget($this->employee, $skill->id, 3, $this->manager->user);
    $summary = app(LearningAnalytics::class)->summary();

    expect($summary['skill_gaps'][0])->toBe(['skill' => 'Kotlin', 'suppressed' => true])
        ->and($summary['costs'])->toBeNull()
        ->and(collect($summary['by_department'])->every(fn ($row) => $row['suppressed']))->toBeTrue();

    config(['peopleos.learning.analytics_min_group' => 1]);
    expect(app(LearningAnalytics::class)->skillGaps()[0])->toMatchArray(['skill' => 'Kotlin', 'employees' => 1, 'average_gap' => 3.0]);
});

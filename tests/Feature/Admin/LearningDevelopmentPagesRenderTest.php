<?php

use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Learning\Models\LearningProgram;
use App\Domain\Learning\Models\LearningProvider;
use App\Domain\Learning\Services\Learning;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Services\SkillAssessments;
use App\Domain\Skills\Services\SkillScales;
use App\Filament\Pages\LearningDashboard;
use App\Filament\Pages\MyLearning;
use App\Filament\Pages\TeamLearning;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\DevelopmentPlans\DevelopmentPlanResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LearningAssignments\LearningAssignmentResource;
use App\Filament\Resources\LearningCertificates\LearningCertificateResource;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Resources\LearningInstructors\LearningInstructorResource;
use App\Filament\Resources\LearningPrograms\LearningProgramResource;
use App\Filament\Resources\LearningProviders\LearningProviderResource;
use App\Filament\Resources\SkillAssessments\SkillAssessmentResource;
use App\Filament\Resources\SkillScales\SkillScaleResource;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Learning/LearningTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['learning.learn', 'learning.assign', 'learning.team', 'learning.approve', 'skills.assess', 'skills.self', 'development.team', 'development.own']);
    $this->employee = activeEmployee($this->manager, ['learning.learn', 'skills.self', 'development.own']);
    $course = publishedCourse(['code' => 'SAFE', 'title' => 'Workplace safety', 'validity_months' => 12], false);
    $enrolment = app(Learning::class)->enrol($this->employee, $course, now()->addDays(10));
    app(Learning::class)->complete(app(Learning::class)->enrol($this->employee, publishedCourse(['code' => 'CODE', 'title' => 'Code of conduct', 'validity_months' => 12], false)));
    LearningProvider::create(['code' => 'INT', 'name' => 'Internal L&D', 'provider_type' => 'internal']);
    LearningProgram::create(['code' => 'NEWMGR', 'name' => 'New manager program']);
    app(SkillScales::class)->defaultVersion();
    $skill = Skill::create(['name' => 'Negotiation', 'code' => 'NEGOTIATION']);
    $assessment = app(SkillAssessments::class)->draft($this->employee, $skill->id, 'manager', ['level' => 2, 'target_level' => 4], $this->manager->user);
    app(SkillAssessments::class)->finalize($assessment, $this->manager->user);
    app(DevelopmentPlans::class)->create($this->employee, 'Commercial skills', [], $this->manager->user);
    actAsTenant(null);
});

it('renders the learning, skills and development administration screens', function () {
    $this->get(LearningDashboard::getUrl())->assertOk()->assertSee('Mandatory training');
    $this->get(CourseResource::getUrl('index'))->assertOk()->assertSee('Workplace safety');
    $this->get(LearningEnrolmentResource::getUrl('index'))->assertOk();
    $this->get(LearningAssignmentResource::getUrl('index'))->assertOk();
    $this->get(LearningCertificateResource::getUrl('index'))->assertOk();
    $this->get(LearningProviderResource::getUrl('index'))->assertOk()->assertSee('Internal L&amp;D', false);
    $this->get(LearningInstructorResource::getUrl('index'))->assertOk();
    $this->get(LearningProgramResource::getUrl('index'))->assertOk()->assertSee('New manager program');
    $this->get(SkillScaleResource::getUrl('index'))->assertOk()->assertSee('Proficiency');
    $this->get(SkillAssessmentResource::getUrl('index'))->assertOk()->assertSee('Negotiation');
    $this->get(DevelopmentPlanResource::getUrl('index'))->assertOk()->assertSee('Commercial skills');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk();
});

it('gives employees My learning and managers Team learning, and keeps the L&D dashboard from both', function () {
    $this->actingAs($this->employee->user);
    $this->get(MyLearning::getUrl())->assertOk()->assertSee('Workplace safety')->assertSee('Negotiation')->assertSee('Commercial skills');
    $this->get(TeamLearning::getUrl())->assertForbidden();
    $this->get(LearningDashboard::getUrl())->assertForbidden();

    $this->actingAs($this->manager->user);
    $this->get(TeamLearning::getUrl())->assertOk()->assertSee($this->employee->employee_code)->assertSee('Negotiation');
    $this->get(LearningDashboard::getUrl())->assertForbidden();
});

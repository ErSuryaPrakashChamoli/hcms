<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Services\Assets;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Services\Learning;
use App\Filament\Resources\AssetCategories\AssetCategoryResource;
use App\Filament\Resources\AssetModels\AssetModelResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LearningAssignments\LearningAssignmentResource;
use App\Filament\Resources\LearningCertificates\LearningCertificateResource;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Resources\LearningEnrolments\Pages\ViewLearningEnrolment;
use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Resources\TrainingSessions\TrainingSessionResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Learning/LearningTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->employee = activeEmployee(null, ['learning.learn', 'asset.own', 'task.view']);
    $this->course = publishedCourse();
    $this->enrolment = app(Learning::class)->enrol($this->employee, $this->course, now()->addDays(30));
    $this->laptop = app(Assets::class)->receive(['asset_category_id' => AssetCategory::query()->where('code', 'LAPTOP')->value('id'), 'asset_tag' => 'LT-001', 'name' => 'Dell Latitude', 'serial_number' => 'SN1']);
    actAsTenant(null);
});

it('renders the learning and asset pages', function () {
    $this->get(CourseResource::getUrl('index'))->assertOk()->assertSee('POSH awareness');
    $this->get(CourseResource::getUrl('edit', ['record' => $this->course]))->assertOk();
    $this->get(LearningPathResource::getUrl('index'))->assertOk();
    $this->get(LearningAssignmentResource::getUrl('index'))->assertOk();
    $this->get(LearningEnrolmentResource::getUrl('index'))->assertOk()->assertSee('POSH awareness');
    $this->get(LearningEnrolmentResource::getUrl('view', ['record' => $this->enrolment]))->assertOk()->assertSee('Policy');
    $this->get(TrainingSessionResource::getUrl('index'))->assertOk();
    $this->get(LearningCertificateResource::getUrl('index'))->assertOk();
    $this->get(AssetResource::getUrl('index'))->assertOk()->assertSee('LT-001');
    $this->get(AssetResource::getUrl('view', ['record' => $this->laptop]))->assertOk()->assertSee('Assign');
    $this->get(AssetResource::getUrl('create'))->assertOk();
    $this->get(AssetCategoryResource::getUrl('index'))->assertOk()->assertSee('Laptop');
    $this->get(AssetModelResource::getUrl('index'))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk();
});

it('lets the learner complete modules and pass the quiz, and assigns an asset from the asset page', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);
    [$m1, $m2] = $this->course->modules;

    Livewire::test(ViewLearningEnrolment::class, ['record' => $this->enrolment->id])->callAction('completeModule', data: ['module_id' => $m1->id])->assertNotified('Module completed');
    Livewire::test(ViewLearningEnrolment::class, ['record' => $this->enrolment->id])->callAction('completeModule', data: ['module_id' => $m2->id])->assertNotified('Module completed');
    Livewire::test(ViewLearningEnrolment::class, ['record' => $this->enrolment->id])->callAction('takeAssessment', data: ['answers' => [0 => '1', 1 => '0']])->assertNotified('Passed — score 100%');
    expect(LearningEnrolment::query()->find($this->enrolment->id)->status)->toBe('completed');

    $this->actingAs($this->admin);
    Livewire::test(ViewAsset::class, ['record' => $this->laptop->id])
        ->callAction('assign', data: ['employee_id' => $this->employee->id, 'assigned_on' => '2026-09-21', 'condition' => 'new'])
        ->assertNotified('Asset assigned');
    expect(Asset::query()->find($this->laptop->id)->custodian_id)->toBe($this->employee->id);

    // The employee sees only their own asset and cannot reach configuration.
    actAsTenant(null);
    $this->actingAs($this->employee->user);
    $this->get(AssetResource::getUrl('index'))->assertOk()->assertSee('My assets')->assertSee('LT-001');
    $this->get(AssetCategoryResource::getUrl('index'))->assertForbidden();
    $this->get(LearningEnrolmentResource::getUrl('index'))->assertOk()->assertSee('My learning');
    $this->get(LearningCertificateResource::getUrl('index'))->assertOk()->assertSee('CERT-POSH');
});

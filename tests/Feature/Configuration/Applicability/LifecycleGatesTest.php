<?php

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Services\Learning;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Services\Recognitions;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;

require_once __DIR__.'/../../ServiceDesk/ServiceDeskTestHelpers.php';
require_once __DIR__.'/../../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../../Learning/LearningTestHelpers.php';

/*
| Lifecycle configuration limited to some companies (Stage 5): a letter template is never used for another company's
| people, and a document type is required only of the companies that use it.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->hire = fn (Company $company) => app(HireEmployeeAction::class)->handle(['first_name' => fake()->firstName(), 'last_name' => 'Gate'], ['joining_date' => '2026-11-23'], ['company_id' => $company->id]);
});

it('never issues a letter from a template limited to another company', function () {
    $template = LetterTemplate::create(['name' => 'Markedge offer', 'code' => 'MK_OFFER', 'type' => 'offer', 'subject' => 'Offer', 'requires_approval' => false, 'body' => '<p>Welcome {{ employee.first_name }}</p>']);
    app(EntityApplicability::class)->apply($template, ApplicabilitySelection::single($this->mk->id), $this->admin);

    expect(fn () => app(Letters::class)->generate($template, ($this->hire)($this->fe), [], $this->admin))
        ->toThrow(RuntimeException::class, 'The letter template Markedge offer is not used by this employee\'s company.');
    expect(app(Letters::class)->generate($template, ($this->hire)($this->mk), [], $this->admin)->id)->not->toBeNull();
});

it('requires a document type only of the companies that use it', function () {
    $type = DocumentType::query()->create(['name' => 'Markedge NDA', 'code' => 'MK_NDA', 'category' => 'employment', 'mandatory_for_employee' => true]);
    app(EntityApplicability::class)->apply($type, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $rules = app(DocumentRules::class);

    expect($rules->missing(($this->hire)($this->mk))->pluck('code')->all())->toContain('MK_NDA')
        ->and($rules->missing(($this->hire)($this->fe))->pluck('code')->all())->not->toContain('MK_NDA');
});

it('offers a catalogue service limited to some companies only to their people', function () {
    $service = sdApprovedService('MK_LAPTOP');
    app(EntityApplicability::class)->apply($service, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $catalogue = app(ServiceCatalogue::class);
    $version = $catalogue->versionOn($service);

    expect($catalogue->eligible($version, ($this->hire)($this->mk)))->toBeTrue()
        ->and($catalogue->eligible($version, ($this->hire)($this->fe)))->toBeFalse();
});

it('keeps grievance categories, badges and asset types limited to some companies inside them (engagement, assets)', function () {
    $mk = forceLifecycle(($this->hire)($this->mk), LifecycleState::Active);
    $fe = forceLifecycle(($this->hire)($this->fe), LifecycleState::Active);
    $applicability = app(EntityApplicability::class);

    $category = GrievanceCategory::query()->create(['name' => 'Markedge conduct', 'code' => 'MK_CONDUCT']);
    $applicability->apply($category, ApplicabilitySelection::single($this->mk->id), $this->admin);
    expect(fn () => app(Grievances::class)->raise($category, $fe, 'Subject', 'Details'))->toThrow(RuntimeException::class, 'is not used by this employee\'s company');
    expect(app(Grievances::class)->raise($category, $mk, 'Subject', 'Details')->id)->not->toBeNull();

    $badge = Badge::query()->create(['code' => 'MK_STAR', 'name' => 'Markedge star', 'status' => 'active']);
    $applicability->apply($badge, ApplicabilitySelection::single($this->mk->id), $this->admin);
    expect(app(Recognitions::class)->refusalForReceiver($badge, $fe))->toContain('is not used by')
        ->and(app(Recognitions::class)->refusalForReceiver($badge, $mk))->toBeNull();

    $laptop = AssetCategory::query()->create(['name' => 'Markedge laptop', 'code' => 'MK_LAPTOP', 'status' => 'active']);
    $applicability->apply($laptop, ApplicabilitySelection::single($this->mk->id), $this->admin);
    expect(fn () => app(AssetRequests::class)->request($fe, ['kind' => 'new', 'asset_category_id' => $laptop->id], $this->admin))
        ->toThrow(RuntimeException::class, 'That asset type is not used by this employee\'s company.');
    expect(app(AssetRequests::class)->request($mk, ['kind' => 'new', 'asset_category_id' => $laptop->id], $this->admin)->asset_category_id)->toBe($laptop->id);
});

it('appraises and assigns learning only inside the companies a scale or course applies to (performance, learning)', function () {
    $mk = forceLifecycle(($this->hire)($this->mk), LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $fe = forceLifecycle(($this->hire)($this->fe), LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $applicability = app(EntityApplicability::class);

    $scale = RatingScale::default();
    $applicability->apply($scale, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $cycle = draftCycle(['rating_scale_id' => $scale->id]);
    expect(app(Appraisals::class)->ineligibility($mk, $cycle))->toBeNull()
        ->and(app(Appraisals::class)->ineligibility($fe, $cycle))->not->toBeNull();

    $course = publishedCourse(['code' => 'MK_POSH'], withQuiz: false);
    $applicability->apply($course, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $assignment = LearningAssignment::create(['name' => 'Everyone', 'course_id' => $course->id, 'conditions' => [], 'due_days' => 14]);
    app(Learning::class)->applyAssignment($assignment, $this->admin);
    expect(LearningEnrolment::query()->where('course_id', $course->id)->pluck('employee_id')->all())->toBe([$mk->id]);
});

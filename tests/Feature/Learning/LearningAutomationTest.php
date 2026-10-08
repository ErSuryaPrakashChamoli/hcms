<?php

use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Learning\Jobs\GenerateCertificateDocument;
use App\Domain\Learning\Jobs\SendLearningReminders;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningReminderLog;
use App\Domain\Learning\Services\Certificates;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\LearningReminders;
use Illuminate\Support\Facades\Queue;
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
    $this->manager = activeEmployee(null, ['learning.learn', 'learning.assign', 'development.team']);
    $this->employee = activeEmployee($this->manager, ['learning.learn', 'development.own']);
    $this->learning = app(Learning::class);
});

it('reminds once for due learning, throttles mandatory-overdue reminders and tells the manager', function () {
    $course = publishedCourse(['code' => 'POSHX', 'title' => 'POSH'], false);
    $soon = $this->learning->enrol($this->employee, $course, now()->addDays(2));
    $late = $this->learning->enrol($this->employee, publishedCourse(['code' => 'FIRE2', 'title' => 'Fire safety'], false), now()->subDays(3), null, null, null, true);
    $this->learning->tick();
    expect($late->refresh()->status)->toBe('overdue');

    $first = app(LearningReminders::class)->tick();
    $second = app(LearningReminders::class)->tick();
    expect($first)->toMatchArray(['due' => 1, 'overdue_mandatory' => 1])->and($second)->toMatchArray(['due' => 0, 'overdue_mandatory' => 0])
        ->and($this->manager->user->notifications()->where('data->title', 'like', '%overdue on mandatory learning%')->count())->toBe(1);

    $this->travelTo('2026-09-29 09:00:00'); // a week later the overdue reminder may go again; the due reminder never repeats
    expect(app(LearningReminders::class)->tick())->toMatchArray(['due' => 0, 'overdue_mandatory' => 1])
        ->and(LearningReminderLog::query()->count())->toBe(3);
});

it('reminds plan owners of development milestones and runs as a tenant-bound queued job', function () {
    $plans = app(DevelopmentPlans::class);
    $plan = $plans->create($this->employee, 'Growth', ['owner_employee_id' => $this->manager->id], $this->manager->user);
    $plans->addItem($plan, 'milestone', 'Present to the leadership team', ['due_on' => now()->addDays(5)->toDateString()], $this->manager->user);
    $plans->transition($plan, 'active', null, $this->manager->user);

    expect(app(LearningReminders::class)->tick()['milestones'])->toBe(1)
        ->and($this->employee->user->notifications()->where('data->title', 'like', 'Development milestone due%')->exists())->toBeTrue();

    Queue::fake();
    $this->artisan('peopleos:learning:send-reminders', ['--queue' => true])->assertSuccessful();
    Queue::assertPushed(SendLearningReminders::class, fn ($job) => $job->tenantId() === $this->tenant->id);
});

it('generates a private certificate document once per certificate through the queued job', function () {
    Storage::fake('local');
    config(['peopleos.learning.generate_certificate_documents' => true]);
    $enrolment = $this->learning->enrol($this->employee, publishedCourse(['code' => 'FIRSTAID', 'title' => 'First aid', 'validity_months' => 24], false));
    $this->learning->complete($enrolment); // sync queue: the job runs after commit

    $certificate = LearningCertificate::query()->where('learning_enrolment_id', $enrolment->id)->sole();
    expect($certificate->document_path)->not->toBeNull()->and($certificate->document_name)->toEndWith('.html');
    Storage::disk('local')->assertExists($certificate->document_path);
    expect(Storage::disk('local')->get($certificate->document_path))->toContain('First aid')->toContain('version 1');

    (new GenerateCertificateDocument($certificate->id))->handle(app(Certificates::class)); // retry-safe
    expect($certificate->refresh()->document_sha256)->toBe(hash('sha256', Storage::disk('local')->get($certificate->document_path)));
});

<?php

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Jobs\BuildEmployeeDocumentDownload;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Models\EmployeeDocumentDownload;
use App\Domain\Documents\Services\Documents;
use App\Domain\Documents\Services\EmployeeDocumentDownloads;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Employment\Services\ProfileCompletionMails;
use App\Domain\Employment\Services\ReturningEmployees;
use App\Domain\Employment\Services\UpcomingJoiners;
use App\Domain\Employment\Support\JoiningDayTaskSource;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\UpcomingJoiners as UpcomingJoinersPage;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Support\AuditReasonField;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| HROne parity WO-4 (matrix 23 §23.3.1 #1, 8–9, 11, 13–14, 16; §23.11 #18): the upcoming joiners register, "Change expected
| joining date", the not-joining outcome (D-WO1: end state not_joined), the joining-day task to HR, "Download all documents"
| and the profile-completion mail — and that nothing changes until someone uses them.
*/

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    Storage::fake((string) config('peopleos.documents.disk'));
    $this->company = Company::factory()->create();
    $this->manager = wo4Hire('Mohan', 'Lal', '2025-01-01');
});

function wo4Hire(string $first, string $last, string $joining, array $person = [], array $employment = [], ?Employee $manager = null, ?Company $company = null): Employee
{
    $company ??= Company::query()->orderBy('id')->first();

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => $last] + $person, ['joining_date' => $joining] + $employment, ['company_id' => $company->id], $manager?->id);
}

function wo4Template(): OnboardingTemplate
{
    $template = OnboardingTemplate::create(['name' => 'Joiners', 'key' => 'joiners', 'priority' => 1]);
    $template->items()->createMany([
        ['phase' => 'preboarding', 'type' => 'task', 'title' => 'Send the offer pack', 'owner_type' => 'user', 'owner_user_id' => test()->hr->id, 'sort_order' => 1],
        ['phase' => 'day_one', 'type' => 'task', 'title' => 'Issue the laptop', 'owner_type' => 'user', 'owner_user_id' => test()->hr->id, 'sort_order' => 2],
    ]);

    return $template;
}

function wo4Settings(array $values): void
{
    foreach ($values as $key => $value) {
        app(SettingsRepository::class)->set($key, $value);
    }
}

/** @return list<string> */
function wo4ZipNames(string $content): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'wo4zip');
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $names;
}

it('keeps every default: no new task, no mail, the engine and the generic state change refuse "not joined"', function () {
    $joiner = wo4Hire('Asha', 'Rao', '2026-10-25', ['personal_email' => 'asha@example.test'], [], $this->manager);

    // Hiring with a future date still starts pre-boarding exactly as before; nothing is mailed or listed.
    expect($joiner->lifecycle_state)->toBe(LifecycleState::Preboarding)
        ->and($joiner->joining_date->toDateString())->toBe('2026-10-25')
        ->and($joiner->expected_joining_date)->toBeNull()
        ->and(NotificationDelivery::query()->where('event', ProfileCompletionMails::EVENT)->exists())->toBeFalse()
        ->and(config('peopleos.notifications.events'))->not->toContain(ProfileCompletionMails::EVENT)
        ->and(app(SettingsRepository::class)->get('employee.joining.day_task'))->toBeFalse()
        ->and(app(SettingsRepository::class)->get('employee.joining.profile_completion_mail'))->toBeFalse();
    $this->travelTo('2026-10-25 09:00:00');
    expect(app(JoiningDayTaskSource::class)->tasksFor($this->hr, null))->toBeEmpty()
        ->and(fn () => app(ProfileCompletionMails::class)->send($joiner, $this->hr, ['personal']))->toThrow(RuntimeException::class, 'switched off');

    // not_joined is an end state reached only through "Mark as not joining".
    expect(LifecycleState::NotJoined->allowedNext())->toBe([])
        ->and(LifecycleState::NotJoined->isEmployed())->toBeFalse()->and(LifecycleState::NotJoined->isPayrollEligible())->toBeFalse()
        ->and(LifecycleState::Preboarding->canTransitionTo(LifecycleState::NotJoined))->toBeTrue()
        ->and(LifecycleState::Joined->canTransitionTo(LifecycleState::NotJoined))->toBeFalse()
        ->and(LifecycleState::Onboarding->canTransitionTo(LifecycleState::NotJoined))->toBeFalse()
        ->and(fn () => app(LifecycleEngine::class)->transition($joiner, LifecycleState::NotJoined, now(), 'API'))
        ->toThrow(InvalidLifecycleTransitionException::class, 'Mark as not joining');
    // "Change lifecycle state" on the 360 never reaches it either.
    Livewire::test(ViewEmployee::class, ['record' => $joiner->getRouteKey()])
        ->callAction('lifecycle', ['to_state' => 'not_joined', 'effective_date' => '2026-10-25', AuditReasonField::NAME => 'Declined']);
    expect($joiner->refresh()->lifecycle_state)->toBe(LifecycleState::Preboarding)
        ->and(EmployeeLifecycleTransition::query()->where('to_state', 'not_joined')->exists())->toBeFalse();
});

it('lists upcoming joiners with their expected date, days to join, onboarding, background check and documents, inside the scope', function () {
    wo4Template();
    $type = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    DocumentType::query()->whereKeyNot($type->id)->update(['mandatory_for_onboarding' => false]);
    $asha = wo4Hire('Asha', 'Rao', '2026-10-25', [], [], $this->manager);
    $other = Company::factory()->create();
    $ravi = wo4Hire('Ravi', 'Kumar', '2026-11-10', [], [], null, $other);
    app(Documents::class)->store($ravi, UploadedFile::fake()->createWithContent('pan.pdf', '%PDF-1.4 pan'), $type);

    $page = Livewire::test(UpcomingJoinersPage::class)
        ->assertCanSeeTableRecords([$asha, $ravi])->assertCanNotSeeTableRecords([$this->manager])
        ->assertTableColumnFormattedStateSet('expected_joining', '25 Oct 2026', $asha)
        ->assertTableColumnStateSet('days_to_join', 'In 5 days', $asha)
        ->assertTableColumnStateSet('days_to_join', 'In 21 days', $ravi)
        ->assertTableColumnStateSet('onboarding_progress', '0% done', $asha)
        ->assertTableColumnStateSet('bgv_status', null, $asha)
        ->assertTableColumnStateSet('documents_pending', '1 missing', $asha)
        ->assertTableColumnStateSet('documents_pending', '1 to verify', $ravi);
    expect(collect($page->instance()->getTableRecords()->items())->pluck('id')->all())->toBe([$asha->id, $ravi->id]); // by expected joining date

    // The organisation filter works on the planned position (no current position before day one).
    Livewire::test(UpcomingJoinersPage::class)->filterTable('organisation', ['company_id' => [$other->id]])
        ->assertCanSeeTableRecords([$ravi])->assertCanNotSeeTableRecords([$asha]);

    // The desk links it; the audited CSV holds what the register shows.
    $this->get(WorkforceDesk::getUrl())->assertOk()->assertSee('Upcoming joiners')->assertSee(UpcomingJoinersPage::getUrl(), false);
    $this->get(UpcomingJoinersPage::getUrl())->assertOk()->assertSee('Upcoming joiners');
    $csv = Livewire::test(UpcomingJoinersPage::class)->resetTableFilters()->instance()->exportCsv(); // filters are kept in the session
    ob_start();
    $csv->sendContent();
    $content = (string) ob_get_clean();
    expect($content)->toContain('Expected joining date')->toContain($asha->employee_code)->toContain('2026-10-25')->not->toContain($this->manager->employee_code)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Export->value)->where('entity_label', 'Upcoming joiners')->where('metadata->rows', 2)->exists())->toBeTrue();

    // Holders of employee.view alone cannot open it.
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    expect(UpcomingJoinersPage::canAccess())->toBeFalse();
    $this->get(UpcomingJoinersPage::getUrl())->assertForbidden();

    // Access scope: an HR user limited to the other company reaches only that company's joiners (once their position is in force).
    $tara = wo4Hire('Tara', 'Sen', '2026-10-22', [], [], null, $other);
    $scoped = tenantUser($this->tenant, ['employee.view', 'employee.lifecycle', 'document.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$other->id]], 'Other company');
    $this->travelTo('2026-10-23 10:00:00');
    $this->actingAs($scoped->fresh());
    Livewire::test(UpcomingJoinersPage::class)->assertCanSeeTableRecords([$tara])->assertCanNotSeeTableRecords([$asha, $this->manager])
        ->assertTableColumnStateSet('days_to_join', '1 day(s) overdue', $tara);
});

it('changes the expected joining date with a reason, moving onboarding, probation, position and manager; refused once joined', function () {
    wo4Template();
    $asha = wo4Hire('Asha', 'Rao', '2026-10-25', [], [], $this->manager);
    $plan = OnboardingPlan::query()->where('employee_id', $asha->id)->sole();
    [$pack, $laptop] = $plan->tasks()->orderBy('sort_order')->get()->all();
    expect($pack->due_on->toDateString())->toBe('2026-10-22')->and($laptop->due_on->toDateString())->toBe('2026-10-25');
    app(Onboarding::class)->complete($pack, 'Sent', null, $this->hr);
    $probation = $asha->refresh()->probation_end_date->toDateString();

    $service = app(UpcomingJoiners::class);
    expect(fn () => $service->changeJoiningDate($asha, '2026-10-19', 'Earlier', $this->hr))->toThrow(RuntimeException::class, 'earlier than today')
        ->and(fn () => $service->changeJoiningDate($asha, '2026-11-05', '', $this->hr))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $service->changeJoiningDate($asha, '2026-11-05', 'Notice period', tenantUser($this->tenant, ['employee.view'])))->toThrow(RuntimeException::class, 'lifecycle right');

    Livewire::test(UpcomingJoinersPage::class)->callTableAction('changeJoiningDate', $asha, ['joining_date' => '2026-11-05', 'reason' => 'Serving notice at the current employer'])->assertHasNoTableActionErrors();

    $asha->refresh();
    expect($asha->joining_date->toDateString())->toBe('2026-11-05')->and($asha->expected_joining_date->toDateString())->toBe('2026-11-05')
        ->and($probation)->toBe('2027-04-25')->and($asha->probation_end_date->toDateString())->toBe('2027-05-05')
        ->and($plan->refresh()->anchor_date->toDateString())->toBe('2026-11-05')
        ->and($pack->refresh()->due_on->toDateString())->toBe('2026-10-22')     // already done: kept
        ->and($laptop->refresh()->due_on->toDateString())->toBe('2026-11-05')   // not started: moved
        ->and(EmployeePosition::query()->where('employee_id', $asha->id)->sole()->effective_from->toDateString())->toBe('2026-11-05')
        ->and(ReportingRelationship::query()->where('employee_id', $asha->id)->sole()->effective_from->toDateString())->toBe('2026-11-05')
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $asha->id)->where('title', 'Expected joining date changed')->value('description'))->toContain('25 Oct 2026 → 05 Nov 2026')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', $plan->getMorphClass())->where('entity_id', (string) $plan->id)->where('metadata->rescheduled_items', 1)->exists())->toBeTrue();

    // Once joined, the date is history: refused (and the actions are gone from the 360).
    $joined = wo4Hire('Kiran', 'Das', '2026-10-01');
    expect(fn () => $service->changeJoiningDate($joined, '2026-11-01', 'Late', $this->hr))->toThrow(RuntimeException::class, 'has already joined');
    Livewire::test(ViewEmployee::class, ['record' => $joined->getRouteKey()])->assertActionHidden('changeJoiningDate')->assertActionHidden('markNotJoining');
    Livewire::test(ViewEmployee::class, ['record' => $asha->getRouteKey()])->assertActionVisible('changeJoiningDate');
});

it('marks a joiner as not joining: reason, onboarding cancelled, nothing deleted, never employed, still found by the rehire check', function () {
    wo4Template();
    $user = User::factory()->forTenant($this->tenant)->create(['status' => UserStatus::Active]);
    $asha = wo4Hire('Asha', 'Rao', '2026-10-25', ['personal_email' => 'asha.rao@example.test'], ['user_id' => $user->id], $this->manager);
    app(Documents::class)->store($asha, UploadedFile::fake()->createWithContent('pan.pdf', '%PDF-1.4 pan'), DocumentType::query()->where('code', 'PAN')->first());
    $plan = OnboardingPlan::query()->where('employee_id', $asha->id)->sole();
    $counts = fn () => [Employee::query()->count(), OnboardingTask::query()->count(), EmployeeDocument::query()->count(), EmployeePosition::query()->count(), ReportingRelationship::query()->count()];
    $before = $counts();
    $headcount = app(WorkforceMetrics::class)->headcount('2026-10-31');

    $service = app(UpcomingJoiners::class);
    expect(fn () => $service->markNotJoining($asha, 'unknown', null, $this->hr))->toThrow(RuntimeException::class, 'Choose why')
        ->and(fn () => $service->markNotJoining($asha, 'other', '', $this->hr))->toThrow(RuntimeException::class, 'note')
        ->and(fn () => $service->markNotJoining($this->manager, 'personal', null, $this->hr))->toThrow(RuntimeException::class, 'has already joined')
        ->and(array_keys($service->reasons()))->toContain('accepted_other_offer', 'no_show', 'other');

    Livewire::test(UpcomingJoinersPage::class)->callTableAction('markNotJoining', $asha, ['reason' => 'accepted_other_offer', 'note' => 'Joined a competitor'])->assertHasNoTableActionErrors();

    $asha->refresh();
    $transition = EmployeeLifecycleTransition::query()->where('employee_id', $asha->id)->where('to_state', 'not_joined')->sole();
    expect($asha->lifecycle_state)->toBe(LifecycleState::NotJoined)
        ->and($transition->metadata)->toMatchArray(['not_joining_reason' => 'accepted_other_offer', 'note' => 'Joined a competitor', 'expected_joining_date' => '2026-10-25'])
        ->and($transition->reason)->toBe('Not joining: Accepted another offer — Joined a competitor')
        ->and($service->notJoining($asha))->toMatchArray(['code' => 'accepted_other_offer', 'label' => 'Accepted another offer'])
        ->and($plan->refresh()->status)->toBe('cancelled')
        ->and($plan->tasks()->pluck('status')->unique()->all())->toBe(['skipped'])
        ->and($counts())->toBe($before)                                           // nothing deleted
        ->and($asha->joining_date)->toBeNull()->and($asha->expected_joining_date->toDateString())->toBe('2026-10-25')
        ->and(EmployeePosition::query()->where('employee_id', $asha->id)->effectiveOn('2026-10-25')->exists())->toBeFalse()
        ->and(ReportingRelationship::query()->where('employee_id', $asha->id)->effectiveOn('2026-11-30')->exists())->toBeFalse()
        ->and($this->manager->directReports()->effectiveOn('2026-11-30')->pluck('employee_id')->all())->not->toContain($asha->id)
        ->and($user->refresh()->status)->toBe(UserStatus::Suspended)
        ->and(Employee::query()->employed()->whereKey($asha->id)->exists())->toBeFalse();

    // Never employed: not in the headcount, nor any payroll population (also once the planned date has passed).
    $this->travelTo('2026-11-02 10:00:00');
    expect(app(WorkforceMetrics::class)->headcount('2026-10-31'))->toBe($headcount)
        ->and(app(WorkforceMetrics::class)->headcount('2026-11-02'))->toBe($headcount)
        ->and(app(PayrollRuns::class)->populationIn($this->company->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->pluck('employees.id')->all())->toContain($this->manager->id)->not->toContain($asha->id)
        ->and(app(PayrollRuns::class)->populationIn($this->company->id, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))->pluck('employees.id')->all())->not->toContain($asha->id);

    // The register keeps them under "Not joining"; the rehire check still finds them, as never employed here.
    Livewire::test(UpcomingJoinersPage::class)->assertCanNotSeeTableRecords([$asha])
        ->filterTable('joining_status', 'not_joining')->assertCanSeeTableRecords([$asha])->assertTableColumnStateSet('days_to_join', 'Not joining', $asha);
    $match = collect(app(ReturningEmployees::class)->matches($this->hr, ['first_name' => 'Asha', 'last_name' => 'Rao', 'personal_email' => 'asha.rao@example.test']))->firstWhere('employee_id', $asha->id);
    expect($match)->not->toBeNull()->and($match['status'])->toBe('Not Joined')->and($match['currently_employed'])->toBeFalse()->and($match['joining_date'])->toBeNull();

    // An end state: no way out, and the joiner actions are gone.
    expect(fn () => $service->changeJoiningDate($asha, '2026-12-01', 'Try again', $this->hr))->toThrow(RuntimeException::class, 'did not join');
    Livewire::test(ViewEmployee::class, ['record' => $asha->getRouteKey()])->assertActionHidden('markNotJoining')->assertActionHidden('markJoined');
});

it('reports not-joined people as never employed in headcount even when marked after the planned date', function () {
    $asha = wo4Hire('Asha', 'Rao', '2026-10-22');
    $base = app(WorkforceMetrics::class)->headcount('2026-10-28');
    $this->travelTo('2026-10-28 10:00:00');
    app(UpcomingJoiners::class)->markNotJoining($asha, 'no_show', null, $this->hr);

    expect(app(WorkforceMetrics::class)->headcount('2026-10-28'))->toBe($base)
        ->and(app(WorkforceMetrics::class)->headcounts(['2026-10-25', '2026-10-28']))->toBe(['2026-10-25' => $base, '2026-10-28' => $base])
        ->and(EmployeePosition::query()->where('employee_id', $asha->id)->effectiveOn('2026-10-28')->exists())->toBeFalse();
});

it('gives HR a joining-day task, inside their scope, when the organisation switches it on', function () {
    $asha = wo4Hire('Asha', 'Rao', '2026-10-25');
    $other = Company::factory()->create();
    $ravi = wo4Hire('Ravi', 'Kumar', '2026-10-25', [], [], null, $other);
    wo4Settings(['employee.joining.day_task' => true]);

    expect(app(JoiningDayTaskSource::class)->tasksFor($this->hr, null))->toBeEmpty(); // not their day yet
    $this->travelTo('2026-10-25 08:00:00');
    $tasks = app(ExperienceTasks::class)->for($this->hr, null)->where('kind', 'joining_day');
    expect($tasks->pluck('title')->all())->toContain('Asha Rao joins today', 'Ravi Kumar joins today')
        ->and($tasks->first()->domain)->toBe('employment');

    $scoped = tenantUser($this->tenant, ['employee.view', 'employee.lifecycle']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$other->id]], 'Other company');
    expect(app(JoiningDayTaskSource::class)->tasksFor($scoped->fresh(), null)->pluck('title')->all())->toBe(['Ravi Kumar joins today'])
        ->and(app(JoiningDayTaskSource::class)->tasksFor(tenantUser($this->tenant, ['employee.view', 'task.view']), null))->toBeEmpty();

    // Still listed while overdue; gone once marked as joined.
    $this->travelTo('2026-10-27 08:00:00');
    expect(app(JoiningDayTaskSource::class)->tasksFor($this->hr, null)->pluck('title')->all())->toContain('Asha Rao was expected to join on 25 Oct 2026');
    $engine = app(LifecycleEngine::class);
    $engine->transition($asha, LifecycleState::Joined, '2026-10-25', 'Joined');
    expect(app(JoiningDayTaskSource::class)->tasksFor($this->hr, null)->pluck('title')->all())->not->toContain('Asha Rao was expected to join on 25 Oct 2026');
});

it('downloads all documents of a joiner as a zip for the person who asked — only documents they may open, audited, expiring', function () {
    $asha = wo4Hire('Asha', 'Rao', '2026-10-25');
    $documents = app(Documents::class);
    $pan = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    $documents->store($asha, UploadedFile::fake()->createWithContent('pan-old.pdf', '%PDF-1.4 old'), $pan);
    $v2 = $documents->store($asha, UploadedFile::fake()->createWithContent('pan.pdf', '%PDF-1.4 new'), $pan);      // v2: v1 archived
    $documents->store($asha, UploadedFile::fake()->createWithContent('offer.pdf', '%PDF-1.4 offer'), null, 'Offer letter');
    $reader = tenantUser($this->tenant, ['employee.view', 'document.view', 'onboarding.view']);

    expect(fn () => app(EmployeeDocumentDownloads::class)->request(tenantUser($this->tenant, ['employee.view']), $asha))->toThrow(RuntimeException::class, 'cannot download');
    $scoped = tenantUser($this->tenant, ['employee.view', 'document.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [Company::factory()->create()->id]], 'Elsewhere');
    expect(fn () => app(EmployeeDocumentDownloads::class)->request($scoped->fresh(), $asha))->toThrow(RuntimeException::class, 'cannot download');
    // The job checks again as the person who asked: someone who may not open the employee gets nothing.
    $forged = EmployeeDocumentDownload::create(['employee_id' => $asha->id, 'requested_by' => $scoped->id, 'total' => 2]);
    expect(app(EmployeeDocumentDownloads::class)->build($forged)->status)->toBe('failed')->and($forged->included)->toBe(0)->and($forged->file_path)->toBeNull();
    $forged->delete();

    $this->actingAs($reader);
    Livewire::test(UpcomingJoinersPage::class)->callTableAction('downloadAllDocuments', $asha)->assertHasNoTableActionErrors();
    $download = EmployeeDocumentDownload::query()->where('employee_id', $asha->id)->sole();
    expect($download->status)->toBe('ready')->and($download->total)->toBe(2)->and($download->included)->toBe(2)->and($download->requested_by)->toBe($reader->id)
        ->and($download->file_path)->toStartWith("tenants/{$this->tenant->id}/employees/downloads/")
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'DOWNLOAD')->where('module', 'documents')->where('metadata->purpose', EmployeeDocumentDownloads::PURPOSE)->count())->toBe(2);

    $file = app(EmployeeDocumentDownloads::class)->fetch($download, $reader);
    expect(wo4ZipNames($file['content']))->toEqualCanonicalizing([$v2->title.' (v2).pdf', 'Offer letter.pdf'])
        ->and(fn () => app(EmployeeDocumentDownloads::class)->fetch($download, $this->hr))->toThrow(RuntimeException::class, 'Only the person');
    $this->get(EmployeeDocumentDownloads::url($download))->assertOk();
    $this->get(route('employees.documents.zip', ['download' => $download->id]))->assertForbidden(); // unsigned
    $this->actingAs($this->hr);
    $this->get(EmployeeDocumentDownloads::url($download))->assertForbidden();                        // not the requester

    // The job carries its tenant, tries, timeout and backoff.
    $job = new BuildEmployeeDocumentDownload($download->id);
    expect([$job->tries, $job->timeout, $job->backoff, $job->tenantId])->toBe([3, 900, [60, 300], $this->tenant->id])
        ->and($job->middleware())->not->toBeEmpty()
        ->and(DataInventory::TABLES['documents'])->toContain('employee_document_downloads');

    // Expired after the configured hours: the daily run removes the zip, the row stays.
    $this->travel(25)->hours();
    $this->actingAs($reader);
    $this->get(EmployeeDocumentDownloads::url($download))->assertForbidden();
    Artisan::call('peopleos:onboarding:start-due', ['--tenant' => $this->tenant->id]);
    expect($download->refresh()->status)->toBe('expired')->and($download->file_path)->toBeNull()->and(EmployeeDocumentDownload::query()->count())->toBe(1);
});

it('sends the profile-completion mail by hand only: what is missing, to the personal e-mail, with no link', function () {
    Mail::fake();
    $type = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    DocumentType::query()->whereKeyNot($type->id)->update(['mandatory_for_onboarding' => false]);
    $asha = wo4Hire('Asha', 'Rao', '2026-10-25', ['personal_email' => 'asha.rao@example.test'], ['work_email' => 'asha@acme.test'], $this->manager);

    // Off by default: not offered.
    Livewire::test(UpcomingJoinersPage::class)->assertTableActionHidden('profileCompletionMail', $asha);
    wo4Settings(['employee.joining.profile_completion_mail' => true]);
    // Switching it on sends nothing by itself: a new joiner gets no mail.
    wo4Hire('Ravi', 'Kumar', '2026-11-10', ['personal_email' => 'ravi@example.test']);
    expect(NotificationDelivery::query()->where('event', ProfileCompletionMails::EVENT)->exists())->toBeFalse();

    expect(array_keys(app(UpcomingJoiners::class)->missingProfileSections($asha)))->toContain('personal', 'address', 'bank');
    Livewire::test(UpcomingJoinersPage::class)->assertTableActionVisible('profileCompletionMail', $asha)
        ->callTableAction('profileCompletionMail', $asha, ['sections' => ['address', 'bank'], 'documents' => [$type->id], 'to' => ['personal'], 'note' => 'Bring originals on day one.'])
        ->assertHasNoTableActionErrors();

    $delivery = NotificationDelivery::query()->where('event', ProfileCompletionMails::EVENT)->sole();
    expect($delivery->recipient)->toBe('asha.rao@example.test')->and($delivery->channel)->toBe('email')
        ->and($delivery->body)->toContain('- Address')->toContain('- Bank details')->toContain('- '.$type->name.' (document)')->toContain('Bring originals on day one.')->toContain('25 Oct 2026')
        ->and($delivery->subject.$delivery->body)->not->toContain('http')->not->toContain('/admin')->not->toContain((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $audit = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::EmployeeEmailed->value)->where('entity_id', (string) $asha->id)->sole();
    expect($audit->metadata)->toMatchArray(['purpose' => 'profile_completion', 'sections' => ['address', 'bank'], 'documents' => [$type->name], 'to' => ['personal']])
        ->and(json_encode($audit->metadata))->not->toContain('Bring originals')->not->toContain('asha.rao@example.test');

    // Sent as plain text, without the layout whose header links to the application.
    $delivery->update(['status' => 'queued']);
    app(Notifier::class)->deliver($delivery->refresh());
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('asha.rao@example.test') && $mail->plainText && $mail->content()->markdown === null && $mail->content()->text === 'mail.notification-text');

    // The organisation's template is used; it cannot carry a link to PeopleOS ({{ app.url }} renders nothing; a typed one is refused).
    NotificationTemplate::create(['key' => ProfileCompletionMails::TEMPLATE_KEY, 'name' => 'Profile completion', 'subject' => 'Hello {{ employee.first_name }}', 'body' => "Missing:\n{{ joiner.missing }}\nSign in: {{ app.url }}", 'status' => 'active']);
    $count = app(ProfileCompletionMails::class)->send($asha, $this->hr, ['address'], [], ['personal', 'work']);
    $latest = NotificationDelivery::query()->where('event', ProfileCompletionMails::EVENT)->latest('id')->first();
    expect($count)->toBe(2)->and($latest->subject)->toBe('Hello Asha')->and($latest->body)->toBe("Missing:\n- Address\nSign in:");
    NotificationTemplate::query()->where('key', ProfileCompletionMails::TEMPLATE_KEY)->update(['body' => 'Sign in at '.config('app.url').'/admin']);
    expect(fn () => app(ProfileCompletionMails::class)->send($asha, $this->hr, ['address']))->toThrow(RuntimeException::class, 'cannot carry a link')
        ->and(fn () => app(ProfileCompletionMails::class)->send($asha, tenantUser($this->tenant, ['employee.view']), ['address']))->toThrow(RuntimeException::class, 'cannot write')
        ->and(fn () => app(ProfileCompletionMails::class)->send($this->manager, $this->hr, ['address']))->toThrow(RuntimeException::class, 'upcoming joiner')
        ->and(fn () => app(ProfileCompletionMails::class)->send($asha, $this->hr, []))->toThrow(RuntimeException::class, 'at least one');
});

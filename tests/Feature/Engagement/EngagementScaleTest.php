<?php

use App\Domain\Communication\Services\CommunicationDelivery;
use App\Domain\Communication\Services\Communications;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Services\AudienceQuery;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Domain\Engagement\Services\SurveyNotices;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/EngagementTestHelpers.php';

/*
 | Phase 13 §54: audience evaluation, the snapshot, submission, analytics, listings and delivery run a
 | bounded number of queries however large the population or the response set — filtering and
 | aggregation happen in SQL, never by loading employees or responses into PHP.
 */

function engagementQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** @return list<Employee> active employees with a login and a position in the department */
function engagementPopulation(int $count, Department $department): array
{
    $company = Company::query()->first() ?? Company::factory()->create();
    $tenant = app(TenantContext::class)->current();

    return LifecycleEngine::unguarded(fn () => collect(range(1, $count))->map(function () use ($company, $department, $tenant) {
        $user = User::factory()->forTenant($tenant)->create();
        $user->roles()->attach(Role::query()->where('slug', 'employee')->value('id'));
        $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
        EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'department_id' => $department->id, 'effective_from' => '2025-01-01', 'change_type' => 'hire']);

        return $employee;
    })->all());
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->dept = Department::factory()->create();
});

it('evaluates audiences and takes snapshots in SQL with a constant number of queries; invitations cost a bounded amount per person', function () {
    $criteria = ['department_ids' => [$this->dept->id], 'lifecycle_states' => ['active']];
    $audience = app(AudienceQuery::class);
    config(['queue.default' => 'null']); // measure the snapshot alone; invitations are measured below
    $open = function (string $code) use ($criteria) {
        $version = null;
        $queries = engagementQueries(function () use (&$version, $code, $criteria) {
            $version = openEngagementSurvey($this->actors, ['audience_criteria' => $criteria, 'breakdown_dimension' => 'department'], null, $code);
        });

        return [$version, $queries];
    };

    engagementPopulation(30, $this->dept);
    $audience->count($criteria, $this->actors['preparer']); // warm the scope cache
    $smallCount = engagementQueries(fn () => expect($audience->count($criteria, $this->actors['preparer']))->toBe(30));
    [$smallVersion, $smallOpen] = $open('SMALL');
    $smallInvite = engagementQueries(fn () => app(SurveyNotices::class)->invite($smallVersion));

    engagementPopulation(270, $this->dept);
    $largeCount = engagementQueries(fn () => expect($audience->count($criteria, $this->actors['preparer']))->toBe(300));
    [$largeVersion, $largeOpen] = $open('LARGE');
    $largeInvite = engagementQueries(fn () => app(SurveyNotices::class)->invite($largeVersion));

    fwrite(STDERR, "engagement scale audience count {$smallCount}/{$largeCount}, open {$smallOpen}/{$largeOpen}, invitations {$smallInvite}/{$largeInvite}\n");
    expect($largeCount)->toBe($smallCount)->and($smallCount)->toBe(1)
        ->and($largeOpen)->toBeLessThanOrEqual($smallOpen) // 10× the population, no more queries (the first run also warms caches)
        ->and(SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->where('survey_version_id', $largeVersion->id)->count())->toBe(300);
    // One notice per person through the Notifier (log claim, preference, delivery rows): a fixed cost per person.
    expect(($largeInvite - $smallInvite) / 270)->toBeLessThanOrEqual($smallInvite / 30)->toBeLessThan(10);
});

it('keeps submission, analytics, listings and the API constant as responses and communications grow', function () {
    $staff = engagementPopulation(120, $this->dept);
    $version = openEngagementSurvey($this->actors, ['audience_criteria' => ['department_ids' => [$this->dept->id]], 'anonymity_mode' => 'identified']);
    $responses = app(SurveyResponses::class);
    $submit = fn (Employee $e) => $responses->submit($version, $e->user, ['mood' => '4', 'tools' => ['wiki', 'chat'], 'comment' => 'ok']);
    $key = app(ApiKeys::class)->issue('Scale', ['engagement.read', 'communications.read']);
    $analyst = $this->actors['analyst'];
    $comms = app(Communications::class);
    $publish = function (int $n) use ($comms) {
        foreach (range(1, $n) as $i) {
            $a = $comms->create(['title' => "Notice {$i}", 'body' => 'x', 'audience_criteria' => ['employee_ids' => [Employee::query()->value('id')]]], $this->actors['preparer']);
            $comms->publish($comms->approve($comms->submit($a, $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
        }
    };

    $measure = function () use ($version, $analyst, $key, $staff, $submit) {
        static $next = 100;
        $respondent = $staff[$next++];
        $one = engagementQueries(fn () => expect($submit($respondent)['status'])->toBe('submitted'));
        $analytics = engagementQueries(fn () => app(EngagementAnalytics::class)->results($version, $analyst));
        $this->actingAs(tenantUser($this->tenant, ['*']));
        $surveyList = engagementQueries(fn () => $this->get(SurveyResource::getUrl('index'))->assertOk());
        $announcementList = engagementQueries(fn () => $this->get(AnnouncementResource::getUrl('index'))->assertOk());
        auth()->logout();
        actAsTenant(null);
        $api = engagementQueries(fn () => $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/communications?per_page=10')->assertOk());
        $apiSurveys = engagementQueries(fn () => $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/engagement/surveys')->assertOk());
        $this->flushHeaders();
        actAsTenant($this->tenant);

        return compact('one', 'analytics', 'surveyList', 'announcementList', 'api', 'apiSurveys');
    };

    foreach (array_slice($staff, 0, 10) as $e) {
        $submit($e);
    }
    $publish(12);
    $measure(); // warm caches
    $small = $measure();
    foreach (array_slice($staff, 10, 88) as $e) {
        $submit($e);
    }
    $publish(30);
    $large = $measure();

    fwrite(STDERR, 'engagement scale queries small '.json_encode($small).' / large '.json_encode($large)."\n");
    expect($large)->toBe($small);
});

it('delivers communication with a constant number of queries per recipient and bounded batches', function () {
    engagementPopulation(40, $this->dept);
    $comms = app(Communications::class);
    $draft = $comms->create(['title' => 'All hands', 'body' => 'x', 'audience_criteria' => ['department_ids' => [$this->dept->id]]], $this->actors['preparer']);
    $approved = $comms->approve($comms->submit($draft, $this->actors['preparer']), null, $this->actors['approver']);
    config(['queue.default' => 'null']); // snapshot now, deliver below in measured batches
    $published = $comms->publish($approved, $this->actors['preparer']);
    expect($published->recipients_count)->toBe(40);

    $delivery = app(CommunicationDelivery::class);
    $ten = engagementQueries(fn () => expect(array_sum($delivery->deliver($published, 10)))->toBe(10));
    $thirty = engagementQueries(fn () => expect(array_sum($delivery->deliver($published, 30)))->toBe(30));
    fwrite(STDERR, "communication delivery queries 10 recipients {$ten} / 30 recipients {$thirty}\n");
    expect(($thirty - 1) / 30)->toBe(($ten - 1) / 10)->and($delivery->pending($published))->toBe(0);
});

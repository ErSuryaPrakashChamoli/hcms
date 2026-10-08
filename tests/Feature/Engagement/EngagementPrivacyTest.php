<?php

use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Domain\Engagement\Services\GroupKeys;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Department;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/EngagementTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->analytics = app(EngagementAnalytics::class);
    $this->responses = app(SurveyResponses::class);
});

function answerAll(array $staff, $version, callable $answers): void
{
    foreach ($staff as $i => $employee) {
        app(SurveyResponses::class)->submit($version, $employee->user, $answers($i));
    }
}

it('computes complementary suppression so total minus visible never reveals a small group', function () {
    $a = app(EngagementAnalytics::class);
    expect($a->visible(['A' => 5, 'B' => 1], 6, 5))->toBe([])                       // 6 = 5 + 1 → both hidden
        ->and($a->visible(['A' => 5, 'B' => 5], 10, 5))->toBe(['A', 'B'])
        ->and($a->visible(['A' => 8, 'B' => 5, 'C' => 4], 17, 5))->toBe(['A'])       // C=4 alone would leak; B hidden too → remainder 9
        ->and($a->visible(['A' => 8, 'B' => 6, 'C' => 5, 'D' => 2], 21, 5))->toBe(['B', 'A'])
        ->and($a->visible(['A' => 7], 7, 5))->toBe(['A']);
});

it('merges small eligible groups so no group key narrows a response below k', function () {
    $keys = app(GroupKeys::class);
    $big = Department::factory()->create(['name' => 'Big']);
    $mid = Department::factory()->create(['name' => 'Mid']);
    $tiny = Department::factory()->create(['name' => 'Tiny']);
    $staff = [...engagementTeam(6, $big), ...engagementTeam(5, $mid), ...engagementTeam(2, $tiny)];
    $ids = array_map(fn ($e) => $e->id, $staff);
    $assigned = $keys->assign('department', $ids, '2026-10-05');
    $counts = array_count_values(array_map(fn ($k) => (string) $k, $assigned));
    // Tiny (2) alone is below k; merged with the smallest qualifying group (Mid) into "other" (7).
    expect($counts)->toBe(["department:{$big->id}" => 6, 'other' => 7]);

    // Only one qualifying group and a small rest: no keys at all (overall only).
    $assigned = $keys->assign('department', array_map(fn ($e) => $e->id, [...array_slice($staff, 0, 6), ...array_slice($staff, 11, 2)]), '2026-10-05');
    expect(array_values(array_unique($assigned)))->toBe([null]);
});

it('suppresses results below k, releases anonymous results only after closing, and hides small groups', function () {
    $big = Department::factory()->create(['name' => 'Big']);
    $small = Department::factory()->create(['name' => 'Small']);
    $bigStaff = engagementTeam(7, $big);
    $smallStaff = engagementTeam(5, $small);
    $version = openEngagementSurvey($this->actors, ['breakdown_dimension' => 'department']);

    answerAll($bigStaff, $version, fn ($i) => ['mood' => (string) (($i % 5) + 1), 'tools' => ['laptop']]);
    answerAll(array_slice($smallStaff, 0, 1), $version, fn () => ['mood' => '1', 'comment' => 'My manager is unfair']);

    // While open: nothing released for anonymous surveys (no live differencing).
    $live = $this->analytics->results($version->refresh(), $this->actors['analyst']);
    expect($live['released'])->toBeFalse()->and($live['questions'])->toBe([]);

    app(Surveys::class)->close($version, $this->actors['preparer']);
    $results = $this->analytics->results($version->refresh(), $this->actors['analyst']);
    // Overall 8 ≥ 5 is shown; Big (7) would make "total − Big" = 1 → Big is suppressed as well.
    expect($results['respondents'])->toBe(8)
        ->and(collect($results['groups'])->pluck('suppressed', 'label')->all())->toBe(['Department: Big' => true, 'Department: Small' => true])
        ->and(collect($results['groups'])->pluck('respondents')->filter()->all())->toBe([]);
    $mood = collect($results['questions'])->firstWhere('key', 'mood');
    expect($mood['overall']['answered'])->toBe(8)->and(array_sum($mood['overall']['distribution']))->toBe(8)
        ->and(array_filter($mood['groups']))->toBe([]);
    // "tools" answered by 7 only overall and groups suppressed; "comment" answered by 1 → suppressed entirely.
    expect(collect($results['questions'])->firstWhere('key', 'comment')['overall'])->toBeNull();

    // Free text: below text_min_group (10) → suppressed.
    $comment = $version->questions()->where('key', 'comment')->first();
    expect($this->analytics->comments($version, $comment, $this->actors['analyst']))->toBeNull();
});

it('shows groups that meet k together with their complement, and never shows anything below k overall', function () {
    $a = Department::factory()->create(['name' => 'A']);
    $b = Department::factory()->create(['name' => 'B']);
    $staffA = engagementTeam(6, $a);
    $staffB = engagementTeam(6, $b);
    $version = openEngagementSurvey($this->actors, ['breakdown_dimension' => 'department']);
    answerAll($staffA, $version, fn () => ['mood' => '5']);
    answerAll(array_slice($staffB, 0, 4), $version, fn () => ['mood' => '1']);
    app(Surveys::class)->close($version, $this->actors['preparer']);

    $results = $this->analytics->results($version->refresh(), $this->actors['analyst']);
    // A = 6, B = 4: B is below k and alone would equal total − A, so A is hidden too.
    expect(collect($results['groups'])->pluck('suppressed')->all())->toBe([true, true]);

    $tiny = openEngagementSurvey($this->actors, [], null, 'TINY');
    answerAll(array_slice($staffA, 0, 4), $tiny, fn () => ['mood' => '3']);
    app(Surveys::class)->close($tiny, $this->actors['preparer']);
    $r = $this->analytics->results($tiny->refresh(), $this->actors['analyst']);
    expect($r['suppressed'])->toBeTrue()->and($r['respondents'])->toBeNull()->and($r['questions'])->toBe([]);
});

it('gives a manager only their own team group above k, never overall or other groups, and free text never', function () {
    $tenant = $this->tenant;
    $manager = engagementStaff(null, null, ['engagement.participate', 'engagement.team_results']);
    $otherManager = engagementStaff(null, null, ['engagement.participate', 'engagement.team_results']);
    $team = engagementTeam(6, null, $manager);
    $otherTeam = engagementTeam(5, null, $otherManager);
    $version = openEngagementSurvey($this->actors, ['breakdown_dimension' => 'manager', 'result_visibility' => ['hr' => true, 'managers' => true]]);
    answerAll($team, $version, fn () => ['mood' => '4']);
    answerAll($otherTeam, $version, fn () => ['mood' => '2']);
    app(Surveys::class)->close($version, $this->actors['preparer']);

    $view = $this->analytics->results($version->refresh(), $manager->user->fresh());
    expect($view['view'])->toBe('manager')->and($view['respondents'])->toBe(6)
        ->and(collect($view['groups'])->pluck('key')->all())->toBe(["manager:{$manager->id}"]);
    $mood = collect($view['questions'])->firstWhere('key', 'mood');
    expect($mood['overall'])->toBeNull()->and($mood['groups']["manager:{$manager->id}"]['average'])->toBe(4.0)
        ->and(array_keys($mood['groups']))->toBe(["manager:{$manager->id}"]);
    expect(fn () => $this->analytics->comments($version, $version->questions()->where('key', 'comment')->first(), $manager->user->fresh()))->toThrow(EngagementRuleViolation::class)
        ->and(fn () => $this->analytics->participation($version, $manager->user->fresh()))->toThrow(EngagementRuleViolation::class);

    // An ordinary employee cannot see results unless the version shares them.
    expect(fn () => $this->analytics->results($version, $team[0]->user->fresh()))->toThrow(EngagementRuleViolation::class);
});

it('releases free text only overall above the text threshold, shuffled and without handles for anonymous surveys', function () {
    $staff = engagementTeam(11);
    $version = openEngagementSurvey($this->actors);
    answerAll($staff, $version, fn ($i) => ['mood' => '3', 'comment' => "Comment {$i}"]);
    app(Surveys::class)->close($version, $this->actors['preparer']);
    $comments = $this->analytics->comments($version->refresh(), $version->questions()->where('key', 'comment')->first(), $this->actors['analyst']);
    expect($comments)->toHaveCount(11)->and(collect($comments)->pluck('handle')->filter()->all())->toBe([])
        ->and(collect($comments)->pluck('text')->sort()->values()->all())->toBe(collect(range(0, 10))->map(fn ($i) => "Comment {$i}")->sort()->values()->all());
    expect(fn () => $this->analytics->comments($version, $version->questions()->where('key', 'comment')->first(), tenantUser($this->tenant, ['engagement.analytics'])))->toThrow(EngagementRuleViolation::class);
});

it('limits HR analytics to surveys whose whole population is inside the analyst scope', function () {
    $staff = engagementTeam(6);
    $version = openEngagementSurvey($this->actors);
    $scoped = tenantUser($this->tenant, ['engagement.analytics']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => Department::factory()->create()->id]);
    app(AccessScopes::class)->forget();
    expect($this->analytics->access($version, $scoped->fresh()))->toBeNull()
        ->and($this->analytics->access($version, $this->actors['analyst']))->toBe('hr');
    expect(SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(6);
});

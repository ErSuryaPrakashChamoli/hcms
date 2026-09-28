<?php

use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Feedback;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Performance\Services\OneOnOnes;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team']);
    $this->employee = activeEmployee($this->manager);
    $this->peer = activeEmployee($this->manager);
    $this->read = app(ApiKeys::class)->issue('BI', ['performance.read']);
    $this->write = app(ApiKeys::class)->issue('OKR tool', ['performance.read', 'performance.write']);
});

it('records goal progress idempotently and refuses without the write scope', function () {
    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'title' => 'Uptime', 'target_value' => 100, 'weight' => 50]);
    $url = "/api/v1/performance/goals/{$goal->id}/progress";

    $this->withHeader('X-Api-Key', $this->read['plaintext'])->postJson($url, ['value' => 50])->assertStatus(403);

    $first = $this->withHeaders(['X-Api-Key' => $this->write['plaintext'], 'Idempotency-Key' => 'sync-001'])->postJson($url, ['value' => 50, 'measurement' => 'Status page'])->assertCreated();
    $retry = $this->withHeaders(['X-Api-Key' => $this->write['plaintext'], 'Idempotency-Key' => 'sync-001'])->postJson($url, ['value' => 75])->assertOk();

    expect($first->json('data.id'))->toBe($retry->json('data.id'))
        ->and($first->json('data.source'))->toBe('api')
        ->and($first->json('data.previous_value'))->toEqual(0)
        ->and(GoalCheckIn::query()->where('goal_id', $goal->id)->count())->toBe(1)
        ->and((float) $goal->refresh()->progress)->toBe(50.0);

    $this->withHeaders(['X-Api-Key' => $this->write['plaintext'], 'Idempotency-Key' => str_repeat('x', 65)])->postJson($url, ['value' => 60])->assertStatus(422);
    $this->withHeader('X-Api-Key', $this->write['plaintext'])->postJson($url, ['value' => 'lots'])->assertStatus(422);
    $this->withHeader('X-Api-Key', $this->read['plaintext'])->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.measurement', 'Status page');
});

it('never exposes notes, answers, anonymous authors, PIP reasons or unfinalized ratings', function () {
    $appraisals = app(Appraisals::class);
    $cycle = $appraisals->launch(draftCycle(['stages' => [['key' => 'final', 'name' => 'Final', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]), $this->hr);
    $mine = Appraisal::query()->where('employee_id', $this->employee->id)->firstOrFail();
    $appraisals->calibrate($mine, 4.0, 'Secret calibration note', $this->hr);

    $meeting = OneOnOne::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $this->manager->id, 'scheduled_at' => now(), 'notes' => 'Shared words']);
    app(OneOnOnes::class)->setPrivateNotes($meeting, 'Private words', $this->manager->user);
    app(Feedback::class)->give($this->employee, $this->peer, 'constructive', 'Anonymous words', 'manager', anonymous: true);
    app(ImprovementPlans::class)->open($this->peer, $this->manager, 'Reason words', [['objective' => 'Objective words']], '2026-10-01', '2026-11-30');

    $key = ['X-Api-Key' => $this->read['plaintext']];
    $body = collect(['cycles', 'goals', 'reviews', 'check-ins', 'one-on-ones', 'feedback', 'competencies', 'pips', 'analytics'])
        ->map(fn ($path) => $this->withHeaders($key)->getJson("/api/v1/performance/{$path}")->assertOk()->getContent())->implode("\n");

    foreach (['Secret calibration note', 'Shared words', 'Private words', 'Anonymous words', 'Reason words', 'Objective words', $this->peer->employee_code.'","status":"given'] as $secret) {
        expect($body)->not->toContain($secret);
    }
    $reviews = $this->withHeaders($key)->getJson('/api/v1/performance/reviews?cycle=FY26')->json('data');
    expect(collect($reviews)->firstWhere('employee_code', $this->employee->employee_code)['final_rating'])->toBeNull()
        ->and($this->withHeaders($key)->getJson('/api/v1/performance/feedback')->json('data.0'))->toMatchArray(['anonymous' => true, 'author_code' => null])
        ->and($this->withHeaders($key)->getJson('/api/v1/performance/cycles')->json('data.0.template_checksum'))->toBe($cycle->templateVersion->checksum);
});

it('suppresses analytics groups smaller than the configured minimum', function () {
    $key = ['X-Api-Key' => $this->read['plaintext']];
    $cycle = app(Appraisals::class)->launch(draftCycle(), $this->hr);

    $small = $this->withHeaders($key)->getJson('/api/v1/performance/analytics?cycle=FY26')->assertOk()->json('data');
    expect($small['min_group'])->toBe(5)->and($small['overall'])->toBe(['employees' => null, 'suppressed' => true]);

    config(['peopleos.performance.analytics_min_group' => 2]);
    $open = $this->withHeaders($key)->getJson('/api/v1/performance/analytics?cycle=FY26')->json('data');
    expect($open['overall']['suppressed'])->toBeFalse()
        ->and($open['overall']['employees'])->toBe($cycle->appraisals()->count())
        ->and($open['overall']['rating_distribution'])->toBeNull(); // nothing finalized yet
});

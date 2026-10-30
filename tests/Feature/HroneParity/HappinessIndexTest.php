<?php

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\MoodCheckIn;
use App\Domain\Engagement\Models\MoodParticipation;
use App\Domain\Engagement\Models\MoodReason;
use App\Domain\Engagement\Services\ConfidentialIdentities;
use App\Domain\Engagement\Services\MoodAnalytics;
use App\Domain\Engagement\Services\MoodCheckIns;
use App\Domain\Engagement\Services\MoodDefaults;
use App\Domain\Organisation\Models\Department;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\HappinessIndex;
use App\Filament\Pages\Home;
use App\Filament\Resources\MoodReasons\Pages\ManageMoodReasons;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Engagement/EngagementTestHelpers.php';

/* HROne setup parity HP-9f — Engage → Happiness index (mood bot): the switch, frequency, weekday and privacy, the reason
 * tags (at most 20 active, HROne's 14 seeded), one check-in per period through the engagement anonymity boundary, the
 * Home question, and results (dashboard and dataset) only above the privacy thresholds; off by default. */

/** @return list<Employee> */
function hp9fTeam(int $count, Department $department): array
{
    return array_map(fn () => engagementStaff($department, null, ['engagement.mood']), range(1, $count));
}

function hp9fOn(array $settings = []): void
{
    foreach (['engage.mood.enabled' => true] + $settings as $key => $value) {
        app(SettingsRepository::class)->set($key, $value, 'Mood bot');
    }
}

beforeEach(function () {
    $this->travelTo('2026-10-30 06:00:00'); // a Friday (ISO week 44)
    config(['peopleos.engagement.analytics_min_group' => 3, 'peopleos.engagement.text_min_group' => 4]);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->moods = app(MoodCheckIns::class);
    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->ops = Department::factory()->create(['name' => 'Operations']);
    $this->analyst = tenantUser($this->tenant, ['engagement.view', 'engagement.manage', 'engagement.analytics', 'engagement.comments']);
});

it('seeds HROne’s fourteen reasons and keeps at most twenty active, never deleting one', function () {
    expect(MoodReason::query()->where('status', 'active')->orderBy('sort_order')->pluck('name')->all())->toBe(MoodDefaults::REASONS)
        ->and(app(MoodDefaults::class)->seed())->toBe(0);
    foreach (range(1, 6) as $i) {
        MoodReason::query()->create(['name' => "Reason {$i}"]);
    }
    expect(fn () => MoodReason::query()->create(['name' => 'One too many']))->toThrow(RuntimeException::class, 'up to 20 active')
        ->and(MoodReason::query()->create(['name' => 'Kept aside', 'status' => 'inactive'])->exists)->toBeTrue()
        ->and(fn () => MoodReason::query()->where('name', 'Kept aside')->first()->update(['status' => 'active']))->toThrow(RuntimeException::class, 'up to 20 active')
        ->and(fn () => MoodReason::query()->where('name', 'Growth')->first()->delete())->toThrow(RuntimeException::class, 'never deleted');

    $this->actingAs($this->analyst);
    Livewire::test(ManageMoodReasons::class)->assertOk()->assertSee('You can add up to 20 active tags (20 active now)')->assertSee('Appraisal Salary');
});

it('asks nobody while the mood bot is off (the default)', function () {
    [$asha] = hp9fTeam(1, $this->sales);
    expect($this->moods->enabled())->toBeFalse()->and($this->moods->prompt($asha->user))->toBeNull()
        ->and(fn () => $this->moods->checkIn($asha->user, 4))->toThrow(EngagementRuleViolation::class, 'switched off');
    $this->actingAs($asha->user);
    Livewire::test(Home::class)->assertOk()->assertDontSee('How’s your mood today?');
    expect(HappinessIndex::canAccess())->toBeFalse();
});

it('asks once per period from the weekday, and keeps anonymous check-ins apart from the person', function () {
    [$asha, $ravi] = hp9fTeam(2, $this->sales);
    hp9fOn();
    $this->travelTo('2026-10-29 06:00:00'); // Thursday: the weekly question opens on Friday
    expect($this->moods->prompt($asha->user))->toBeNull()
        ->and(fn () => $this->moods->checkIn($asha->user, 4))->toThrow(EngagementRuleViolation::class, 'not open today');

    $this->travelTo('2026-10-30 06:00:00');
    $growth = MoodReason::query()->where('name', 'Growth')->value('id');
    expect($this->moods->prompt($asha->user))->toMatchArray(['period' => '2026-W44', 'label' => 'Week 44, 2026', 'privacy' => 'anonymous'])
        ->and($this->moods->checkIn($asha->user, 4, [$growth], 'SECRET-MOOD-COMMENT'))->toBe(['status' => 'submitted', 'period' => '2026-W44'])
        ->and($this->moods->checkIn($asha->user, 1))->toBe(['status' => 'already_submitted', 'period' => '2026-W44'])
        ->and($this->moods->prompt($asha->user))->toBeNull()
        ->and(fn () => $this->moods->checkIn($ravi->user, 6))->toThrow(EngagementRuleViolation::class, '1 to 5')
        ->and(fn () => $this->moods->checkIn($ravi->user, 3, [999999]))->toThrow(EngagementRuleViolation::class, 'not one of the reasons')
        ->and(fn () => $this->moods->checkIn(tenantUser($this->tenant, ['engagement.participate']), 3))->toThrow(EngagementRuleViolation::class, 'engagement.mood');

    $checkIn = MoodCheckIn::query()->sole();
    expect($checkIn->employee_id)->toBeNull()->and($checkIn->checked_in_on)->toBeNull()->and($checkIn->privacy)->toBe('anonymous')
        ->and($checkIn->reason_ids)->toBe([$growth])->and(strlen($checkIn->id))->toBe(36)
        ->and(Schema::getColumnListing('mood_checkins'))->not->toContain('created_at')->not->toContain('updated_at')->not->toContain('user_id')
        ->and(MoodParticipation::query()->sole()->checked_in_on->toDateString())->toBe('2026-10-30')
        ->and(fn () => $checkIn->update(['mood' => 5]))->toThrow(RuntimeException::class, 'never changed')
        ->and(EngagementIdentity::query()->count())->toBe(0);
    $audit = AuditEvent::query()->where('action', 'MOOD_CHECKIN_SUBMITTED')->sole();
    expect($audit->actor_id)->toBeNull()->and($audit->metadata)->toMatchArray(['mode' => 'anonymous', 'period' => '2026-W44'])
        ->and(AuditEvent::query()->get()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n"))->not->toContain('SECRET-MOOD-COMMENT');

    // The next week is a new period.
    $this->travelTo('2026-11-06 06:00:00');
    expect($this->moods->checkIn($asha->user, 2)['status'])->toBe('submitted');
});

it('keeps confidential authorship for the audited reveal and identified check-ins for HR only', function () {
    [$asha, $ravi] = hp9fTeam(2, $this->sales);
    hp9fOn(['engage.mood.privacy' => 'confidential', 'engage.mood.frequency' => 'daily']);
    $this->moods->checkIn($asha->user, 2, [], 'Too many meetings');
    $confidential = MoodCheckIn::query()->sole();
    $reviewer = tenantUser($this->tenant, ['engagement.confidential_identity']);
    expect($confidential->employee_id)->toBeNull()->and($confidential->period_key)->toBe('2026-10-30')
        ->and(EngagementIdentity::query()->where('subject_type', 'mood_checkin')->value('employee_id'))->toBe($asha->id)
        ->and(fn () => app(ConfidentialIdentities::class)->reveal('mood_checkin', $confidential->id, 'short', $reviewer))->toThrow(EngagementRuleViolation::class, 'clear reason')
        ->and(app(ConfidentialIdentities::class)->reveal('mood_checkin', $confidential->id, 'Raised a safety concern', $reviewer)->id)->toBe($asha->id)
        ->and(AuditEvent::query()->where('action', 'CONFIDENTIAL_RESPONSE_IDENTIFIED')->count())->toBe(1);

    app(SettingsRepository::class)->set('engage.mood.privacy', 'identified', 'Identified');
    $this->moods->checkIn($ravi->user, 5, [], 'Great day');
    $identified = MoodCheckIn::query()->where('privacy', 'identified')->sole();
    $hr = tenantUser($this->tenant, ['engagement.responses']);
    expect($identified->employee_id)->toBe($ravi->id)->and($identified->checked_in_on->toDateString())->toBe('2026-10-30')
        ->and(collect(app(MoodAnalytics::class)->identified($hr))->pluck('employee.id')->all())->toBe([$ravi->id])
        ->and(app(MoodAnalytics::class)->identified($this->analyst))->toBe([])
        ->and(AuditEvent::query()->where('action', 'MOOD_CHECKIN_SUBMITTED')->whereNotNull('actor_id')->count())->toBe(1);

    app(SettingsRepository::class)->set('engage.mood.privacy', 'anonymous', 'Anonymous');
    $this->travelTo('2026-10-31 06:00:00');
    $this->moods->checkIn($asha->user, 3);
    $anonymous = MoodCheckIn::query()->where('privacy', 'anonymous')->sole();
    expect(fn () => app(ConfidentialIdentities::class)->reveal('mood_checkin', $anonymous->id, 'Raised a safety concern', $reviewer))->toThrow(EngagementRuleViolation::class, 'never can');
});

it('shows results only for periods that ended, groups above the threshold with complementary suppression, and comments above the text threshold', function () {
    $sales = hp9fTeam(4, $this->sales);
    $ops = hp9fTeam(3, $this->ops);
    hp9fOn();
    foreach ($sales as $i => $e) {
        $this->moods->checkIn($e->user, 5, [], 'Sales comment '.$i);
    }
    foreach ($ops as $e) {
        $this->moods->checkIn($e->user, 2);
    }
    $analytics = app(MoodAnalytics::class);
    expect($analytics->results($this->analyst)['periods'])->toBe([]) // week 44 is still open
        ->and(fn () => $analytics->results(tenantUser($this->tenant, ['engagement.view'])))->toThrow(EngagementRuleViolation::class);

    // Week 45: Sales 4, Operations 1 — Operations is too small, and hiding it alone would reveal it (5 − 4).
    $this->travelTo('2026-11-06 06:00:00');
    foreach ($sales as $e) {
        $this->moods->checkIn($e->user, 4);
    }
    $this->moods->checkIn($ops[0]->user, 1);
    // Week 46: two check-ins only.
    $this->travelTo('2026-11-13 06:00:00');
    $this->moods->checkIn($sales[0]->user, 3);
    $this->moods->checkIn($sales[1]->user, 3);

    $this->travelTo('2026-11-16 06:00:00');
    $results = $analytics->results($this->analyst);
    [$w46, $w45, $w44] = $results['periods'];
    $groups = fn (array $period) => collect($period['groups'])->mapWithKeys(fn ($g) => [$g['label'] => $g['respondents']])->all();
    expect($w44)->toMatchArray(['label' => 'Week 44, 2026', 'respondents' => 7, 'average' => 3.71, 'happy_share' => 57.1])
        ->and($w44['distribution'])->toBe([1 => 0, 2 => 3, 3 => 0, 4 => 0, 5 => 4])
        ->and($groups($w44))->toBe(['Department: Operations' => 3, 'Department: Sales' => 4])
        ->and($w45['respondents'])->toBe(5)->and($groups($w45))->toBe(['Department: Operations' => null, 'Department: Sales' => null])
        ->and($w46['respondents'])->toBeNull()->and($w46['groups'])->toBe([])
        ->and($results['current']['label'])->toBe('Week 47, 2026');

    // Comments: overall only, shuffled, never below the text threshold, and only for engagement.comments holders.
    expect(collect($analytics->comments($this->analyst, '2026-W44'))->pluck('text')->sort()->values()->all())->toBe(['Sales comment 0', 'Sales comment 1', 'Sales comment 2', 'Sales comment 3'])
        ->and(collect($analytics->comments($this->analyst, '2026-W44'))->pluck('handle')->filter()->all())->toBe([])
        ->and($analytics->comments($this->analyst, '2026-W45'))->toBeNull()
        ->and(fn () => $analytics->comments(tenantUser($this->tenant, ['engagement.analytics']), '2026-W44'))->toThrow(EngagementRuleViolation::class);

    // The dataset: one row per period and group, suppressed cells left out, no comments.
    $report = app(ReportRunner::class)->execute('happiness_index', ['fields' => ['period', 'group', 'respondents', 'average_mood']], $this->analyst);
    expect(collect($report->rows)->sortBy('group')->values()->all())->toEqual([
        ['period' => 'Week 44, 2026', 'group' => 'Department: Operations', 'respondents' => 3, 'average_mood' => 2.0],
        ['period' => 'Week 44, 2026', 'group' => 'Department: Sales', 'respondents' => 4, 'average_mood' => 5.0],
    ])->and(array_keys(app(DatasetRegistry::class)->get('happiness_index')->fields()))->not->toContain('comment');

    // The dashboard.
    $this->actingAs($this->analyst);
    Livewire::test(HappinessIndex::class)->assertOk()->assertSee('Week 44, 2026')->assertSee('Department: Sales')->assertSee('suppressed')
        ->call('showComments', '2026-W44')->assertSee('Sales comment 2');
    $this->actingAs($sales[0]->user);
    $this->get(HappinessIndex::getUrl())->assertForbidden();
});

it('asks on Home and records the answer from the question card', function () {
    [$asha] = hp9fTeam(1, $this->sales);
    hp9fOn();
    $work = MoodReason::query()->where('name', 'Work environment')->value('id');
    $this->actingAs($asha->user);
    Livewire::test(Home::class)->assertOk()->assertSee('How’s your mood today?')->assertSee('Very happy')
        ->callAction('moodCheckIn', ['mood' => 4, 'reasons' => [$work], 'comment' => 'Good week'], ['mood' => 4])->assertHasNoActionErrors()
        ->assertDontSee('How’s your mood today?');
    expect(MoodCheckIn::query()->sole())->mood->toBe(4)->reason_ids->toBe([$work])->comment->toBe('Good week');
});

<?php

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Communication\Services\Communications;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Performance\Services\Goals;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\WallComment;
use App\Domain\Recognition\Models\WallPost;
use App\Domain\Recognition\Models\WallReaction;
use App\Domain\Recognition\Services\Recognitions;
use App\Domain\Recognition\Services\RewardPoints;
use App\Domain\Recognition\Services\SocialWall;
use App\Filament\Pages\Home;
use App\Filament\Pages\SocialWall as SocialWallPage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Engagement/EngagementTestHelpers.php';

/* HROne setup parity HP-9e — Engage → Social wall: posts for badges, rewards, celebrations, announcements, new joiners
 * and achievements, HR posts, reactions, comments (restricted per post type), moderation and reports, the wall scope,
 * the festive greeting and the Home card; off by default. */

const HP9E_PERMS = ['recognition.view', 'recognition.give', 'wall.view', 'celebration.view', 'communication.view'];

/** An active employee with a login in $company. */
function hp9eStaffIn(Company $company, array $permissions = HP9E_PERMS): Employee
{
    $user = tenantUser(test()->tenant, $permissions);
    $employee = app(HireEmployeeAction::class)->handle(['first_name' => $user->name, 'last_name' => 'Wall'],
        ['joining_date' => '2025-01-01', 'work_email' => $user->email, 'user_id' => $user->id], ['company_id' => $company->id], null);
    forceLifecycle($employee, LifecycleState::Active);

    return $employee->refresh();
}

function hp9eWallOn(array $settings = []): void
{
    foreach (['engage.wall.enabled' => true] + $settings as $key => $value) {
        app(SettingsRepository::class)->set($key, $value, 'Social wall');
    }
}

beforeEach(function () {
    $this->travelTo('2026-10-14 06:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->wall = app(SocialWall::class);
    $this->hr = tenantUser($this->tenant, [...HP9E_PERMS, 'recognition.manage', 'recognition.points', 'wall.post', 'wall.moderate']);
    $this->manager = activeEmployee(null, HP9E_PERMS);
    $this->asha = activeEmployee($this->manager, HP9E_PERMS);
    $this->ravi = activeEmployee($this->manager, HP9E_PERMS);
    $this->badge = Badge::query()->where('code', 'TEAM_PLAYER')->firstOrFail();
});

it('keeps the wall off by default: no posts, no feed, no page, no Home card', function () {
    app(Recognitions::class)->give($this->badge, $this->ravi, $this->asha->user, 'Thanks');
    EmployeeReminderDue::dispatch($this->asha, 'employee.birthday', []);

    expect(WallPost::query()->count())->toBe(0)
        ->and($this->wall->feed($this->asha->user))->toHaveCount(0)
        ->and(fn () => $this->wall->post($this->hr, 'Hello'))->toThrow(RuntimeException::class, 'switched off');
    $this->actingAs($this->asha->user);
    expect(SocialWallPage::canAccess())->toBeFalse();
    $this->get(SocialWallPage::getUrl())->assertForbidden();
    Livewire::test(Home::class)->assertOk()->assertDontSee('Social wall');
});

it('posts public badges once, never private ones, takes a withdrawn badge’s post down and shows badge points when asked', function () {
    hp9eWallOn();
    $recognition = app(Recognitions::class)->give($this->badge, $this->ravi, $this->asha->user, 'For the release');
    $private = Badge::query()->create(['code' => 'QUIET', 'name' => 'Quiet thanks', 'visibility' => 'private']);
    app(Recognitions::class)->give($private, $this->asha, $this->ravi->user, 'Private');

    $post = WallPost::query()->sole();
    expect($post->type)->toBe('badge')->and($post->title)->toBe($this->ravi->person->display_name.' received the badge Team Player')
        ->and($post->body)->toBe('For the release')->and($post->employee_id)->toBe($this->ravi->id)
        ->and($this->wall->feed($this->manager->user)->pluck('id')->all())->toBe([$post->id]);

    app(Recognitions::class)->withdraw($recognition, $this->hr, 'Given twice');
    expect($post->refresh()->status)->toBe('hidden')
        ->and($this->wall->feed($this->manager->user))->toHaveCount(0)
        ->and($this->wall->feed($this->hr)->pluck('id')->all())->toBe([$post->id])
        ->and(fn () => $this->wall->restore($post, $this->hr, 'Back'))->toThrow(RuntimeException::class, 'withdrawn');

    // Points: a badge that shows its points on the wall, and points given between colleagues.
    app(SettingsRepository::class)->set('engage.points.enabled', true, 'On');
    app(RewardPoints::class)->deposit(100, $this->hr);
    $star = Badge::query()->create(['code' => 'STAR', 'name' => 'Star', 'reward_points' => 20, 'show_points_on_wall' => true]);
    app(Recognitions::class)->give($star, $this->asha, $this->ravi->user, 'Star work');
    expect(WallPost::query()->where('type', 'badge')->latest('id')->first()->meta['points'])->toBe(20);

    $policy = Policy::create(['type' => 'reward_points', 'name' => 'Monthly', 'code' => 'RP_WALL']);
    app(Policies::class)->draft($policy, ['credit_cycle' => 'monthly', 'points_per_cycle' => 50]);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'reward_points', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    app(RewardPoints::class)->credit($this->asha);
    app(RewardPoints::class)->give($this->asha->user, $this->ravi, 10, 'For the demo');
    expect(WallPost::query()->where('type', 'reward')->sole())->title->toBe($this->asha->person->display_name.' gave '.$this->ravi->person->display_name.' 10 reward points')
        ->body->toBe('For the demo');
});

it('lets HR post, everyone react once and comment unless comments are restricted for the post type', function () {
    hp9eWallOn(['engage.wall.attachments' => false]);
    $post = $this->wall->post($this->hr, 'Town hall moved to Friday', 'See you at 4 pm.');
    expect($post->type)->toBe('hr_post')->and($post->author_user_id)->toBe($this->hr->id)
        ->and(fn () => $this->wall->post($this->asha->user, 'Me too'))->toThrow(RuntimeException::class, 'cannot post')
        ->and(fn () => $this->wall->post($this->hr, 'With a file', null, ['tenants/'.$this->tenant->id.'/wall/x.pdf']))->toThrow(RuntimeException::class, 'Attachments are not allowed');

    $this->wall->react($post, $this->asha->user, 'like');
    $this->wall->react($post, $this->asha->user, 'love');
    $this->wall->react($post, $this->ravi->user, 'like');
    expect(WallReaction::query()->where('wall_post_id', $post->id)->count())->toBe(2)
        ->and($this->wall->feed($this->asha->user)->first()->my_reaction)->toBe('love')
        ->and($this->wall->feed($this->asha->user)->first()->reactions_count)->toBe(2);
    $this->wall->react($post, $this->asha->user, null);
    expect(WallReaction::query()->where('wall_post_id', $post->id)->count())->toBe(1);

    $comment = $this->wall->comment($post, $this->asha->user, '<i>Great</i> news');
    expect($comment->body)->toBe('Great news')
        ->and(fn () => $this->wall->removeComment($comment, $this->ravi->user))->toThrow(RuntimeException::class, 'Only its author')
        ->and(fn () => $comment->update(['body' => 'Edited']))->toThrow(RuntimeException::class, 'never edited')
        ->and($this->wall->removeComment($comment->refresh(), $this->asha->user)->status)->toBe('removed');

    app(SettingsRepository::class)->set('engage.wall.comments_disabled_for', ['hr_post'], 'No comments on HR posts');
    expect(fn () => $this->wall->comment($post, $this->ravi->user, 'Hi'))->toThrow(RuntimeException::class, 'not allowed on this kind of post')
        ->and(fn () => $post->delete())->toThrow(RuntimeException::class, 'never deleted');

    // The page: HR posts, an employee comments where allowed.
    app(SettingsRepository::class)->set('engage.wall.comments_disabled_for', [], 'Comments back');
    $this->actingAs($this->hr);
    Livewire::test(SocialWallPage::class)->assertOk()->callAction('post', ['title' => 'Diwali party', 'body' => 'Friday 6 pm'])->assertHasNoActionErrors();
    $this->actingAs($this->ravi->user);
    Livewire::test(SocialWallPage::class)->assertOk()->assertSee('Diwali party')->assertSee('Town hall moved to Friday')
        ->callAction('comment', ['body' => 'Count me in'], ['post' => WallPost::query()->where('title', 'Diwali party')->value('id')]);
    expect(WallComment::query()->where('body', 'Count me in')->exists())->toBeTrue();
});

it('lets moderators hide, restore and pin, and tells them of reports', function () {
    hp9eWallOn();
    $first = $this->wall->post($this->hr, 'First');
    $second = $this->wall->post($this->hr, 'Second');
    $comment = $this->wall->comment($second, $this->asha->user, 'Rude words');

    expect(fn () => $this->wall->hide($second, $this->asha->user, 'x'))->toThrow(RuntimeException::class, 'Only wall moderators')
        ->and(fn () => $this->wall->pin($first, $this->asha->user))->toThrow(RuntimeException::class, 'Only wall moderators')
        ->and($this->wall->report($comment, $this->ravi->user, 'Not nice'))->toBe(1)
        ->and(NotificationDelivery::query()->where('event', 'wall.reported')->where('user_id', $this->hr->id)->exists())->toBeTrue();

    $this->wall->hide($comment, $this->hr, 'Offensive');
    expect($this->wall->comments($second, $this->ravi->user))->toHaveCount(0)->and($this->wall->comments($second, $this->hr))->toHaveCount(1);

    $this->wall->hide($second, $this->hr, 'Off topic');
    expect($this->wall->feed($this->asha->user)->pluck('title')->all())->toBe(['First'])
        ->and(fn () => $this->wall->comment($second->refresh(), $this->asha->user, 'Hi'))->toThrow(RuntimeException::class, 'cannot see this post')
        ->and(fn () => $this->wall->react($second, $this->asha->user))->toThrow(RuntimeException::class, 'cannot see this post');
    $this->wall->restore($second->refresh(), $this->hr, 'Reviewed');
    $this->wall->pin($first, $this->hr);
    expect($this->wall->feed($this->asha->user)->pluck('title')->all())->toBe(['First', 'Second']);
});

it('shows people the posts of their own company when the wall is company-wide', function () {
    hp9eWallOn(['engage.wall.scope' => 'company']);
    $other = Company::factory()->create(['name' => 'Second Co']);
    $far = hp9eStaffIn($other);
    app(Recognitions::class)->give($this->badge, $far, $this->asha->user, 'Cross-company thanks');
    app(Recognitions::class)->give($this->badge, $this->ravi, $this->asha->user, 'Same company');
    $hr = $this->wall->post($this->hr, 'For everyone');

    expect($this->wall->feed($this->manager->user)->pluck('employee_id')->filter()->values()->all())->toBe([$this->ravi->id])
        // The joiner from the other company sees their own welcome and badge, and the organisation-wide HR post.
        ->and($this->wall->feed($far->user)->pluck('employee_id')->filter()->unique()->values()->all())->toBe([$far->id])
        ->and($this->wall->feed($far->user)->pluck('type')->sort()->values()->all())->toBe(['badge', 'hr_post', 'new_joiner'])
        ->and($this->wall->feed($far->user)->pluck('id')->all())->toContain($hr->id);
});

it('posts celebrations, announcements, new joiners and achievements to the people who may see them', function () {
    $outsider = activeEmployee(null, ['wall.view']);
    hp9eWallOn();
    $this->asha->person->update(['birthday_shared' => true]);
    EmployeeReminderDue::dispatch($this->asha->refresh(), 'employee.birthday', []);
    EmployeeReminderDue::dispatch($this->asha, 'employee.birthday', []);
    $this->ravi->person->update(['birthday_shared' => false]);
    EmployeeReminderDue::dispatch($this->ravi->refresh(), 'employee.birthday', []); // not shared: no post
    $birthday = WallPost::query()->where('type', 'celebration')->sole();
    expect($birthday->title)->toBe('Happy birthday, '.$this->asha->person->display_name.'!')
        ->and($this->wall->feed($this->ravi->user)->pluck('id')->all())->toBe([$birthday->id])
        ->and($this->wall->feed($outsider->user))->toHaveCount(0);

    // An announcement is seen by its recipients only.
    $actors = engagementActors();
    $comms = app(Communications::class);
    $draft = $comms->create(['title' => 'New canteen menu', 'body' => 'Starting **Monday**.', 'audience_criteria' => ['employee_ids' => [$this->asha->id]]], $actors['preparer']);
    $comms->publish($comms->approve($comms->submit($draft, $actors['preparer']), null, $actors['approver']), $actors['preparer']);
    $announcement = WallPost::query()->where('type', 'announcement')->sole();
    expect($announcement->body)->toBe('Starting Monday.')
        ->and($this->wall->feed($this->asha->user)->pluck('id')->all())->toContain($announcement->id)
        ->and($this->wall->feed($this->ravi->user)->pluck('id')->all())->not->toContain($announcement->id)
        ->and($this->wall->feed($this->hr)->pluck('id')->all())->toContain($announcement->id);

    // A new joiner, once.
    EmployeeLifecycleChanged::dispatch($this->ravi, LifecycleState::Onboarding, LifecycleState::Joined, now(), null);
    EmployeeLifecycleChanged::dispatch($this->ravi, LifecycleState::Joined, LifecycleState::Probation, now(), null);
    expect(WallPost::query()->where('type', 'new_joiner')->pluck('title')->all())->toBe(['Welcome, '.$this->ravi->person->display_name.'!']);

    // Achievements only while the organisation shows them.
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $goal = app(Goals::class)->create(['employee_id' => $this->asha->id, 'title' => 'Launch the app', 'weight' => 100]);
    app(Goals::class)->close($goal, 'completed');
    expect(WallPost::query()->where('type', 'goal_achievement')->count())->toBe(0);
    app(SettingsRepository::class)->set('engage.wall.show_achievements', true, 'Achievements');
    $next = app(Goals::class)->create(['employee_id' => $this->asha->id, 'title' => 'Hire two engineers', 'weight' => 100]);
    app(Goals::class)->close($next, 'completed');
    expect(WallPost::query()->where('type', 'goal_achievement')->sole()->title)->toBe($this->asha->person->display_name.' achieved the goal "Hire two engineers"');
});

it('shows the latest posts on Home with a one-click like, and greets people on their holidays', function () {
    hp9eWallOn(['engage.wall.festive_theme' => true]);
    $post = $this->wall->post($this->hr, 'Office closed on Monday');
    $calendar = HolidayCalendar::create(['name' => 'India', 'code' => 'IN']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-10-14', 'name' => 'Founders Day', 'type' => 'public']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);

    $this->actingAs($this->asha->user);
    expect($this->wall->festiveHoliday($this->asha->user))->toBe('Founders Day');
    Livewire::test(Home::class)->assertOk()->assertSee('Social wall')->assertSee('Office closed on Monday')->assertSee('Happy Founders Day!')
        ->call('wallReact', $post->id);
    expect(WallReaction::query()->where('wall_post_id', $post->id)->where('user_id', $this->asha->user_id)->value('reaction'))->toBe('like');
    Livewire::test(SocialWallPage::class)->assertOk()->assertSee('Happy Founders Day!');

    app(SettingsRepository::class)->set('engage.wall.festive_theme', false, 'Off');
    expect($this->wall->festiveHoliday($this->asha->user))->toBeNull();
});

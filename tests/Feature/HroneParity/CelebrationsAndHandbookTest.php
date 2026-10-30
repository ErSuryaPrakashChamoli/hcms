<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Knowledge\Services\PolicyAcknowledgementTaskSource;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\People\Models\BirthdayWish;
use App\Domain\People\Services\Anniversaries;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\Birthdays as BirthdaysPage;
use App\Filament\Pages\Home;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\Pages\ViewArticle;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

/* HROne setup parity HP-9a — Engage → Celebration and HR handbook: work and wedding anniversaries beside birthdays
 * (off by default), roles told of the day's celebrations, message preview, the first-login welcome, handbook due days,
 * downloads, per-employee acknowledgement lists and date filters. Defaults keep the earlier behaviour. */

function hp9aAt(string $local): void
{
    test()->travelTo(Carbon::parse($local, 'Asia/Kolkata'));
}

function hp9aJoined(Employee $employee, string $date): Employee
{
    return forceLifecycle($employee, 'active', ['joining_date' => $date])->refresh();
}

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->settings = app(SettingsRepository::class);
    $this->hr = tenantUser($this->tenant, ['employee.view', 'celebration.view', 'celebration.manage', 'settings.view', 'settings.update']);
    $this->manager = activeEmployee(null, ['celebration.view']);
    $this->asha = activeEmployee($this->manager, ['celebration.view']);
    $this->ravi = activeEmployee($this->manager, ['celebration.view']);
    $this->other = activeEmployee(null, ['celebration.view']);
});

it('keeps today’s behaviour by default: no anniversaries, the Birthdays page, the built-in welcome, no handbook due date', function () {
    hp9aJoined($this->asha, '2023-11-14');
    hp9aAt('2026-11-14 07:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();

    expect(app(Anniversaries::class)->enabledKinds())->toBe([])
        ->and(BirthdaysPage::kinds())->toBe(['birthday' => 'Birthdays'])
        ->and(NotificationDelivery::query()->where('event', 'like', 'celebration.work_anniversary%')->exists())->toBeFalse()
        ->and($this->settings->get('experience.welcome.enabled'))->toBeTrue()
        ->and($this->settings->get('experience.welcome.message'))->toBeNull()
        ->and(app(KnowledgeBase::class)->reminderDays())->toBe(7)
        ->and($this->settings->get('kb.download_allowed'))->toBeFalse();

    $this->actingAs($this->hr);
    Livewire::test(BirthdaysPage::class)->assertSet('kind', 'birthday')->assertDontSee('Work anniversaries');

    $this->actingAs($this->asha->user);
    $home = Livewire::test(Home::class);
    expect($home->instance()->showWelcome())->toBeTrue()->and($home->instance()->welcomeMessage())->toBeNull();
});

it('celebrates work anniversaries from the first full year: greeting, nudges, the event and once a day', function () {
    $this->settings->set('celebration.work_anniversary.enabled', true);
    hp9aJoined($this->asha, '2023-11-14');
    hp9aJoined($this->ravi, '2026-11-14'); // joined today: no anniversary yet
    hp9aJoined($this->other, '2020-02-29');
    $template = NotificationTemplate::create(['key' => 'wa', 'name' => 'Work anniversary', 'subject' => 'Cheers {{ employee.name }}', 'body' => 'x']);
    NotificationRule::create(['name' => 'Work anniversary', 'event' => 'employee.work_anniversary', 'audience' => [['type' => 'user', 'user_id' => $this->hr->id]], 'channels' => ['in_app'], 'notification_template_id' => $template->id]);

    $anniversaries = app(Anniversaries::class);
    expect($anniversaries->on('work_anniversary', Carbon::parse('2026-11-14'))->pluck('id')->all())->toBe([$this->asha->id])
        // 29 February joiners are celebrated on 28 February outside leap years.
        ->and($anniversaries->on('work_anniversary', Carbon::parse('2027-02-28'))->pluck('id')->all())->toBe([$this->other->id]);

    hp9aAt('2026-11-14 06:30');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    $to = fn (string $event, Employee|int $who) => NotificationDelivery::query()->where('event', $event)->where('user_id', $who instanceof Employee ? $who->user_id : $who);

    expect($to('celebration.work_anniversary.greeting', $this->asha)->value('subject'))->toBe('Happy work anniversary, '.$this->asha->person->first_name.'!')
        ->and($to('celebration.work_anniversary.greeting', $this->asha)->value('body'))->toContain('3 years')->toContain($this->tenant->name)
        ->and($to('celebration.work_anniversary.today', $this->manager)->value('subject'))->toBe('It’s '.$this->asha->person->display_name.'’s work anniversary today (3 years)')
        ->and($to('celebration.work_anniversary.today', $this->ravi)->exists())->toBeTrue()
        ->and($to('celebration.work_anniversary.greeting', $this->ravi)->exists())->toBeFalse()
        ->and($to('employee.work_anniversary', $this->hr->id)->value('subject'))->toBe('Cheers '.$this->asha->person->display_name);

    $before = NotificationDelivery::query()->count();
    hp9aAt('2026-11-14 10:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    expect(NotificationDelivery::query()->count())->toBe($before);
});

it('records wedding anniversaries (day and month), keeps them private unless shared, and lets colleagues wish once per occasion', function () {
    expect(fn () => $this->asha->person->update(['wedding_anniversary_month' => 4, 'wedding_anniversary_day' => 31]))->toThrow(InvalidArgumentException::class);
    $this->asha->person->refresh()->update(['wedding_anniversary_month' => 11, 'wedding_anniversary_day' => null]);
    expect($this->asha->person->refresh()->wedding_anniversary_month)->toBeNull();

    $this->asha->person->update(['wedding_anniversary_month' => 11, 'wedding_anniversary_day' => 14, 'date_of_birth' => '1994-11-14']);
    expect($this->asha->person->refresh()->wedding_anniversary_shared)->toBeFalse()->and($this->asha->person->weddingAnniversaryLabel())->toBe('14 November');

    $this->settings->set('celebration.wedding_anniversary.enabled', true);
    hp9aAt('2026-11-14 10:00');
    $anniversaries = app(Anniversaries::class);
    $today = app(Birthdays::class)->today();

    // Private: HR sees it, colleagues do not; the employee is still greeted, nobody is nudged.
    expect($anniversaries->on('wedding_anniversary', $today, $this->hr)->pluck('id')->all())->toBe([$this->asha->id])
        ->and($anniversaries->on('wedding_anniversary', $today, $this->ravi->user))->toHaveCount(0)
        ->and(fn () => $anniversaries->wish('wedding_anniversary', $this->ravi->user, $this->asha, 'Congrats'))->toThrow(RuntimeException::class, 'not shared');
    $counts = $anniversaries->sendDayNotices($today);
    expect($counts['wedding_anniversary'])->toMatchArray(['celebrants' => 1, 'greetings' => 1, 'nudges' => 0]);

    // Shared: the team-mate wishes once; a birthday wish the same day is a separate wish.
    $this->asha->person->update(['wedding_anniversary_shared' => true]);
    $wish = $anniversaries->wish('wedding_anniversary', $this->ravi->user, $this->asha->refresh(), '<i>Happy anniversary!</i>');
    expect($wish->kind)->toBe('wedding_anniversary')->and($wish->message)->toBe('Happy anniversary!')
        ->and(fn () => $anniversaries->wish('wedding_anniversary', $this->ravi->user, $this->asha, 'Again'))->toThrow(RuntimeException::class, 'already wished')
        ->and(fn () => $anniversaries->wish('wedding_anniversary', $this->asha->user, $this->asha, 'Me'))->toThrow(RuntimeException::class, 'yourself');
    app(Birthdays::class)->wish($this->ravi->user, $this->asha, 'Happy birthday too');
    expect(BirthdayWish::query()->where('employee_id', $this->asha->id)->pluck('kind')->sort()->values()->all())->toBe(['birthday', 'wedding_anniversary'])
        ->and(app(Birthdays::class)->wishesFor($this->asha, 2026))->toHaveCount(1)
        ->and($anniversaries->wishesFor('wedding_anniversary', $this->asha, 2026)->pluck('id')->all())->toBe([$wish->id])
        ->and(NotificationDelivery::query()->where('event', 'celebration.wedding_anniversary.wish')->where('user_id', $this->asha->user_id)->exists())->toBeTrue();

    // Switched off again: no wishes.
    $this->settings->set('celebration.wedding_anniversary.enabled', false);
    expect(fn () => $anniversaries->wish('wedding_anniversary', $this->other->user, $this->asha, 'Hi'))->toThrow(RuntimeException::class, 'does not celebrate');
});

it('records the wedding anniversary from Edit profile', function () {
    $this->actingAs(tenantUser($this->tenant, ['*']));

    Livewire::test(EditEmployee::class, ['record' => $this->asha->getRouteKey()])
        ->fillForm(['person.wedding_anniversary_month' => 2, 'person.wedding_anniversary_day' => 14, 'person.wedding_anniversary_shared' => true, 'audit_reason' => 'Recorded on request'])
        ->call('save')->assertHasNoFormErrors();

    expect($this->asha->person->refresh()->weddingAnniversaryLabel())->toBe('14 February')->and($this->asha->person->wedding_anniversary_shared)->toBeTrue();
});

it('sends the day’s list to the roles chosen for birthdays and anniversaries', function () {
    $role = Role::query()->where('slug', 'hr-executive')->firstOrFail();
    $exec = tenantUser($this->tenant, ['celebration.view']);
    $exec->roles()->attach($role);
    $this->settings->set('celebration.birthdays.notify_roles', ['hr-executive']);
    $this->settings->set('celebration.work_anniversary.enabled', true);
    $this->settings->set('celebration.work_anniversary.notify_roles', ['hr-executive']);
    $this->asha->person->update(['date_of_birth' => '1994-11-14']);
    hp9aJoined($this->ravi, '2021-11-14');

    hp9aAt('2026-11-14 07:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();

    $to = fn (string $event) => NotificationDelivery::query()->where('event', $event)->where('user_id', $exec->id);
    expect($to('celebration.birthday.today')->value('subject'))->toBe('Birthday today: '.$this->asha->person->display_name)
        ->and($to('celebration.work_anniversary.today')->value('body'))->toContain($this->ravi->person->display_name.' ('.$this->ravi->employee_code.'), 5 years');

    // Without the setting nobody outside the team and celebration managers is told (as before).
    expect(NotificationDelivery::query()->where('event', 'celebration.birthday.today')->where('user_id', $this->other->user_id)->exists())->toBeFalse();
});

it('turns Birthdays into Celebrations with a tab per occasion, wishes on Home and a message preview', function () {
    $this->settings->set('celebration.work_anniversary.enabled', true);
    $this->settings->set('celebration.work_anniversary.greeting_message', 'Well done {name}: {years} at {organisation}!');
    $this->settings->set('experience.welcome.message', 'Hello {name}, welcome to {organisation}.');
    hp9aJoined($this->asha, '2024-11-14');
    hp9aAt('2026-11-14 10:00');

    $this->actingAs($this->other->user);
    expect(BirthdaysPage::getNavigationLabel())->toBe('Celebrations');
    Livewire::test(BirthdaysPage::class)->assertSee('Work anniversaries')
        ->call('selectKind', 'work_anniversary')->assertSet('kind', 'work_anniversary')->assertSee($this->asha->person->display_name)->assertSee('2 years')
        ->callAction('wishAnniversary', ['message' => 'Two years already!'], ['employee' => $this->asha->id, 'kind' => 'work_anniversary'])
        ->assertNotified('Wishes sent to '.$this->asha->person->first_name);
    expect(BirthdayWish::query()->where('kind', 'work_anniversary')->where('employee_id', $this->asha->id)->value('message'))->toBe('Two years already!');

    $this->actingAs($this->ravi->user);
    Livewire::test(Home::class)->assertSee('Celebrations today')->assertSee('2 years work anniversary')
        ->callAction('wishAnniversary', ['message' => 'Congrats'], ['employee' => $this->asha->id, 'kind' => 'work_anniversary'])->assertNotified();
    expect(BirthdayWish::query()->where('kind', 'work_anniversary')->count())->toBe(2);

    // Celebration managers preview every message as employees receive it.
    $this->actingAs($this->hr);
    $page = Livewire::test(BirthdaysPage::class)->assertActionVisible('preview');
    $messages = collect($page->instance()->previewMessages())->pluck('text', 'label');
    expect($messages['Work anniversary greeting'])->toBe('Well done '.strtok($this->hr->name, ' ').': 3 years at '.$this->tenant->name.'!')
        ->and($messages['Welcome at first sign-in'])->toBe('Hello '.strtok($this->hr->name, ' ').', welcome to '.$this->tenant->name.'.')
        ->and($messages)->toHaveKey('Birthday greeting');
    $this->actingAs($this->other->user);
    Livewire::test(BirthdaysPage::class)->assertActionHidden('preview');
});

it('lets the organisation write or switch off the first-login welcome', function () {
    $this->actingAs($this->asha->user);
    $this->settings->set('experience.welcome.message', 'Namaste {name}!');
    Livewire::test(Home::class)->assertSee('Namaste '.$this->asha->person->first_name.'!');

    $this->settings->set('experience.welcome.enabled', false);
    expect(Livewire::test(Home::class)->instance()->showWelcome())->toBeFalse();
});

it('gives handbook acknowledgements a due date, reminds from it and lists who acknowledged', function () {
    $this->travelTo('2026-10-01 09:00:00');
    $this->asha->user->roles()->first()->permissions()->syncWithoutDetaching(app(PermissionRegistry::class)->idsMatching(['kb.view', 'task.view']));
    $kb = app(KnowledgeBase::class);
    $article = Article::create(['title' => 'Code of conduct', 'category' => 'conduct', 'body' => 'Be kind.', 'requires_acknowledgement' => true]);
    kbPublishForTests($article);
    $article->refresh();

    // Default: no due date on the task; reminders after the service desk's 7 days.
    $task = app(PolicyAcknowledgementTaskSource::class)->tasksFor($this->asha->user->fresh(), $this->asha)->first();
    expect($task->dueAt)->toBeNull();

    $this->settings->set('kb.acknowledgement.due_days', 10);
    $task = app(PolicyAcknowledgementTaskSource::class)->tasksFor($this->asha->user->fresh(), $this->asha)->first();
    expect($task->dueAt->toDateString())->toBe('2026-10-11')->and($kb->reminderDays())->toBe(10)
        ->and($kb->hasAcknowledgementsDue($kb->reminderDays()))->toBeFalse();
    $this->travelTo('2026-10-12 09:00:00');
    expect($kb->hasAcknowledgementsDue($kb->reminderDays()))->toBeTrue();

    $kb->acknowledge($article, $this->asha);
    $kb->recordRead($article, $this->ravi);
    $rows = $kb->acknowledgementList($article)->keyBy(fn (array $r) => $r['employee']->id);
    expect($rows[$this->asha->id]['acknowledged_at'])->not->toBeNull()
        ->and($rows[$this->ravi->id]['read_at'])->not->toBeNull()->and($rows[$this->ravi->id]['acknowledged_at'])->toBeNull()
        ->and($rows[$this->other->id]['read_at'])->toBeNull()
        ->and($kb->acknowledgementList($article)->first()['employee']->id)->toBe($this->asha->id);

    $writer = tenantUser($this->tenant, ['kb.manage', 'kb.view']);
    $this->actingAs($writer);
    Livewire::test(ListArticles::class)->assertTableActionVisible('acknowledgements', $article)
        ->callTableAction('acknowledgementsCsv', $article)->assertFileDownloaded('acknowledgements-code-of-conduct-v1.csv');
});

it('filters handbook articles by created and published dates', function () {
    $this->travelTo('2026-08-01 09:00:00');
    $old = Article::create(['title' => 'Old policy', 'category' => 'conduct', 'body' => 'Old.']);
    kbPublishForTests($old);
    $this->travelTo('2026-10-01 09:00:00');
    $new = Article::create(['title' => 'New policy', 'category' => 'conduct', 'body' => 'New.']);
    kbPublishForTests($new);

    $this->actingAs(tenantUser($this->tenant, ['kb.manage', 'kb.view']));
    Livewire::test(ListArticles::class)
        ->filterTable('created', ['created_from' => '2026-09-01'])->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old])
        ->resetTableFilters()
        ->filterTable('published', ['published_until' => '2026-08-31'])->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$new]);
});

it('lets readers download the published version only when the organisation allows it, audited', function () {
    $this->asha->user->roles()->first()->permissions()->syncWithoutDetaching(app(PermissionRegistry::class)->idsMatching(['kb.view']));
    $article = Article::create(['title' => 'Travel policy', 'category' => 'conduct', 'body' => "# Travel\n\n<script>alert(1)</script> Book early."]);
    kbPublishForTests($article);
    $article->refresh();
    $kb = app(KnowledgeBase::class);
    $reader = $this->asha->user->fresh();

    $this->actingAs($reader);
    Livewire::test(ViewArticle::class, ['record' => $article->getRouteKey()])->assertActionHidden('download');
    expect(fn () => $kb->download($article, $reader, $this->asha))->toThrow(RuntimeException::class, 'cannot be downloaded');

    $this->settings->set('kb.download_allowed', true);
    $file = $kb->download($article, $reader, $this->asha);
    expect($file['name'])->toBe('travel-policy-v1.html')->and($file['content'])->toContain('Book early')->not->toContain('<script>')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Download->value)->where('module', 'kb')->exists())->toBeTrue();
    Livewire::test(ViewArticle::class, ['record' => $article->getRouteKey()])->assertActionVisible('download')
        ->callAction('download')->assertFileDownloaded('travel-policy-v1.html');

    // Outside the audience: refused.
    $article->forceFill(['audience' => [['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'probation']]])->saveQuietly();
    expect(fn () => $kb->download($article->refresh(), $reader, $this->asha))->toThrow(RuntimeException::class, 'not addressed to you');
    expect(ArticleResource::canAccess())->toBeTrue();
});

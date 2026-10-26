<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\People\Models\BirthdayWish;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\Birthdays as BirthdaysPage;
use App\Filament\Pages\Home;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| Birthdays: a person may celebrate on another day than the date of birth on their papers. Colleagues see shared
| birthdays (day and month only) and wish them on the day; HR (celebration.manage) sees everyone, gets the month's
| list ahead of time and the day's list each morning. The daily run works on the tenant's own clock (Asia/Kolkata here).
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['employee.view', 'celebration.view', 'celebration.manage']);
    $this->manager = employeeWithUser(null, ['celebration.view']);
    $this->asha = employeeWithUser($this->manager, ['celebration.view']);
    $this->ravi = employeeWithUser($this->manager, ['celebration.view']);
    $this->other = employeeWithUser(null, ['celebration.view']);
});

function bornOn(Employee $employee, string $dob, ?int $month = null, ?int $day = null, bool $shared = true): Employee
{
    $employee->person->update(['date_of_birth' => $dob, 'birthday_month' => $month, 'birthday_day' => $day, 'birthday_shared' => $shared]);

    return $employee->refresh();
}

function atTenantTime(string $local): void
{
    test()->travelTo(Carbon::parse($local, 'Asia/Kolkata'));
}

it('celebrates on the birthday recorded for the person, else on the date of birth', function () {
    bornOn($this->asha, '1994-02-14');
    expect($this->asha->person->birthday_key)->toBe(214)->and($this->asha->person->birthdayLabel())->toBe('14 February')
        ->and($this->asha->person->celebratesOtherDay())->toBeFalse();

    // Papers say 14 February; she celebrates on 3 March.
    bornOn($this->asha, '1994-02-14', 3, 3);
    expect($this->asha->person->birthday_key)->toBe(303)->and($this->asha->person->celebratesOtherDay())->toBeTrue();

    // Clearing the month clears the day, and the date of birth applies again.
    $this->asha->person->update(['birthday_month' => null]);
    expect($this->asha->person->refresh()->birthday_day)->toBeNull()->and($this->asha->person->birthday_key)->toBe(214);

    expect(fn () => $this->asha->person->update(['birthday_month' => 2, 'birthday_day' => 30]))->toThrow(InvalidArgumentException::class);
});

it('records the celebrated birthday from Edit profile', function () {
    $this->actingAs(tenantUser($this->tenant, ['*']));
    bornOn($this->asha, '1994-02-14');

    Livewire::test(EditEmployee::class, ['record' => $this->asha->getRouteKey()])
        ->fillForm(['person.birthday_month' => 3, 'person.birthday_day' => 3, 'person.birthday_shared' => false, 'audit_reason' => 'Celebrates on another day'])
        ->call('save')->assertHasNoFormErrors();

    expect($this->asha->person->refresh()->birthday_key)->toBe(303)->and($this->asha->person->birthday_shared)->toBeFalse()
        ->and($this->asha->person->date_of_birth->toDateString())->toBe('1994-02-14');
});

it('shows colleagues shared birthdays only, and HR everyone', function () {
    atTenantTime('2026-11-14 10:00');
    bornOn($this->asha, '1994-11-14');
    bornOn($this->ravi, '1990-11-14', shared: false);
    bornOn($this->other, '1992-11-20');
    $birthdays = app(Birthdays::class);
    $today = $birthdays->today();

    expect($birthdays->on($today, $this->manager->user)->pluck('id')->all())->toBe([$this->asha->id])
        ->and($birthdays->on($today, $this->hr)->pluck('id')->sort()->values()->all())->toBe([$this->asha->id, $this->ravi->id])
        ->and($birthdays->inMonth(2026, 11, $this->other->user)->pluck('id')->all())->toBe([$this->asha->id, $this->other->id])
        ->and($birthdays->upcoming($today, 14, $this->asha->user)->pluck('id')->all())->toBe([$this->other->id]);

    // Team-only visibility: the other employee no longer sees Asha.
    app(SettingsRepository::class)->set('celebration.birthdays.visible_to', 'team');
    expect($birthdays->on($today, $this->other->user))->toHaveCount(0)
        ->and($birthdays->on($today, $this->ravi->user)->pluck('id')->all())->toBe([$this->asha->id]);
});

it('celebrates 29 February on 28 February outside leap years', function () {
    bornOn($this->asha, '1996-02-29');
    $birthdays = app(Birthdays::class);

    expect($birthdays->on(Carbon::parse('2027-02-28'))->pluck('id')->all())->toBe([$this->asha->id])
        ->and($birthdays->on(Carbon::parse('2028-02-28')))->toHaveCount(0)
        ->and($birthdays->on(Carbon::parse('2028-02-29'))->pluck('id')->all())->toBe([$this->asha->id]);
});

it('lets colleagues wish on the day, once a year, never themselves', function () {
    atTenantTime('2026-11-14 10:00');
    bornOn($this->asha, '1994-11-14');
    bornOn($this->ravi, '1990-11-15');
    $birthdays = app(Birthdays::class);

    $wish = $birthdays->wish($this->other->user, $this->asha, '<b>Happy birthday</b>, Asha!');

    expect($wish->message)->toBe('Happy birthday, Asha!')
        ->and(NotificationDelivery::query()->where('event', 'celebration.birthday.wish')->where('user_id', $this->asha->user_id)->value('body'))->toBe('Happy birthday, Asha!')
        ->and($birthdays->wishesFor($this->asha, 2026)->pluck('id')->all())->toBe([$wish->id])
        ->and(fn () => $birthdays->wish($this->other->user, $this->asha, 'Again'))->toThrow(RuntimeException::class, 'already wished')
        ->and(fn () => $birthdays->wish($this->asha->user, $this->asha, 'Me'))->toThrow(RuntimeException::class, 'yourself')
        ->and(fn () => $birthdays->wish($this->other->user, $this->ravi, 'Early'))->toThrow(RuntimeException::class, 'birthday itself');

    // A private birthday cannot be wished by colleagues.
    bornOn($this->asha, '1994-11-14', shared: false);
    expect(fn () => $birthdays->wish($this->ravi->user, $this->asha, 'Hi'))->toThrow(RuntimeException::class, 'not shared');
});

it('greets the employee, reminds their team and gives HR the day’s list, once a day after 06:00 on the tenant’s clock', function () {
    bornOn($this->asha, '1994-11-14');
    bornOn($this->ravi, '1990-11-14', shared: false);

    atTenantTime('2026-11-14 05:30');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    expect(NotificationDelivery::query()->where('event', 'like', 'celebration.%')->count())->toBe(0);

    atTenantTime('2026-11-14 06:10');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();

    $to = fn (string $event, Employee|int $who) => NotificationDelivery::query()->where('event', $event)->where('user_id', $who instanceof Employee ? $who->user_id : $who);
    expect($to('celebration.birthday.greeting', $this->asha)->where('channel', 'in_app')->value('subject'))->toBe('Happy birthday, '.$this->asha->person->first_name.'!')
        ->and($to('celebration.birthday.greeting', $this->asha)->where('channel', 'email')->exists())->toBeTrue()
        ->and($to('celebration.birthday.greeting', $this->ravi)->exists())->toBeTrue()
        // Asha's manager and team-mate are reminded; nobody is reminded about Ravi's private birthday.
        ->and($to('celebration.birthday.today', $this->manager)->pluck('subject')->all())->toBe(['It’s '.$this->asha->person->display_name.'’s birthday today'])
        ->and($to('celebration.birthday.today', $this->ravi)->count())->toBe(1)
        ->and($to('celebration.birthday.today', $this->asha)->exists())->toBeFalse()
        ->and($to('celebration.birthday.today', $this->other)->exists())->toBeFalse()
        // HR gets the day's list, private birthdays included and marked.
        ->and($to('celebration.birthday.today', $this->hr->id)->value('subject'))->toBe('Birthdays today: 2 people')
        ->and($to('celebration.birthday.today', $this->hr->id)->value('body'))->toContain($this->ravi->person->display_name.' ('.$this->ravi->employee_code.', private)');

    $before = NotificationDelivery::query()->count();
    atTenantTime('2026-11-14 09:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    expect(NotificationDelivery::query()->count())->toBe($before);
});

it('fires employee.birthday for the tenant’s own notification rules', function () {
    bornOn($this->asha, '1994-11-14');
    $template = NotificationTemplate::create(['key' => 'bday', 'name' => 'Birthday', 'subject' => 'Cake for {{ employee.name }}', 'body' => 'x']);
    NotificationRule::create(['name' => 'Birthday', 'event' => 'employee.birthday', 'audience' => [['type' => 'user', 'user_id' => $this->hr->id]], 'channels' => ['in_app'], 'notification_template_id' => $template->id]);

    atTenantTime('2026-11-14 07:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();

    expect(NotificationDelivery::query()->where('event', 'employee.birthday')->where('user_id', $this->hr->id)->value('subject'))->toBe('Cake for '.$this->asha->person->display_name);
});

it('sends HR the month’s list on the 1st, or the configured days before', function () {
    bornOn($this->asha, '1994-12-03');
    bornOn($this->ravi, '1990-12-25', shared: false);
    bornOn($this->other, '1992-11-30');
    $list = fn () => NotificationDelivery::query()->where('event', 'celebration.birthday.month')->where('user_id', $this->hr->id)->where('channel', 'in_app');

    // Mid-month: nothing (the list for November went out on the 1st; a new deployment does not send a stale one).
    atTenantTime('2026-11-20 07:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    expect($list()->count())->toBe(0);

    atTenantTime('2026-12-01 07:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    expect($list()->value('subject'))->toBe('Birthdays in December 2026: 2 people')
        ->and($list()->value('body'))->toContain('Thu 3 Dec — '.$this->asha->person->display_name)->toContain('Fri 25 Dec — '.$this->ravi->person->display_name)
        ->and($list()->value('body'))->not->toContain($this->other->person->display_name)
        // Email lists one person per line.
        ->and(NotificationDelivery::query()->where('event', 'celebration.birthday.month')->where('user_id', $this->hr->id)->where('channel', 'email')->value('body'))
        ->toContain('Thu 3 Dec — '.$this->asha->person->display_name.' ('.$this->asha->employee_code.")\nFri 25 Dec — ".$this->ravi->person->display_name)
        // Employees never receive the list.
        ->and(NotificationDelivery::query()->where('event', 'celebration.birthday.month')->where('user_id', $this->manager->user_id)->exists())->toBeFalse();

    // Five days ahead: January's list goes out on 27 December, once.
    app(SettingsRepository::class)->set('celebration.birthdays.digest_lead_days', 5);
    bornOn($this->asha, '1994-01-09');
    atTenantTime('2026-12-27 07:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    atTenantTime('2026-12-28 07:00');
    $this->artisan('peopleos:birthdays:send')->assertSuccessful();
    expect($list()->where('subject', 'like', 'Birthdays in January 2027%')->count())->toBe(1);
});

it('lets an employee wish a colleague from the Birthdays page and Home, and set their own birthday', function () {
    atTenantTime('2026-11-14 10:00');
    bornOn($this->asha, '1994-11-14');
    $this->actingAs($this->other->user);

    $this->get(BirthdaysPage::getUrl())->assertOk()->assertSee($this->asha->person->display_name)->assertSee('Send wishes');
    Livewire::test(BirthdaysPage::class)->callAction('wish', ['message' => 'Have a great day!'], ['employee' => $this->asha->id])->assertNotified('Wishes sent to '.$this->asha->person->first_name);
    expect(BirthdayWish::query()->where('employee_id', $this->asha->id)->value('message'))->toBe('Have a great day!');

    $this->actingAs($this->ravi->user);
    Livewire::test(Home::class)->assertSee('Birthdays today')
        ->callAction('wish', ['message' => 'Many happy returns'], ['employee' => $this->asha->id])->assertNotified();
    expect(BirthdayWish::query()->where('employee_id', $this->asha->id)->count())->toBe(2);

    // Asha sees her wishes, and moves her celebrated birthday (her date of birth is unchanged).
    $this->actingAs($this->asha->user);
    $this->get(BirthdaysPage::getUrl())->assertOk()->assertSee('Wishes for you')->assertSee('Many happy returns');
    Livewire::test(BirthdaysPage::class)->callAction('myBirthday', ['birthday_month' => 3, 'birthday_day' => 3, 'birthday_shared' => false])->assertHasNoActionErrors();
    expect($this->asha->person->refresh()->birthday_key)->toBe(303)->and($this->asha->person->date_of_birth->toDateString())->toBe('1994-11-14');
});

it('keeps set-up and the month download to people who manage birthdays', function () {
    atTenantTime('2026-11-14 10:00');
    bornOn($this->asha, '1994-11-14');

    $this->actingAs($this->other->user);
    Livewire::test(BirthdaysPage::class)->assertActionHidden('settings')->assertActionHidden('download');

    $this->actingAs($this->hr);
    Livewire::test(BirthdaysPage::class)
        ->callAction('settings', ['visible_to' => 'company', 'announce_to' => 'manager', 'greeting_enabled' => false, 'greeting_message' => 'Hi {name}', 'digest_enabled' => true, 'digest_lead_days' => 3, 'notify_admins_on_day' => false, 'email' => false])
        ->assertHasNoActionErrors();
    $settings = app(SettingsRepository::class);
    expect($settings->get('celebration.birthdays.visible_to'))->toBe('company')->and($settings->get('celebration.birthdays.digest_lead_days'))->toBe(3)->and($settings->get('celebration.birthdays.greeting_enabled'))->toBeFalse();

    Livewire::test(BirthdaysPage::class)->callAction('download')->assertFileDownloaded('birthdays-2026-11.csv');

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(BirthdaysPage::getUrl())->assertForbidden();
});

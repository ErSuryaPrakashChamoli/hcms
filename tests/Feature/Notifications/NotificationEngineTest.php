<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Notifications\Channels\Channel;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Services\AudienceResolver;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\TemplateRenderer;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = employeeWithUser();
    $this->employee = employeeWithUser($this->manager);
});

it('renders templates from the context tree', function () {
    $context = app(NotificationContext::class)->build($this->employee, ['lifecycle' => ['to' => 'Confirmed']], $this->hr);

    $out = app(TemplateRenderer::class)->render('Hi {{ employee.first_name }} ({{ employee.code }}), manager {{ employee.manager }}, by {{ initiator.name }}: {{ lifecycle.to }} {{ missing.path }} {{ employee.joining_date }}', $context);

    expect($out)->toBe(sprintf('Hi %s (%s), manager %s, by %s: Confirmed  01 Jan 2025', $this->employee->person->first_name, $this->employee->employee_code, $this->manager->person->display_name, $this->hr->name));
});

it('resolves audiences to users', function () {
    $role = Role::query()->where('slug', 'hr-manager')->first();
    $hrUser = tenantUser($this->tenant, []);
    $hrUser->roles()->attach($role);
    $context = app(NotificationContext::class)->build($this->employee, [], $this->hr);

    $users = app(AudienceResolver::class)->resolve([['type' => 'subject'], ['type' => 'manager'], ['type' => 'initiator'], ['type' => 'role', 'role_id' => $role->id], ['type' => 'user', 'user_id' => $hrUser->id]], $context);

    expect($users->pluck('id')->all())->toEqualCanonicalizing([$this->employee->user_id, $this->manager->user_id, $this->hr->id, $hrUser->id]);
});

it('fires rules on domain events through every configured channel', function () {
    Mail::fake();
    $template = NotificationTemplate::create(['key' => 'confirmed', 'name' => 'Confirmed', 'subject' => '{{ employee.name }} confirmed', 'body' => 'Effective {{ lifecycle.effective_date }}. Reason: {{ lifecycle.reason }}']);
    NotificationRule::create(['name' => 'Tell them', 'event' => 'employee.confirmed', 'audience' => [['type' => 'subject'], ['type' => 'manager']], 'channels' => ['in_app', 'email', 'sms'], 'notification_template_id' => $template->id]);
    NotificationRule::create(['name' => 'Only sales', 'event' => 'employee.confirmed', 'audience' => [['type' => 'initiator']], 'channels' => ['in_app'], 'notification_template_id' => $template->id, 'conditions' => [['field' => 'employee.department', 'operator' => 'equals', 'value' => 'Sales']]]);

    app(LifecycleEngine::class)->transition($this->employee, LifecycleState::Confirmed, '2026-09-01', 'Cleared');

    $deliveries = NotificationDelivery::query()->where('event', 'employee.confirmed')->get();

    expect($deliveries)->toHaveCount(6)
        ->and($deliveries->where('user_id', $this->hr->id))->toBeEmpty()
        ->and($deliveries->first()->subject)->toBe($this->employee->person->display_name.' confirmed')
        ->and($deliveries->first()->body)->toBe('Effective 01 Sep 2026. Reason: Cleared')
        ->and($deliveries->where('status', 'sent'))->toHaveCount(6);

    Mail::assertSent(NotificationMail::class, 2);
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($this->employee->user->email));

    expect($this->employee->user->notifications()->count())->toBe(1)
        ->and($this->employee->user->notifications()->first()->data['title'])->toBe($this->employee->person->display_name.' confirmed');
});

it('records failed deliveries instead of breaking the business action', function () {
    config()->set('peopleos.notifications.channels.email.driver', BrokenChannel::class);
    $template = NotificationTemplate::create(['key' => 't', 'name' => 'T', 'subject' => 'S', 'body' => 'B']);
    NotificationRule::create(['name' => 'r', 'event' => 'employee.confirmed', 'audience' => [['type' => 'subject']], 'channels' => ['email'], 'notification_template_id' => $template->id]);

    app(LifecycleEngine::class)->transition($this->employee, LifecycleState::Confirmed);

    $delivery = NotificationDelivery::query()->first();
    expect($this->employee->fresh()->lifecycle_state)->toBe(LifecycleState::Confirmed)
        ->and($delivery->status)->toBe('failed')
        ->and($delivery->error)->toBe('SMTP down');
});

it('ignores rules whose template or rule is inactive', function () {
    $template = NotificationTemplate::create(['key' => 't', 'name' => 'T', 'subject' => 'S', 'body' => 'B', 'status' => 'inactive']);
    NotificationRule::create(['name' => 'r', 'event' => 'employee.confirmed', 'audience' => [['type' => 'subject']], 'channels' => ['in_app'], 'notification_template_id' => $template->id]);

    app(LifecycleEngine::class)->transition($this->employee, LifecycleState::Confirmed);

    expect(NotificationDelivery::query()->count())->toBe(0);
});

final class BrokenChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        throw new RuntimeException('SMTP down');
    }
}

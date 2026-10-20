<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\NotificationRecovery;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| SaaS.8: a delivery never waits forever. Stuck in `sending` (worker lost) → failed with an unknown outcome, never resent
| automatically; left `queued` (job lost) → dispatched again; a failed delivery can be sent again by an administrator
| holding notification.resend, with a reason, once.
*/

function delivery(array $attributes = []): NotificationDelivery
{
    return NotificationDelivery::query()->create($attributes + ['user_id' => test()->recipient->id, 'channel' => 'email', 'event' => 'ops.test',
        'subject' => 'Payslip ready', 'body' => 'Your payslip is ready.', 'status' => 'queued', 'recipient' => 'person@acme.test']);
}

function age(NotificationDelivery $delivery, int $minutes): NotificationDelivery
{
    NotificationDelivery::query()->whereKey($delivery->id)->update(['updated_at' => now()->subMinutes($minutes)]);

    return $delivery->refresh();
}

beforeEach(function () {
    $this->tenant = provisionTenant('Acme');
    actAsTenant($this->tenant);
    $this->recipient = tenantUser($this->tenant, ['task.view']);
    $this->admin = tenantUser($this->tenant, ['notification.deliveries', 'notification.resend']);
    $this->viewer = tenantUser($this->tenant, ['notification.deliveries']);
    $this->recovery = app(NotificationRecovery::class);
});

it('marks a delivery stuck in sending past the lease as failed with an unknown outcome, and never resends it by itself', function () {
    Queue::fake();
    $stuck = age(delivery(['status' => 'sending']), 20);
    $fresh = age(delivery(['status' => 'sending']), 5);

    expect($this->recovery->recover())->toBe(['stale_sending' => 1, 'requeued' => 0])
        ->and($stuck->fresh()->status)->toBe('failed')
        ->and($stuck->fresh()->error)->toBe(NotificationRecovery::UNKNOWN_OUTCOME)
        ->and($fresh->fresh()->status)->toBe('sending');
    Queue::assertNothingPushed();
});

it('dispatches again an async delivery whose job was lost, once per window, and leaves fresh and in-app ones alone', function () {
    Queue::fake();
    $lost = age(delivery(), 90);
    age(delivery(), 10);
    age(delivery(['channel' => 'in_app', 'recipient' => null]), 90);

    expect($this->recovery->recover())->toBe(['stale_sending' => 0, 'requeued' => 1])
        ->and($this->recovery->recover())->toBe(['stale_sending' => 0, 'requeued' => 0]);
    Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->deliveryId === $lost->id && $job->tenantId === $this->tenant->id);
    Queue::assertPushed(DeliverNotification::class, 1);
});

it('sends a failed delivery again for a permitted administrator, with a reason, once, audited', function () {
    Mail::fake();
    $failed = delivery(['status' => 'failed', 'error' => 'SMTP 451']);

    $this->recovery->resend($failed, $this->admin, 'Mail server was down');

    expect($failed->fresh()->status)->toBe('sent')->and($failed->fresh()->resend_count)->toBe(1)->and($failed->fresh()->error)->toBeNull();
    Mail::assertSent(NotificationMail::class, 1);
    $event = AuditEvent::query()->where('action', 'NOTIFICATION_RESENT')->sole();
    expect($event->actor_id)->toBe($this->admin->id)->and($event->reason)->toBe('Mail server was down')
        ->and($event->tenant_id)->toBe($this->tenant->id)
        ->and(json_encode($event->metadata))->not->toContain('Payslip');

    // A second click (or a second administrator) finds it no longer failed.
    expect(fn () => $this->recovery->resend($failed->fresh(), $this->admin, 'Mail server was down'))->toThrow(RuntimeException::class);
    Mail::assertSent(NotificationMail::class, 1);
});

it('refuses a resend without the permission, without a reason, for a delivery that has not failed, or from another tenant', function () {
    Mail::fake();
    $failed = delivery(['status' => 'failed']);

    expect(fn () => $this->recovery->resend($failed, $this->viewer, 'I can see the log'))->toThrow(RuntimeException::class, 'cannot send')
        ->and(fn () => $this->recovery->resend($failed, $this->admin, 'no'))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $this->recovery->resend(delivery(['status' => 'sent']), $this->admin, 'Again please'))->toThrow(RuntimeException::class, 'cannot send');

    $other = provisionTenant('Other');
    actAsTenant($other);
    $otherAdmin = tenantUser($other, ['notification.deliveries', 'notification.resend']);
    expect(NotificationDelivery::query()->find($failed->id))->toBeNull()
        ->and(fn () => $this->recovery->resend($failed, $otherAdmin, 'Not my tenant'))->toThrow(RuntimeException::class, 'cannot send');
    actAsTenant($this->tenant);
    expect($failed->fresh()->status)->toBe('failed');
    Mail::assertNothingSent();
});

it('runs the recovery command per tenant and leaves a suspended tenant\'s deliveries for after reactivation', function () {
    Queue::fake();
    age(delivery(['status' => 'sending']), 30);
    actAsTenant(null);
    app(TenantSuspensions::class)->suspend($this->tenant, 'Investigation', platformAdmin());

    $this->artisan('peopleos:notifications:recover')->assertSuccessful();

    actAsTenant($this->tenant);
    expect(NotificationDelivery::query()->where('status', 'sending')->count())->toBe(1);
});

it('offers Send again on the delivery log only to permitted users, on failed deliveries', function () {
    $failed = delivery(['status' => 'failed']);
    $sent = delivery(['status' => 'sent']);

    $this->actingAs($this->admin);
    Livewire::test(ListNotificationDeliveries::class)->assertTableActionVisible('resend', $failed)->assertTableActionHidden('resend', $sent);
    $this->actingAs($this->viewer);
    Livewire::test(ListNotificationDeliveries::class)->assertTableActionHidden('resend', $failed);
});

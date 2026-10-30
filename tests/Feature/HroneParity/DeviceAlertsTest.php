<?php

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Notifications\DeviceSilent;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/* HROne setup parity TO-8: biometric server settings and the e-mail when a device or sync server goes silent. */

beforeEach(function () {
    $this->travelTo('2026-09-23 10:00:00'); // a Wednesday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
});

it('e-mails once when a device sends nothing for longer than its alert window, and again only after it was heard from', function () {
    Notification::fake();
    $device = AttendanceDevice::create(['name' => 'Gate', 'code' => 'GATE', 'adapter' => 'generic', 'status' => 'active', 'last_seen_at' => now()->subHours(5),
        'settings' => ['sync_mode' => 'server', 'server_type' => 'mssql', 'table_name' => 'CheckInOut', 'column_map' => ['USERID' => 'employee_code', 'CHECKTIME' => 'punched_at'],
            'alert_after_hours' => 4, 'alert_emails' => ['it@example.com', 'not-an-address'], 'alert_skip_days' => ['sun']]]);

    $this->artisan('peopleos:attendance:device-alerts')->assertSuccessful();
    $this->artisan('peopleos:attendance:device-alerts')->assertSuccessful();
    Notification::assertSentTimes(DeviceSilent::class, 1);
    Notification::assertSentTo(new AnonymousNotifiable, DeviceSilent::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === ['it@example.com']);
    expect(AuditEvent::query()->where('action', AuditAction::AttendanceDeviceAlerted->value)->count())->toBe(1)
        ->and($device->refresh()->settings['alerted_at'] ?? null)->not->toBeNull();

    app(PunchIngestion::class)->ingest($device, ['punches' => []]);
    $device->update(['last_seen_at' => now()]); // heard from again
    expect($device->refresh()->settings)->not->toHaveKey('alerted_at');

    $this->travelTo('2026-09-23 16:00:00');
    $this->artisan('peopleos:attendance:device-alerts')->assertSuccessful();
    Notification::assertSentTimes(DeviceSilent::class, 2);
});

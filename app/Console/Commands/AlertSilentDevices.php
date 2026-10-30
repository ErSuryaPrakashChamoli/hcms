<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Notifications\DeviceSilent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * HROne setup parity (TO-8): "send an email alert in case of data sync failure". A device whose settings ask for alerts
 * (`alert_after_hours`, `alert_emails`, optionally `alert_skip_days`) and that has sent nothing for longer is reported
 * once per silence (`alerted_at`, cleared when it is seen again); each alert is audited.
 */
class AlertSilentDevices extends Command
{
    protected $signature = 'peopleos:attendance:device-alerts {--tenant=}';

    protected $description = 'E-mail when an attendance device or sync server stops sending punches';

    public function handle(TenantContext $tenants, AuditRecorder $audit): int
    {
        $runner = TenantRunner::for($this);

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants, $audit) {
                $tenants->runAs($tenant, function () use ($tenant, $audit) {
                    $sent = 0;
                    AttendanceDevice::query()->where('status', 'active')->get()->each(function (AttendanceDevice $device) use ($audit, &$sent) {
                        $settings = $device->settings ?? [];
                        $hours = (int) ($settings['alert_after_hours'] ?? 0);
                        $emails = array_values(array_filter((array) ($settings['alert_emails'] ?? []), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
                        if ($hours <= 0 || $emails === [] || in_array(strtolower(now()->format('D')), array_map('strtolower', (array) ($settings['alert_skip_days'] ?? [])), true)) {
                            return;
                        }
                        $silentSince = $device->last_seen_at ?? $device->created_at;
                        $alertedAt = isset($settings['alerted_at']) ? strtotime((string) $settings['alerted_at']) : null;
                        if ($silentSince === null || $silentSince->gt(now()->subHours($hours)) || ($alertedAt !== null && $alertedAt >= $silentSince->getTimestamp())) {
                            return;
                        }
                        try {
                            Notification::route('mail', $emails)->notify(new DeviceSilent($device, $hours));
                        } catch (Throwable) {
                            return; // the transport refused it: try again next run
                        }
                        $device->forceFill(['settings' => ['alerted_at' => now()->toIso8601String()] + $settings])->saveQuietly();
                        $audit->record(AuditAction::AttendanceDeviceAlerted, 'attendance', $device, reason: 'Device silence alert', metadata: ['hours' => $hours, 'recipients' => count($emails), 'last_seen_at' => $device->last_seen_at?->toIso8601String()]);
                        $sent++;
                    });
                    $this->info("{$tenant->slug}: {$sent} device alert(s)");
                });
            }));

        return $runner->exitCode();
    }
}

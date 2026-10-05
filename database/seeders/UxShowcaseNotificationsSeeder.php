<?php

namespace Database\Seeders;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Platform\Models\Tenant;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * UX.17: realistic in-app notifications for the showcase personas, so the notification surfaces have something true to
 * show. Each one describes a record that exists in the showcase (a pending leave request, a missed-punch correction,
 * an HR request, an expiring passport, a proposed configuration change, an announcement) and goes through the real
 * delivery path (Notifier, in-app channel) with the event names the platform emits. Recipients are the people the
 * record concerns. Deep links stay the notification center's own, checked by each record's policy.
 *
 * Disposable *_showcase databases only (or tests); idempotent (one correlation id per run). Every person is fictional.
 */
class UxShowcaseNotificationsSeeder extends Seeder
{
    public const CORRELATION = 'ux-showcase-notifications';

    public function run(TenantContext $tenants): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! app()->environment('testing') && ! str_ends_with($database, '_showcase')) {
            throw new RuntimeException("UxShowcaseNotificationsSeeder only runs on a disposable *_showcase database (current: {$database}).");
        }

        $tenant = $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->first());
        if ($tenant === null) {
            return;
        }
        $tenants->runAs($tenant, fn () => $this->populate());
    }

    private function populate(): void
    {
        if (NotificationDelivery::query()->where('correlation_id', self::CORRELATION)->exists()) {
            return;
        }
        Context::add('request_id', self::CORRELATION);

        $user = fn (string $email): ?User => User::forCurrentTenant()->where('email', $email)->first();
        $employee = fn (string $email): ?Employee => Employee::query()->where('work_email', $email)->first();
        $pending = fn (?Employee $e) => $e?->leaveRequests()->where('status', 'pending')->with('leaveType')->oldest('from_date')->first();
        $range = fn (LeaveRequest $r) => $r->from_date->isSameDay($r->to_date) ? $r->from_date->format('D j M') : $r->from_date->format('j').'–'.$r->to_date->format('j M');

        $amit = $user('amit.verma@demo.local');
        $neha = $user('neha.kapoor@demo.local');
        $kavya = $user('kavya.menon@demo.local');
        $meera = $user('meera.iyer@demo.local');
        $priyaUser = $user('priya.nair@demo.local');
        $priya = $employee('priya.nair@demo.local');
        $rahul = $employee('rahul.sharma@demo.local');
        $leela = $employee('leela.chandran@demo.local');

        // Manager: the decisions waiting for him, in the words of the leave and attendance events.
        foreach ([$priya, $rahul] as $who) {
            if ($amit && ($request = $pending($who))) {
                $this->send($amit, $who->display_name.' requested '.$request->leaveType->name.', '.$range($request), (float) $request->days.' '.((float) $request->days === 1.0 ? 'day' : 'days').'. Decide in the Approval Center.', 'leave.requested', $request);
            }
        }
        $correction = $leela ? AttendanceRegularisation::query()->where('employee_id', $leela->id)->where('status', 'pending')->first() : null;
        if ($amit && $correction) {
            $this->send($amit, $leela->display_name.' asked to correct attendance for '.$correction->date->format('D j M'), 'A missed punch is waiting for your decision.', 'attendance.regularisation_requested', $correction);
        }

        // HR: a new HR request, its SLA, and a passport about to expire (linked to the person, which HR may open).
        $ticket = Ticket::query()->oldest('id')->first();
        if ($neha && $ticket) {
            $ref = $ticket->number.($ticket->service?->name ? ' ('.$ticket->service->name.')' : '');
            $this->send($neha, 'New HR request '.$ref, 'Raised by '.($ticket->employee?->display_name ?? 'an employee').'.', 'servicedesk.ticket.created', $ticket, read: true);
            $this->send($neha, 'SLA approaching: HR request '.$ref, 'The first response is due soon.', 'servicedesk.ticket.sla_warning', $ticket);
        }
        if ($neha && $priya) {
            $this->send($neha, 'Passport for '.$priya->display_name.' expires in 18 days', 'Ask for a renewed copy before it lapses.', 'document.expiring', $priya);
        }

        // Employee: her own passport (her Employee 360 stays closed to her by permission, so the link is withheld).
        if ($priyaUser && $priya) {
            $this->send($priyaUser, 'Your Passport expires in 18 days', 'Upload a renewed copy so your record stays complete.', 'document.expiring', $priya);
        }

        // Administrator: the configuration change she proposed.
        $change = ConfigurationChange::query()->where('reason', 'like', 'UX showcase seed (synthetic)%')->latest('id')->first();
        if ($kavya && $change) {
            $this->send($kavya, 'Configuration change scheduled: Earned leave', 'Carry-forward wording, effective '.($change->effective_from?->format('j M') ?? 'next week').'.', 'configuration.change.proposed', $change);
        }

        // Everyone: the published announcement (already read by most).
        $drill = Announcement::query()->where('title', 'Quarterly fire drill')->first();
        foreach (array_filter([$amit, $neha, $kavya, $meera, $priyaUser]) as $who) {
            if ($drill) {
                $this->send($who, 'New announcement: Quarterly fire drill', 'Register for the drill from Learning.', 'communication.published', $drill, read: $who !== $priyaUser);
            }
        }
    }

    private int $sent = 0;

    private function send(User $user, string $subject, string $body, string $event, Model $source, bool $read = false): void
    {
        $delivery = app(Notifier::class)->send([$user], ['in_app'], $subject, $body, $event, $source)->first();
        // UX.18: each notification gets its own moment, six minutes apart in the order sent, so the list reads the same on
        // every run (with a frozen clock they would all share one second). Found by its delivery, never by "latest".
        $notification = $delivery ? $user->notifications()->where('data->viewData->peopleos->delivery_id', $delivery->id)->first() : null;
        if ($notification === null) {
            return;
        }
        $at = now()->subMinutes(90 - 6 * $this->sent++);
        $notification->forceFill(['created_at' => $at, 'updated_at' => $at, 'read_at' => $read ? $at : null])->save();
    }
}

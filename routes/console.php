<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 14: the scheduler heartbeat (read by /health/ready and peopleos:readiness).
Schedule::command('peopleos:scheduler:heartbeat')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:configuration:publish-due')->dailyAt('00:05')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:workflows:tick')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:lifecycle:reminders')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
// Birthdays: hourly, so each tenant is greeted after 06:00 on its own clock (claimed once per day and once per month).
Schedule::command('peopleos:birthdays:send')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:performance:reminders')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
// HROne setup parity HP-8b: scheduled goal check-in prompts, each tenant at its own hour (claimed once per day).
Schedule::command('peopleos:performance:check-in-prompts')->hourly()->withoutOverlapping()->onOneServer();
// HROne setup parity HP-8h / HP-8i: 360 rounds (close passed windows, automatic launch) and periodic reviews (open / missed).
Schedule::command('peopleos:performance:feedback-rounds')->dailyAt('06:40')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:performance:periodic-reviews')->dailyAt('06:45')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:attendance:process')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:leave:accrue')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
// HROne parity HP-1: unused comp-off lapses after its validity (first in, first out; once per claim).
Schedule::command('peopleos:leave:expire-comp-off')->dailyAt('01:15')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:attendance:absence-alerts')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:attendance:device-alerts')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:lifecycle:confirmation-reviews')->dailyAt('06:15')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:lifecycle:retirement-due')->dailyAt('06:20')->withoutOverlapping()->onOneServer();
// HROne setup parity 08.2: onboarding checklists and induction programmes that start N days before joining.
Schedule::command('peopleos:onboarding:start-due')->dailyAt('06:25')->withoutOverlapping()->onOneServer();
// HROne setup parity AS-A: reminders for assets whose sub type needs the employee's acknowledgement.
Schedule::command('peopleos:assets:acknowledgement-reminders')->dailyAt('08:30')->withoutOverlapping()->onOneServer();
// HROne setup parity EX-C: unreported expenses past the tenant's expense expiry period expire.
Schedule::command('peopleos:expense:expire')->dailyAt('01:25')->withoutOverlapping()->onOneServer();
// HROne setup parity (Security → Disable login): the daily catch-up of the lifecycle-state sign-in rule.
Schedule::command('peopleos:security:login-status')->dailyAt('00:40')->withoutOverlapping()->onOneServer();
// HROne parity SV-7: the nightly catch-up of role membership rules (only the grants the rules made themselves).
Schedule::command('peopleos:access:role-rules')->dailyAt('00:45')->withoutOverlapping()->onOneServer();
// HROne setup parity P4 (Inbox → Archive inbox): automatic archiving of Approval Center items waiting too long.
Schedule::command('peopleos:inbox:archive')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:compliance:sync')->weeklyOn(1, '00:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:learning:tick')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:learning:send-reminders')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:talent:send-reminders')->dailyAt('07:45')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:workforce:send-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
// Phase 11: scheduled compensation changes become effective on their date (each change locked, made effective once).
Schedule::command('peopleos:compensation:effect')->dailyAt('00:20')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:compensation:send-reminders')->dailyAt('08:15')->withoutOverlapping()->onOneServer();
// HROne setup parity BP-9: reminders to claim reimbursements, and managers asked for planned variable pay.
Schedule::command('peopleos:payroll:claim-reminders')->dailyAt('08:40')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:payroll:variable-pay-tasks')->dailyAt('08:45')->withoutOverlapping()->onOneServer();
// HROne setup parity HP-9d: reward points credited per the reward points policies (cycle credits, lapses, expiry; off by default).
Schedule::command('peopleos:engage:credit-points')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
// Phase 12: SLA warnings / escalations, reminders, auto-close, catalogue promotion (idempotent reminder log).
Schedule::command('peopleos:service-desk:process')->hourly()->withoutOverlapping()->onOneServer();
// Phase 13: surveys open / close on their dates, invitations and reminders (idempotent log), campaigns; announcements publish and deliver.
Schedule::command('peopleos:engagement:process')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:communication:process')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:exit:tick')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:reports:run-due')->hourly()->withoutOverlapping()->onOneServer();
// Phase 14: inbound integration events (the queue applies them at once; this run picks up retries and expired leases).
Schedule::command('peopleos:integrations:process')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:webhooks:deliver')->everyMinute()->withoutOverlapping()->onOneServer();
// SaaS.8: notification deliveries stuck in sending (worker lost) or left queued (job lost).
Schedule::command('peopleos:notifications:recover')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:retention:purge')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
// SaaS.8: the audit chains are verified daily and the result recorded (it was never scheduled); queue tables pruned.
Schedule::command('peopleos:audit:verify --scheduled')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:operations:prune')->dailyAt('03:45')->withoutOverlapping()->onOneServer();
// SaaS.9: export recovery and expiry, due tenant deletions (deletions only when PEOPLEOS_TENANT_DELETION_ENABLED).
Schedule::command('peopleos:offboarding:tick')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// SaaS.10: self-service signup applications (expiry, provisioning, retries, activation).
Schedule::command('peopleos:signups:tick')->everyMinute()->withoutOverlapping()->onOneServer();
// SaaS.6: commercial expiries the dates already decided (idempotent; effective the day after each end, however late).
Schedule::command('peopleos:subscriptions:settle')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:billing:provider-events')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// SaaS.7 completion: billing periods and draft invoices (after the subscription settlement); off unless enabled (no price exists until B-4).
Schedule::command('peopleos:billing:run')->dailyAt('00:45')->withoutOverlapping()->onOneServer()->when(fn () => (bool) config('peopleos.billing.run_enabled'));
Schedule::command('peopleos:warehouse:export')->dailyAt('05:00')->withoutOverlapping()->onOneServer();

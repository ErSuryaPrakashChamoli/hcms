<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('peopleos:configuration:publish-due')->dailyAt('00:05')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:workflows:tick')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:lifecycle:reminders')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:performance:reminders')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:attendance:process')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:leave:accrue')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:compliance:sync')->weeklyOn(1, '00:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:learning:tick')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:learning:send-reminders')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:talent:send-reminders')->dailyAt('07:45')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:workforce:send-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
// Phase 11: scheduled compensation changes become effective on their date (each change locked, made effective once).
Schedule::command('peopleos:compensation:effect')->dailyAt('00:20')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:compensation:send-reminders')->dailyAt('08:15')->withoutOverlapping()->onOneServer();
// Phase 12: SLA warnings / escalations, reminders, auto-close, catalogue promotion (idempotent reminder log).
Schedule::command('peopleos:service-desk:process')->hourly()->withoutOverlapping()->onOneServer();
// Phase 13: surveys open / close on their dates, invitations and reminders (idempotent log), campaigns; announcements publish and deliver.
Schedule::command('peopleos:engagement:process')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:communication:process')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:exit:tick')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:reports:run-due')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:webhooks:deliver')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:retention:purge')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('peopleos:warehouse:export')->dailyAt('05:00')->withoutOverlapping()->onOneServer();

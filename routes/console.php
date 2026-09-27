<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('peopleos:configuration:publish-due')->dailyAt('00:05');
Schedule::command('peopleos:workflows:tick')->everyFiveMinutes();
Schedule::command('peopleos:lifecycle:reminders')->dailyAt('06:00');
Schedule::command('peopleos:attendance:process')->dailyAt('02:00');
Schedule::command('peopleos:leave:accrue')->dailyAt('01:00');
Schedule::command('peopleos:compliance:sync')->weeklyOn(1, '00:30');
Schedule::command('peopleos:learning:tick')->dailyAt('03:00');
Schedule::command('peopleos:servicedesk:tick')->hourly();
Schedule::command('peopleos:exit:tick')->dailyAt('04:00');
Schedule::command('peopleos:reports:run-due')->hourly();
Schedule::command('peopleos:webhooks:deliver')->everyMinute();
Schedule::command('peopleos:retention:purge')->dailyAt('03:30');
Schedule::command('peopleos:warehouse:export')->dailyAt('05:00');

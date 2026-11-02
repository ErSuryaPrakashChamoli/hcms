<?php

use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Engagement\Imports\EventAttendanceImports;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventAttendance;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Organisation\Models\Department;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Di2Helpers.php';
require_once __DIR__.'/../../Engagement/EngagementTestHelpers.php';

/* Data import (Engage → Event attendance): marks through CompanyEvents::markAttendance, with the event's own rules. */

beforeEach(function () {
    $this->travelTo('2026-11-01 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['engagement.events', 'engagement.view', 'employee.view', 'employee.import', 'wall.view']);
    $this->actingAs($this->hr);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $this->asha = engagementStaff($sales, null, ['engagement.participate']);
    $this->ravi = engagementStaff($sales, null, ['engagement.participate']);
    $this->omar = engagementStaff(Department::factory()->create(['name' => 'Operations']), null, ['engagement.participate']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // A town hall for Sales on two days, 10:00 India time.
    $this->event = app(CompanyEvents::class)->publish(app(CompanyEvents::class)->create([
        'title' => 'Town hall', 'event_type' => 'town_hall', 'mode' => 'online', 'meeting_url' => 'https://meet.example.com/town-hall',
        'start_date' => '2026-11-09', 'start_time' => '10:00', 'duration' => '01:30', 'timezone' => 'Asia/Kolkata', 'repeat' => 'daily', 'repeat_until' => '2026-11-10',
        'audience_criteria' => ['department_ids' => [$sales->id]],
    ], $this->hr), $this->hr);
    $this->travelTo('2026-11-09 06:00:00');
});

it('marks event attendance by event title or number, session number or date, with the event\'s own rules', function () {
    $a = $this->asha->employee_code;
    $r = $this->ravi->employee_code;
    $csv = "Event,Session,Employee code,Attended\n"
        ."Town hall,1,{$a},Attended\n"
        ."#{$this->event->id},2026-11-09,{$r},Absent\n"
        ."Town hall,,{$a},Attended\n"
        ."Town hall,2,{$a},Attended\n"
        ."Town hall,1,{$this->omar->employee_code},Attended\n"
        ."Nope,1,{$a},Attended\n"
        ."Town hall,1,{$r},Maybe\n";

    $import = di2Stage(EventAttendanceImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'create', 'error', 'error', 'error', 'error', 'error'])
        ->and(di2Errors($import, 3))->toContain('This event has 2 sessions: give the session number or date')
        ->and(di2Errors($import, 4))->toContain('This session has not started yet')
        ->and(di2Errors($import, 5))->toContain('is not invited to this event')
        ->and(di2Errors($import, 6))->toContain("Event 'Nope' does not exist")
        ->and(di2Errors($import, 7))->toContain('Attended must be Attended or Absent');

    di2Run($import);
    $marks = CompanyEventAttendance::query()->get()->mapWithKeys(fn ($m) => [$m->employee_id => $m->attended])->all();
    expect($marks)->toBe([$this->asha->id => true, $this->ravi->id => false]);

    // The same answer again is skipped; a different one re-marks.
    $again = di2Stage(EventAttendanceImports::TYPE, "Event,Session,Employee code,Attended\nTown hall,1,{$a},yes\nTown hall,1,{$r},present\n");
    expect(di2Actions($again))->toBe(['skip', 'update']);
    di2Run($again);
    expect(CompanyEventAttendance::query()->where('employee_id', $this->ravi->id)->value('attended'))->toBeTrue();
});

it('names an event shared by two titles by its number, and refuses people who do not run events', function () {
    CompanyEvent::query()->whereKey($this->event->id)->first()->replicate()->fill(['status' => 'draft'])->save();
    $import = di2Stage(EventAttendanceImports::TYPE, "Event,Session,Employee code,Attended\nTown hall,1,{$this->asha->employee_code},Attended\n");
    expect(di2Errors($import, 1))->toContain("Several events are called 'Town hall'");

    $clerk = tenantUser($this->tenant, ['employee.import', 'employee.view']);
    expect(ImportCatalogue::allowed($clerk, ImportCatalogue::find('engage.event_attendance')))->toBeFalse()
        ->and(ImportCatalogue::allowed($this->hr, ImportCatalogue::find('engage.event_attendance')))->toBeTrue();
});

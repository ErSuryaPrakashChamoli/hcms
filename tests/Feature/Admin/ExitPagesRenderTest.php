<?php

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Letters\Services\Letters;
use App\Filament\Pages\AlumniPortal;
use App\Filament\Pages\ExitInsights;
use App\Filament\Pages\MyDay;
use App\Filament\Resources\AlumniProfiles\AlumniProfileResource;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\ExitCases\Pages\ViewExitCase;
use App\Filament\Resources\ExitCases\RelationManagers\ClearancesRelationManager;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Letters\Pages\ViewLetter;
use App\Filament\Resources\LetterTemplates\LetterTemplateResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['exit.clear', 'exit.resign', 'task.view']);
    $this->employee = activeEmployee($this->manager, ['exit.resign', 'letter.view', 'alumni.portal', 'leave.apply', 'attendance.regularise', 'servicedesk.request', 'task.view']);
    $this->leaver = activeEmployee($this->manager, ['exit.resign', 'task.view']);
    $this->case = app(Exits::class)->initiate($this->leaver, 'resignation', 'Moving on', '2026-09-21', '2026-09-25', 30, $this->admin);
    $this->letter = app(Letters::class)->generate('experience', $this->leaver, [], $this->admin, $this->case);
    actAsTenant(null);
});

it('renders the exit, letter and alumni pages', function () {
    $this->get(ExitCaseResource::getUrl('index'))->assertOk()->assertSee('EXIT-2026-00001');
    $this->get(ExitCaseResource::getUrl('view', ['record' => $this->case]))->assertOk()->assertSee('Complete exit');
    $this->get(ExitInsights::getUrl())->assertOk()->assertSee('Reasons for leaving');
    $this->get(LetterTemplateResource::getUrl('index'))->assertOk()->assertSee('Experience letter');
    $this->get(LetterResource::getUrl('index'))->assertOk()->assertSee('LTR-2026-00001');
    $this->get(LetterResource::getUrl('view', ['record' => $this->letter]))->assertOk()->assertSee('Approve');
    $this->get(AlumniProfileResource::getUrl('index'))->assertOk();
});

it('lets an employee resign from My Day, a manager clear their stage, and HR approve and issue a letter', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);
    Livewire::test(MyDay::class)->callAction('resign', data: ['reason' => 'New opportunity'])->assertNotified('Resignation submitted');
    expect(ExitCase::query()->where('employee_id', $this->employee->id)->value('status'))->toBe('notice');

    $this->actingAs($this->manager->user);
    $stage = $this->case->clearances()->where('stage', 'manager')->first();
    Livewire::test(ClearancesRelationManager::class, ['ownerRecord' => $this->case->refresh(), 'pageClass' => ViewExitCase::class])
        ->callTableAction('clear', $stage, data: ['recoverable_amount' => 0, 'remarks' => 'All handed over'])
        ->assertNotified('Stage cleared');
    expect($stage->refresh()->status)->toBe('cleared');

    $this->actingAs($this->admin);
    Livewire::test(ViewLetter::class, ['record' => $this->letter->id])->callAction('approve')->assertNotified('Approved');
    Livewire::test(ViewLetter::class, ['record' => $this->letter->id])->callAction('issue')->assertNotified('Letter issued');
    expect($this->letter->refresh()->status)->toBe('issued');

    actAsTenant(null);
    $this->actingAs($this->leaver->user);
    $this->get(ExitCaseResource::getUrl('index'))->assertOk()->assertSee('My exit')->assertSee('EXIT-2026-00001');
    $this->get(LetterResource::getUrl('index'))->assertOk()->assertSee('My letters')->assertSee('LTR-2026-00001');
    $this->get(LetterTemplateResource::getUrl('index'))->assertForbidden();
    $this->get(AlumniPortal::getUrl())->assertForbidden();
});

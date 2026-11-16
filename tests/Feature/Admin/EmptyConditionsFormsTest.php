<?php

use App\Domain\Learning\Models\LearningProgram;
use App\Domain\Performance\Services\Appraisals;
use App\Filament\Support\AuditReasonField;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\LearningPrograms\Pages\ManageLearningPrograms;
use App\Filament\Resources\PerformanceCycles\Pages\EditPerformanceCycle;
use App\Filament\Resources\PerformanceUnits\Pages\ManagePerformanceUnits;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| QA audit 2026-11-12: conditions fields whose screen says "empty = everyone" must accept no conditions. The shared
| conditions repeater requires one row unless the field opts out (->minItems(0)), so these four refused to save:
| a performance cycle open to everyone could not even be saved unchanged.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    Filament::getPanel('admin')->boot();
});

it('saves a performance cycle open to everyone unchanged', function () {
    $cycle = draftCycle();
    expect($cycle->eligibility ?? [])->toBe([]);

    Livewire::test(EditPerformanceCycle::class, ['record' => $cycle->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors(['eligibility']);
});

it('saves a launched cycle\'s name without touching its frozen configuration', function () {
    // A launched cycle keeps its configuration (PerformanceCycle::booted throws on a change). Its edit form used to send
    // back type, stages, options and eligibility as editable fields, so any save — even unchanged — hit that guard.
    $cycle = app(Appraisals::class)->launch(draftCycle());
    expect($cycle->refresh()->isEditable())->toBeFalse();

    Livewire::test(EditPerformanceCycle::class, ['record' => $cycle->getRouteKey()])
        ->fillForm(['name' => 'FY 2026 annual review', AuditReasonField::NAME => 'Clearer name'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($cycle->refresh()->name)->toBe('FY 2026 annual review');
});

it('accepts an empty audience, eligibility or applicability where the screen says empty means everyone', function () {
    Livewire::test(CreateArticle::class)->fillForm(['audience' => []])->call('create')->assertHasNoFormErrors(['audience']);

    Livewire::test(ManagePerformanceUnits::class)->callAction('create', ['applicability' => []])->assertHasNoActionErrors(['applicability']);

    $program = LearningProgram::create(['code' => 'NEWMGR', 'name' => 'New manager program']);
    Livewire::test(ManageLearningPrograms::class)->callTableAction('publish', $program, data: ['conditions' => []])->assertHasNoTableActionErrors(['conditions']);
});

<?php

use App\Domain\Engagement\Models\MissionStatement;
use App\Domain\Engagement\Services\MissionStatements;
use App\Domain\Organisation\Models\Department;
use App\Filament\Pages\Home;
use App\Filament\Resources\MissionStatements\MissionStatementResource;
use App\Filament\Resources\MissionStatements\Pages\CreateMissionStatement;
use App\Filament\Resources\MissionStatements\Pages\EditMissionStatement;
use App\Filament\Resources\MissionStatements\Pages\ListMissionStatements;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-9b — Engage → Mission statement: mission, vision, purpose and core values per audience (the
 * most specific statement wins), shown on employees' Home; set up by engagement managers. */

function hp9bStatement(array $attributes = []): MissionStatement
{
    static $n = 0;
    $n++;

    return MissionStatement::query()->create($attributes + ['code' => "MS{$n}", 'name' => "Mission statement policy {$n}", 'mission' => "Mission {$n}"]);
}

beforeEach(function () {
    $this->travelTo('2026-10-29 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['engagement.view', 'engagement.manage']);
    $this->employee = activeEmployee(null, ['kb.view']);
    $this->other = activeEmployee(null, ['kb.view']);
});

it('creates a statement from the steps of the form, with core values and no audience (everyone)', function () {
    $this->actingAs($this->admin);
    Livewire::test(CreateMissionStatement::class)
        ->fillForm([
            'name' => 'Mission statement policy - 01', 'code' => 'core',
            'mission' => 'Make work better for everyone.', 'vision' => 'The most trusted HR platform.',
            'core_values' => [['title' => 'Integrity', 'icon' => 'heroicon-o-shield-check', 'description' => 'Do the right thing.'], ['title' => 'Customer first', 'icon' => 'heroicon-o-heart', 'description' => null]],
        ])
        ->call('create')->assertHasNoFormErrors();

    $statement = MissionStatement::query()->firstOrFail();
    expect($statement->code)->toBe('CORE')->and($statement->audience)->toBe([])->and($statement->show_on_home)->toBeTrue()
        ->and(array_column($statement->core_values, 'title'))->toBe(['Integrity', 'Customer first'])
        ->and(app(MissionStatements::class)->forEmployee($this->employee)?->id)->toBe($statement->id);

    Livewire::test(EditMissionStatement::class, ['record' => $statement->getRouteKey()])
        ->fillForm(['status' => 'inactive', 'audit_reason' => 'Replaced'])->call('save')->assertHasNoFormErrors();
    expect(app(MissionStatements::class)->forEmployee($this->employee))->toBeNull();

    expect(fn () => hp9bStatement(['mission' => null, 'core_values' => [['title' => '']]]))->toThrow(RuntimeException::class, 'Write the mission');
});

it('shows each employee the most specific statement in force, on Home', function () {
    $department = Department::factory()->create();
    $this->employee->currentPosition->update(['department_id' => $department->id]);
    $everyone = hp9bStatement(['mission' => 'For everyone', 'core_values' => [['title' => 'Integrity', 'icon' => 'heroicon-o-shield-check']]]);
    $engineering = hp9bStatement(['mission' => 'Build what lasts', 'audience' => [['field' => 'department_id', 'operator' => 'in', 'value' => [(string) $department->id]]]]);
    hp9bStatement(['mission' => 'Not yet', 'effective_from' => '2027-01-01', 'audience' => [['field' => 'department_id', 'operator' => 'in', 'value' => [(string) $department->id]], ['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'active']]]);
    hp9bStatement(['mission' => 'Hidden', 'show_on_home' => false, 'audience' => [['field' => 'department_id', 'operator' => 'in', 'value' => [(string) $department->id]], ['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'active']]]);

    $missions = app(MissionStatements::class);
    expect($missions->forEmployee($this->employee->refresh(), homeOnly: true)?->id)->toBe($engineering->id)
        ->and($missions->forEmployee($this->other, homeOnly: true)?->id)->toBe($everyone->id);

    $this->actingAs($this->employee->user);
    Livewire::test(Home::class)->assertSee('Our mission')->assertSee('Build what lasts')->assertDontSee('For everyone');
    $this->actingAs($this->other->user);
    Livewire::test(Home::class)->assertSee('For everyone')->assertSee('Integrity');

    // Hidden from Home by the person: the card goes.
    Livewire::test(Home::class)->call('hideCard', 'mission')->assertDontSee('Our mission');
});

it('shows no mission card when there is none, and keeps the set-up to engagement managers', function () {
    $this->actingAs($this->employee->user);
    Livewire::test(Home::class)->assertDontSee('Our mission');
    expect(MissionStatementResource::canAccess())->toBeFalse();
    $this->get(MissionStatementResource::getUrl('index'))->assertForbidden();

    $this->actingAs(tenantUser($this->tenant, ['engagement.view']));
    $this->get(MissionStatementResource::getUrl('index'))->assertOk();
    expect(MissionStatementResource::canCreate())->toBeFalse();

    $this->actingAs($this->admin);
    $statement = hp9bStatement();
    Livewire::test(ListMissionStatements::class)->assertCanSeeTableRecords([$statement])
        ->filterTable('status', 'inactive')->assertCanNotSeeTableRecords([$statement]);
    expect($this->admin->can('delete', $statement))->toBeFalse();
});

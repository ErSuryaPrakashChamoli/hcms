<?php

use App\Domain\Analytics\Datasets\Dataset;
use App\Domain\Analytics\Datasets\GoalsDataset;
use App\Domain\Analytics\Datasets\ImprovementPlansDataset;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Performance\Services\PerformanceDefaults;
use App\Domain\Performance\Services\PerformanceMasters;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\SetupCentre;
use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\KpiRatingFormulas\KpiRatingFormulaResource;
use App\Filament\Resources\Kras\KraResource;
use App\Filament\Resources\PerformanceLookups\Pages\ManagePerformanceLookups;
use App\Filament\Resources\PerformanceLookups\PerformanceLookupResource;
use App\Filament\Resources\PerformanceUnits\PerformanceUnitResource;
use Illuminate\Support\Collection;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8a — Performance masters: perspectives, initiative types and PIP reasons seeded as HROne lists
 * them, units of measurement, their links on KRAs / goals / key results / improvement plans, and the performance
 * terminology (performance.labels.*, "Use OKR terms"). Defaults keep the earlier wording and behaviour. */

/** Every row of a dataset as field => value (as reports read it). */
function perfMastersRows(Dataset $dataset): Collection
{
    return $dataset->query()->get()->map(fn ($row) => collect(array_keys($dataset->fields()))->mapWithKeys(fn ($field) => [$field => $dataset->value($field, $row)])->all());
}

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals']);
    $this->employee = activeEmployee($this->manager, ['performance.goals', 'performance.review']);
    $this->settings = app(SettingsRepository::class);
});

it('seeds the standard masters as HROne lists them, as system rows that can be renamed or deactivated but not deleted', function () {
    expect(PerformanceLookup::query()->ofKind('perspective')->orderBy('sort_order')->pluck('name')->all())->toBe(['Customer', 'External Process', 'Financial', 'Internal process', 'Learning and growth'])
        ->and(PerformanceLookup::query()->ofKind('initiative_type')->orderBy('sort_order')->pluck('name')->all())->toBe(['BUDDY', 'INDUCTION', 'INITIATIVE', 'PIP', 'TNI'])
        ->and(PerformanceLookup::query()->ofKind('pip_reason')->orderBy('sort_order')->pluck('name')->all())->toBe(['Absenteeism', 'Inappropriate attitude', 'Insubordination', 'Punctuality', 'Unsatisfactory performance'])
        ->and(PerformanceUnit::query()->orderBy('id')->pluck('code')->all())->toBe(['%', 'INR', 'RATING', 'UNITS'])
        ->and(PerformanceUnit::query()->where('is_default', true)->exists())->toBeFalse()
        ->and(PerformanceLookup::query()->where('is_system', false)->count())->toBe(0);

    $customer = PerformanceLookup::query()->ofKind('perspective')->where('code', 'CUSTOMER')->firstOrFail();
    expect(fn () => $customer->delete())->toThrow(RuntimeException::class, 'cannot be deleted')
        ->and(fn () => $customer->update(['code' => 'CLIENT']))->toThrow(RuntimeException::class, 'keeps its code');
    $customer->refresh()->update(['name' => 'Client', 'status' => 'inactive']);
    expect(PerformanceLookup::options('perspective'))->not->toContain('Client')
        ->and(PerformanceLookup::options('perspective', $customer->id))->toContain('Client')
        ->and(fn () => PerformanceUnit::query()->where('code', 'UNITS')->firstOrFail()->delete())->toThrow(RuntimeException::class, 'cannot be deleted')
        ->and(fn () => PerformanceLookup::query()->create(['kind' => 'colour', 'code' => 'RED', 'name' => 'Red']))->toThrow(RuntimeException::class, 'Unknown performance master kind');

    // Adding the standard entries again (existing tenants) adds only what is missing and keeps the renames.
    $mine = PerformanceLookup::query()->create(['kind' => 'pip_reason', 'code' => 'safety', 'name' => 'Safety breach']);
    expect($mine->code)->toBe('SAFETY')
        ->and(app(PerformanceDefaults::class)->seedMasters())->toBe(0)
        ->and($customer->refresh()->name)->toBe('Client');
    $mine->delete();
    expect(PerformanceLookup::query()->whereKey($mine->id)->exists())->toBeFalse();
});

it('links perspectives and units to goals: the unit fills the measure, target and unit code; references are checked', function () {
    $goals = app(Goals::class);
    $financial = PerformanceLookup::query()->ofKind('perspective')->where('code', 'FINANCIAL')->firstOrFail();
    $percent = PerformanceUnit::query()->where('code', '%')->firstOrFail();
    $amount = PerformanceUnit::query()->where('code', 'INR')->firstOrFail();

    $goal = $goals->create(['employee_id' => $this->employee->id, 'title' => 'Collections', 'unit_id' => $amount->id, 'perspective_id' => $financial->id, 'weight' => 40, 'target_value' => 500000]);
    expect($goal->unit)->toBe('INR')->and($goal->measure_type)->toBe('currency')->and((float) $goal->target_value)->toBe(500000.0)
        ->and($goal->perspective->name)->toBe('Financial')->and($goal->measurementUnit->code)->toBe('INR');

    $byPercent = $goals->create(['employee_id' => $this->employee->id, 'title' => 'NPS', 'unit_id' => $percent->id, 'weight' => 10]);
    expect($byPercent->measure_type)->toBe('percentage')->and((float) $byPercent->target_value)->toBe(100.0)->and($byPercent->unit)->toBe('%');

    // A goal without a unit is as before (free-text unit, form defaults).
    $plain = $goals->create(['employee_id' => $this->employee->id, 'title' => 'Plain', 'unit' => 'tickets', 'weight' => 10]);
    expect($plain->unit_id)->toBeNull()->and($plain->unit)->toBe('tickets');

    $financial->update(['status' => 'inactive']);
    expect(fn () => $goals->create(['employee_id' => $this->employee->id, 'title' => 'Margin', 'perspective_id' => $financial->id]))->toThrow(RuntimeException::class, 'active perspective')
        // An unchanged reference to an entry made inactive later does not block other edits.
        ->and($goals->update($goal->refresh(), ['title' => 'Collections FY26'])->title)->toBe('Collections FY26');

    // A unit limited to another group is refused for this employee's goals.
    $amount->update(['applicability' => [['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'probation']]]);
    expect(fn () => $goals->create(['employee_id' => $this->employee->id, 'title' => 'Billing', 'unit_id' => $amount->id]))->toThrow(RuntimeException::class, 'cannot be used for this goal')
        ->and(app(PerformanceMasters::class)->unitOptions($this->employee, $this->hr))->not->toHaveKey($amount->id);

    // Key results take a unit and (when shown) a due date; the goals dataset carries perspective and unit.
    $okr = $goals->create(['employee_id' => $this->employee->id, 'title' => 'Grow', 'type' => 'okr', 'weight' => 20], [
        ['title' => 'New logos', 'measure_type' => 'numeric', 'target_value' => 10, 'unit_id' => $percent->id, 'due_date' => '2026-12-31', 'weight' => 100],
    ]);
    expect($okr->keyResults->first()->due_date->toDateString())->toBe('2026-12-31')->and($okr->keyResults->first()->measurementUnit->code)->toBe('%');
    $rows = perfMastersRows(app(GoalsDataset::class))->keyBy('title');
    expect($rows['Collections FY26']['perspective'])->toBe('Financial')->and($rows['Collections FY26']['unit'])->toBe('Amount (INR)')->and($rows['Plain']['unit'])->toBe('tickets');
});

it('lets employees add their own units only when allowed, visible to them and HR', function () {
    $masters = app(PerformanceMasters::class);
    expect(fn () => $masters->addUnitForUser('Calls', 'numeric', $this->employee->user))->toThrow(RuntimeException::class, 'set up by HR');

    $this->settings->set('performance.units.employee_can_add', true, 'Sales units');
    $calls = $masters->addUnitForUser('Calls', 'numeric', $this->employee->user);
    expect($calls->is_admin)->toBeFalse()->and((int) $calls->created_by)->toBe($this->employee->user->id)
        ->and($masters->unitOptions($this->employee, $this->employee->user))->toHaveKey($calls->id)
        ->and($masters->unitOptions($this->employee, $this->manager->user))->not->toHaveKey($calls->id)
        ->and($masters->unitOptions($this->employee, $this->hr))->toHaveKey($calls->id)
        ->and(fn () => $calls->update(['is_default' => true]))->toThrow(RuntimeException::class, 'organisation unit');

    // Another employee cannot put someone else's unit on a goal.
    $this->actingAs($this->manager->user);
    expect(fn () => app(Goals::class)->create(['employee_id' => $this->manager->id, 'title' => 'Calls made', 'unit_id' => $calls->id], [], $this->manager->user))->toThrow(RuntimeException::class, 'cannot be used');

    // One default unit per organisation.
    $percent = PerformanceUnit::query()->where('code', '%')->firstOrFail();
    $units = PerformanceUnit::query()->where('code', 'UNITS')->firstOrFail();
    $percent->update(['is_default' => true]);
    $units->update(['is_default' => true]);
    expect($percent->refresh()->is_default)->toBeFalse()->and($masters->defaultUnit(null, $this->hr)?->id)->toBe($units->id);
});

it('keeps a perspective and library KPIs (unit, reverse, formula) on KRAs, and PIP reasons on improvement plans', function () {
    $customer = PerformanceLookup::query()->ofKind('perspective')->where('code', 'CUSTOMER')->firstOrFail();
    $kra = Kra::query()->create(['name' => 'Customer delight', 'code' => 'cx', 'perspective_id' => $customer->id, 'kpis' => [
        ['name' => 'Complaints', 'measure' => 'count', 'target' => '5', 'unit_id' => PerformanceUnit::query()->where('code', 'UNITS')->value('id'), 'is_reverse' => true],
    ]]);
    expect($kra->perspective->name)->toBe('Customer')->and($kra->kpis[0]['is_reverse'])->toBeTrue();

    $plans = app(ImprovementPlans::class);
    $plan = $plans->open($this->employee, $this->manager, 'Late on 6 days in August', [['objective' => 'On time daily']], '2026-10-01', '2026-11-30', null, $this->hr, reasonCodes: ['punctuality', 'ABSENTEEISM', 'PUNCTUALITY']);
    expect($plan->reason_codes)->toBe(['PUNCTUALITY', 'ABSENTEEISM'])
        ->and(perfMastersRows(app(ImprovementPlansDataset::class))->first()['reasons'])->toBe('Punctuality, Absenteeism');
    $plans->close($plan, 'cancelled', 'Withdrawn');
    expect(fn () => $plans->open($this->employee, $this->manager, 'x', [['objective' => 'y']], '2026-10-01', '2026-11-30', null, $this->hr, reasonCodes: ['LAZINESS']))->toThrow(RuntimeException::class, 'Unknown PIP reason')
        // Without reasons a plan opens as before.
        ->and($plans->open($this->employee, $this->manager, 'Quality', [['objective' => 'Fewer bugs']], '2026-10-01', '2026-11-30', null, $this->hr)->reason_codes)->toBeNull();
});

it('names the performance terms as the organisation chooses, keeps the built-in wording by default, and sets OKR terms', function () {
    expect(PerformanceLabels::get('kra'))->toBe('KRA')
        ->and(PerformanceLabels::plural('kpi', 'Key results'))->toBe('Key results')
        ->and(PerformanceLabels::get('target', 'Target value'))->toBe('Target value')
        ->and(KraResource::getNavigationLabel())->toBe('KRA library')
        ->and(CompetencyResource::getNavigationLabel())->toBe('Competencies')
        ->and(PerformanceLabels::goalTypes())->toBe(config('peopleos.performance.goal_types'))
        ->and(fn () => PerformanceLabels::get('salary'))->toThrow(RuntimeException::class, 'Unknown performance term');

    app(SetupCatalogue::class)->save('performance', [SetupCatalogue::field('performance.labels.kra') => 'Result area', SetupCatalogue::field('performance.labels.competency') => 'Behaviour'], 'Our words', $this->hr);
    expect(KraResource::getNavigationLabel())->toBe('Result area library')
        ->and(CompetencyResource::getNavigationLabel())->toBe('Behaviours')
        ->and(PerformanceLabels::goalTypes()['kra'])->toBe('Result area');

    // "Use OKR terms": KRA → Objective, KPI → Key result, achievement → Progress; audited; settings.update and a reason needed.
    expect(fn () => PerformanceLabels::useOkrTerms(tenantUser($this->tenant, ['settings.view']), 'x'))->toThrow(RuntimeException::class, 'cannot change')
        ->and(fn () => PerformanceLabels::useOkrTerms($this->hr, ' '))->toThrow(RuntimeException::class, 'reason');
    $changed = PerformanceLabels::useOkrTerms($this->hr, 'We run OKRs');
    expect($changed)->toBe(['performance.labels.kra', 'performance.labels.kpi', 'performance.labels.achievement'])
        ->and(PerformanceLabels::all())->toMatchArray(['kra' => 'Objective', 'kpi' => 'Key result', 'achievement' => 'Progress', 'competency' => 'Behaviour'])
        ->and(PerformanceLabels::plural('kpi', 'Key results'))->toBe('Key results')
        ->and(KpiRatingFormulaResource::getNavigationLabel())->toBe('Key result rating formulas')
        ->and(PerformanceLabels::useOkrTerms($this->hr, 'Again'))->toBe([])
        ->and(AuditEvent::query()->where('reason', 'We run OKRs')->count())->toBe(3);

    // The Setup centre offers the action on the Performance module only.
    Livewire::test(SetupCentre::class)->call('selectModule', 'core')->assertActionHidden('useOkrTerms')
        ->call('selectModule', 'performance')->assertActionVisible('useOkrTerms');
    $this->settings->set('performance.labels.kra', null, 'Reset');
    Livewire::test(SetupCentre::class)->call('selectModule', 'performance')->callAction('useOkrTerms', ['audit_reason' => 'Back to OKR'])->assertNotified('OKR terms in use');
    expect(PerformanceLabels::get('kra'))->toBe('Objective');
});

it('opens the masters, units and formulas to performance administrators only', function () {
    foreach ([PerformanceLookupResource::class, PerformanceUnitResource::class, KpiRatingFormulaResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertOk();
    }
    Livewire::test(ManagePerformanceLookups::class)->assertOk()->assertSee('Customer')
        ->callAction('create', ['kind' => 'initiative_type', 'name' => 'Mentoring', 'code' => 'MENTOR', 'status' => 'active'])->assertHasNoActionErrors()
        ->callAction('standard')->assertNotified('0 standard entries added');
    expect(PerformanceLookup::query()->ofKind('initiative_type')->where('code', 'MENTOR')->exists())->toBeTrue();

    $this->actingAs($this->employee->user);
    foreach ([PerformanceLookupResource::class, PerformanceUnitResource::class, KpiRatingFormulaResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertForbidden();
    }
    expect($this->employee->user->can('create', PerformanceUnit::class))->toBeFalse()
        ->and(Goal::query()->count())->toBe(0);
});

<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Cloning\CloneRequest;
use App\Domain\Configuration\Cloning\ConfigurationCloner;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Services\DesignationDefaults;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Resources\Designations\Pages\EditDesignation;
use App\Filament\Resources\Designations\Pages\ListDesignations;
use App\Filament\Resources\Grades\Pages\EditGrade;
use App\Filament\Support\Applicability\CompanyValuesAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Remediation I.6: a designation's level and grade agree, shared and for every company with its own values, on every date
| from a change on. A grade with no level fits any level; blank values stay allowed. The same check behind the form, the
| company values, the upload, the clone and a grade moving to another level. Positions are never rewritten.
| Sourcing Manager is L5 / T4 shared; T4 is a grade of L5, T5 of L6, T-OPEN has no level.
*/

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->l5 = Level::query()->create(['name' => 'L5', 'code' => 'LG-L5']);
    $this->l6 = Level::query()->create(['name' => 'L6', 'code' => 'LG-L6']);
    $this->t4 = Grade::query()->create(['name' => 'T4', 'code' => 'T4', 'level_id' => $this->l5->id]);
    $this->t5 = Grade::query()->create(['name' => 'T5', 'code' => 'T5', 'level_id' => $this->l6->id]);
    $this->open = Grade::query()->create(['name' => 'T-OPEN', 'code' => 'T-OPEN']);
    $this->sourcing = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR', 'level_id' => $this->l5->id, 'grade_id' => $this->t4->id]);
    $this->overrides = app(ConfigurationOverrides::class);
});

function lgOverrides(): int
{
    return ConfigurationOverride::query()->where('configuration_type', 'designation')->count();
}

it('refuses a shared pair that disagrees, and keeps optional values optional', function () {
    expect(fn () => Designation::query()->create(['name' => 'Buyer', 'code' => 'BUY', 'level_id' => $this->l5->id, 'grade_id' => $this->t5->id]))
        ->toThrow(ApplicabilityRefusedException::class, 'Grade T5 belongs to level L6, not L5');
    expect(fn () => $this->sourcing->update(['grade_id' => $this->t5->id]))->toThrow(ApplicabilityRefusedException::class, 'Change them together');

    // Either may be blank, a grade without a level fits any level, and both changed together are fine.
    expect(Designation::query()->create(['name' => 'Analyst', 'code' => 'ANL', 'grade_id' => $this->t5->id])->exists)->toBeTrue()
        ->and(Designation::query()->create(['name' => 'Lead', 'code' => 'LEAD', 'level_id' => $this->l6->id, 'grade_id' => $this->open->id])->exists)->toBeTrue();
    $this->sourcing->update(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id]);
    expect($this->sourcing->fresh()->only(['level_id', 'grade_id']))->toBe(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id]);
});

it('says so on the designation form, against the field, before anything is saved', function () {
    Livewire::test(EditDesignation::class, ['record' => $this->sourcing->getRouteKey()])
        ->fillForm(['grade_id' => $this->t5->id, 'audit_reason' => 'Regrade'])->call('save')
        ->assertHasFormErrors(['grade_id']);
    expect($this->sourcing->fresh()->grade_id)->toBe($this->t4->id);
});

it("refuses a company's own value that disagrees with its level, writing nothing, and accepts both together", function () {
    $audits = AuditEvent::query()->where('action', AuditAction::OverrideSet->value)->count();

    expect(fn () => $this->overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->t5->id, '2026-11-25', $this->admin, 'Grade only'))
        ->toThrow(ApplicabilityRefusedException::class, "FynnEdge Advisory's values");
    expect(lgOverrides())->toBe(0)->and(AuditEvent::query()->where('action', AuditAction::OverrideSet->value)->count())->toBe($audits);

    $this->overrides->together(function () {
        $this->overrides->set($this->sourcing, $this->fe->id, 'level_id', $this->l6->id, '2026-11-25', $this->admin, 'FynnEdge level');
        $this->overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->t5->id, '2026-11-25', $this->admin, 'FynnEdge grade');
    });
    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id))->toMatchArray(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id])
        ->and(app(DesignationDefaults::class)->for($this->sourcing, $this->mk->id))->toMatchArray(['level_id' => $this->l5->id, 'grade_id' => $this->t4->id]);
});

it('refuses ending one of the pair when the shared value would disagree, and ends both together', function () {
    $this->overrides->together(function () {
        $this->overrides->set($this->sourcing, $this->fe->id, 'level_id', $this->l6->id, '2026-11-25', $this->admin);
        $this->overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->t5->id, '2026-11-25', $this->admin);
    });

    // Ending the grade alone leaves FynnEdge's L6 with the shared T4 (a grade of L5).
    expect(fn () => $this->overrides->end($this->sourcing, $this->fe->id, 'grade_id', '2026-12-01', $this->admin, 'Shared grade'))
        ->toThrow(ApplicabilityRefusedException::class, 'from 1 Dec 2026');
    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2026-12-15')['grade_id'])->toBe($this->t5->id);

    $this->overrides->together(function () {
        $this->overrides->end($this->sourcing, $this->fe->id, 'level_id', '2026-12-01', $this->admin);
        $this->overrides->end($this->sourcing, $this->fe->id, 'grade_id', '2026-12-01', $this->admin);
    });
    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2026-12-01'))->toMatchArray(['level_id' => $this->l5->id, 'grade_id' => $this->t4->id])
        ->and(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2026-11-30'))->toMatchArray(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id]);
});

it('checks the pair on later dates too: a future value that would disagree is refused', function () {
    // FynnEdge's own grade T-OPEN (no level) from today, then L6 from 1 Jan: fine. A later T4 (a grade of L5) under L6: not.
    $this->overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->open->id, '2026-11-25', $this->admin);
    $this->overrides->set($this->sourcing, $this->fe->id, 'level_id', $this->l6->id, '2027-01-01', $this->admin);

    expect(fn () => $this->overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->t4->id, '2026-12-01', $this->admin))
        ->toThrow(ApplicabilityRefusedException::class, 'from 1 Jan 2027');
});

it("refuses a shared change that would leave a company's own value disagreeing", function () {
    // FynnEdge keeps the shared level but has its own grade T-OPEN... then T4: fine under the shared L5.
    $this->overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->t4->id, '2026-11-25', $this->admin);
    // Moving the shared pair to L6 / T5 leaves FynnEdge with L6 and its own T4 (a grade of L5).
    expect(fn () => $this->sourcing->update(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id]))
        ->toThrow(ApplicabilityRefusedException::class, "FynnEdge Advisory's values");
    expect($this->sourcing->fresh()->level_id)->toBe($this->l5->id);
});

it('moves a grade to another level only while no designation pairs it with a different one', function () {
    expect(fn () => $this->t4->update(['level_id' => $this->l6->id]))->toThrow(ApplicabilityRefusedException::class, 'Sourcing Manager pairs it with another level');
    Livewire::test(EditGrade::class, ['record' => $this->t4->getRouteKey()])->fillForm(['level_id' => $this->l6->id])->call('save')->assertHasFormErrors(['level_id']);

    // Through a company's own grade too.
    $this->overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->open->id, '2026-11-25', $this->admin);
    expect(fn () => $this->open->update(['level_id' => $this->l6->id]))->toThrow(ApplicabilityRefusedException::class, 'Sourcing Manager');

    // Unpaired, or paired with the same level: fine.
    $this->t5->update(['level_id' => $this->l5->id]);
    $this->open->update(['level_id' => $this->l5->id]);
    expect($this->open->fresh()->level_id)->toBe($this->l5->id);
});

it('does not block an unrelated change of a pair that disagreed before the rule', function () {
    // Data from before the rule: written past the model.
    DB::table('designations')->where('id', $this->sourcing->id)->update(['grade_id' => $this->t5->id]);
    $legacy = $this->sourcing->fresh();

    $legacy->update(['name' => 'Senior Sourcing Manager']);
    expect($legacy->fresh()->name)->toBe('Senior Sourcing Manager');
    expect(fn () => $legacy->fresh()->update(['level_id' => $this->l5->id, 'grade_id' => $this->t5->id, 'name' => 'x']))->not->toThrow(ApplicabilityRefusedException::class);
    expect(fn () => $legacy->fresh()->update(['grade_id' => $this->t4->id, 'level_id' => $this->l6->id]))->toThrow(ApplicabilityRefusedException::class);
});

it('checks the pair in the designation upload before approval, and sets a company pair together', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $upload = function (string $csv): EmployeeImport {
        $path = EmployeeImports::directory().'/'.uniqid('lg').'.csv';
        Storage::disk('local')->put($path, $csv);
        $import = ImportTypes::register('designations', $path, 'designations.csv', $this->admin, ['mode' => 'both']);
        $service = ImportTypes::service('designations');
        $service->inspect($import);
        $service->map($import->refresh(), $import->refresh()->mapping);

        return $service->validate($import->refresh());
    };
    $import = $upload("Code,Name,Level code,Grade code,Company\n"
        ."BUY,Buyer,LG-L5,T5,\n"            // shared pair that disagrees
        ."SRC-MGR,Sourcing Manager,,T5,FE\n"  // FynnEdge's grade alone: L5 with T5
        ."SRC-MGR,Sourcing Manager,LG-L6,T5,MK\n");  // Markedge's pair together: fine
    $rows = $import->rows()->orderBy('row_number')->get();

    expect($rows[0]->action)->toBe('error')->and(implode(' ', $rows[0]->errors))->toContain('Grade T5 belongs to level L6, not L5')
        ->and($rows[1]->action)->toBe('error')->and(implode(' ', $rows[1]->errors))->toContain('FynnEdge Advisory: Grade T5 belongs to level L6')
        ->and($rows[2]->action)->toBe('update');

    $service = ImportTypes::service('designations');
    $service->approve($import->refresh(), $this->admin, 'test');
    $service->run($import->refresh(), $this->admin);
    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->mk->id))->toMatchArray(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id])
        ->and(Designation::query()->where('code', 'BUY')->exists())->toBeFalse();
});

it('refuses a clone whose company values disagree, and copies an agreeing pair', function () {
    // A company pair that disagrees, written before the rule (past the service).
    ConfigurationOverride::query()->create(['ulid' => (string) str()->ulid(), 'configuration_type' => 'designation', 'configuration_id' => $this->sourcing->id,
        'company_id' => $this->fe->id, 'field' => 'grade_id', 'value' => $this->t5->id, 'shared_value' => $this->t4->id, 'effective_from' => '2026-11-25', 'status' => 'active', 'version' => 1]);
    ConfigurationOverrides::flushMemo();
    $clone = fn (int $company, string $code) => app(ConfigurationCloner::class)->execute(new CloneRequest('designation', $this->sourcing->id,
        [new Destination($company, "Sourcing Manager ({$code})", "SRC-{$code}")], reason: 'Own designation', acknowledged: true), $this->admin);

    expect(fn () => $clone($this->fe->id, 'FE'))->toThrow(ApplicabilityRefusedException::class, 'Grade T5 belongs to level L6, not L5');
    expect(Designation::query()->where('code', 'SRC-FE')->exists())->toBeFalse()
        ->and($clone($this->mk->id, 'MK')->copies[0]->fresh()->only(['level_id', 'grade_id']))->toBe(['level_id' => $this->l5->id, 'grade_id' => $this->t4->id]);
});

it('offers the level and grade together in "Company values…"', function () {
    // One field alone would leave FynnEdge with L6 and the shared T4: refused, as a message, nothing saved.
    Livewire::test(ListDesignations::class)
        ->callTableAction('companyValues', $this->sourcing, ['company_id' => $this->fe->id, 'operation' => 'set', 'field' => 'level_id',
            'value_select' => $this->l6->id, 'effective_from' => '2026-11-25', 'audit_reason' => 'FynnEdge level'])
        ->assertNotified('Not saved');
    expect(lgOverrides())->toBe(0);

    Livewire::test(ListDesignations::class)
        ->callTableAction('companyValues', $this->sourcing, ['company_id' => $this->fe->id, 'operation' => 'set', 'field' => CompanyValuesAction::TOGETHER,
            'together' => ['level_id' => $this->l6->id, 'grade_id' => $this->t5->id], 'effective_from' => '2026-11-25', 'audit_reason' => 'FynnEdge pay structure'])
        ->assertHasNoTableActionErrors()->assertNotified('Company value saved');

    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id))->toMatchArray(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id]);
});

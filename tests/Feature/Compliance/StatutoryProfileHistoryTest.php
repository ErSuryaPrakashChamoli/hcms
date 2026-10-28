<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Resources\EstablishmentStatutoryProfiles\Pages\ManageEstablishmentStatutoryProfiles;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

// C12: payroll past review keeps the statutory profile it was calculated with, and compliance reports decide from the
// same profiles, so no profile change may alter what applied in a month whose payroll is approved or finalised.
beforeEach(function () {
    $this->travelTo('2026-10-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->entity = LegalEntity::query()->where('company_id', $this->company->id)->sole();
    $this->ka = Establishment::query()->where('company_id', $this->company->id)->sole();
    $this->ka->update(['state' => 'KA', 'effective_from' => '2020-01-01']);
    $this->mh = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $this->epf = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->mh->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $this->esi = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->mh->id, 'statute' => 'ESI', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $this->employee = salariedEmployee(240000);
    app(EstablishmentAssignments::class)->assign($this->employee, $this->mh, '2025-01-01', 'Pune');

    $this->runs = app(PayrollRuns::class);
    $this->finalise = function (int $month): PayrollRun {
        $run = $this->runs->calculate($this->runs->open($this->company, 2026, $month, $this->admin), $this->admin);
        PayrollRun::query()->whereKey($run->id)->update(['status' => 'finalized']);

        return $run->fresh();
    };
    $this->codes = fn (PayrollRun $run) => PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->pluck('id'))->pluck('code')->all();
    $this->pfChallan = fn (int $month) => collect(app(ComplianceReports::class)->plan($this->company, ReportPeriod::month(2026, $month)))
        ->first(fn ($i) => $i['definition']->key === 'pf_challan' && $i['target']['id'] === $this->mh->id);
});

it('refuses to close a profile before a finalised month and allows closing after it', function () {
    $run = ($this->finalise)(9);
    expect(($this->codes)($run))->toContain('PF_EE');

    expect(fn () => $this->epf->withAuditReason('Closed in error')->update(['effective_to' => '2026-08-31']))
        ->toThrow(RuntimeException::class, 'This would change whether EPF applied in Sep 2026')
        ->and($this->epf->fresh()->effective_to)->toBeNull();

    $this->epf->refresh()->withAuditReason('EPF code surrendered from November')->update(['effective_to' => '2026-10-31']);
    expect($this->epf->fresh()->effective_to->toDateString())->toBe('2026-10-31');
});

it('refuses to reopen a closed profile over a finalised month calculated without it, in the model and the page', function () {
    $this->epf->update(['effective_to' => '2026-08-31']);
    $run = ($this->finalise)(9);
    expect(($this->codes)($run))->not->toContain('PF_EE');

    expect(fn () => $this->epf->fresh()->update(['effective_to' => null]))
        ->toThrow(RuntimeException::class, 'This would change whether EPF applied in Sep 2026');

    Livewire::test(ManageEstablishmentStatutoryProfiles::class)
        ->callAction(TestAction::make('reopen')->table($this->epf->fresh()), ['reason' => 'Still registered with EPFO']);
    expect($this->epf->fresh()->effective_to->toDateString())->toBe('2026-08-31');
});

it("refuses to delete an establishment's last profile when that would hand a finalised month to the company settings", function () {
    $this->esi->update(['effective_to' => '2026-06-30']);
    $this->esi->fresh()->delete();
    $this->epf->update(['effective_to' => '2026-06-30']);
    $run = ($this->finalise)(9); // profiles decide, and none is in force on 30 Sep: nothing applies
    expect(($this->codes)($run))->not->toContain('PF_EE')->not->toContain('ESI_EE')->not->toContain('PT');

    // The profile's own window (to June) holds no finalised payroll, but without any profile the company settings
    // (EPF, ESI, PT and TDS on) would decide September.
    expect($this->epf->fresh()->usedByLockedPayroll())->toBeFalse()
        ->and(fn () => $this->epf->fresh()->delete())->toThrow(RuntimeException::class, 'This would change whether EPF, ESI, PT, TDS applied in Sep 2026')
        ->and(EstablishmentStatutoryProfile::query()->whereKey($this->epf->id)->exists())->toBeTrue();
});

it('keeps the report of a finalised month as payroll decided it, and lets later months follow a change', function () {
    ($this->finalise)(9);
    expect(($this->pfChallan)(9)['applicable'])->toBeTrue();

    expect(fn () => $this->epf->update(['effective_to' => '2026-08-31']))->toThrow(RuntimeException::class);
    expect(($this->pfChallan)(9)['applicable'])->toBeTrue();

    $this->epf->refresh()->update(['effective_to' => '2026-10-31']);
    expect(($this->pfChallan)(9)['applicable'])->toBeTrue()
        ->and(($this->pfChallan)(10)['applicable'])->toBeTrue()
        ->and(($this->pfChallan)(11)['applicable'])->toBeFalse()
        ->and(($this->pfChallan)(11)['reason'])->toContain('EPF does not apply to this establishment under its statutory profile on 30 Nov 2026');

    // Reopening after the last finalised month is a legitimate correction.
    $this->epf->refresh()->update(['effective_to' => null]);
    expect(($this->pfChallan)(11)['applicable'])->toBeTrue();
});

it('decides by effective dates: a back-dated profile over a finalised month is refused unless it changes nothing there', function () {
    $this->esi->update(['effective_to' => '2026-08-31']);
    $run = ($this->finalise)(9);
    expect(($this->codes)($run))->not->toContain('ESI_EE');

    $row = ['establishment_id' => $this->mh->id, 'statute' => 'ESI', 'applicable' => true, 'effective_from' => '2026-09-01'];
    expect(fn () => EstablishmentStatutoryProfile::query()->create($row))->toThrow(RuntimeException::class, 'This would change whether ESI applied in Sep 2026');

    $exempt = EstablishmentStatutoryProfile::query()->create(['applicable' => false, 'effective_to' => '2026-09-30', 'reason' => 'Exempt for September'] + $row);
    $later = EstablishmentStatutoryProfile::query()->create(['effective_from' => '2026-10-01'] + $row);
    expect($exempt->exists)->toBeTrue()->and($later->exists)->toBeTrue();
});

it('holds only the establishment and the tenant whose payroll is finalised, whoever makes the change', function () {
    ($this->finalise)(9);

    // Another establishment of the same company: no payroll there, so its history is free to change.
    $bengaluru = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->ka->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $bengaluru->update(['effective_to' => '2026-08-31']);
    expect($bengaluru->fresh()->effective_to->toDateString())->toBe('2026-08-31');

    // A user who sees no employees cannot slip past the guard: it reads payroll regardless of access scope.
    $this->actingAs(tenantUser($this->tenant, ['compliance.registrations.view', 'compliance.registrations.manage']));
    expect(fn () => $this->epf->fresh()->update(['effective_to' => '2026-08-31']))->toThrow(RuntimeException::class, 'EPF applied in Sep 2026');

    // Another tenant's establishment is not held by this tenant's payroll, and this tenant stays held.
    $other = provisionTenant();
    actAsTenant($other);
    $company = Company::query()->first() ?? Company::factory()->create();
    $entity = LegalEntity::query()->where('company_id', $company->id)->first();
    $plant = Establishment::query()->where('company_id', $company->id)->first()
        ?? Establishment::query()->create(['legal_entity_id' => $entity->id, 'code' => 'PLANT', 'name' => 'Plant', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $theirs = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $plant->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $theirs->update(['effective_to' => '2026-08-31']);
    expect($theirs->fresh()->effective_to->toDateString())->toBe('2026-08-31');

    actAsTenant($this->tenant);
    expect(fn () => $this->epf->fresh()->update(['effective_to' => '2026-08-31']))->toThrow(RuntimeException::class, 'EPF applied in Sep 2026');
});

it('audits each permitted change with its reason and records nothing for a refused one', function () {
    ($this->finalise)(9);
    $events = fn () => AuditEvent::query()->where('entity_type', EstablishmentStatutoryProfile::class)->where('entity_id', (string) $this->epf->id)->count();
    $before = $events();

    expect(fn () => $this->epf->withAuditReason('Closed in error')->update(['effective_to' => '2026-08-31']))->toThrow(RuntimeException::class);
    expect($events())->toBe($before);

    $this->epf->refresh()->withAuditReason('EPF code surrendered from November')->update(['effective_to' => '2026-10-31']);
    $event = AuditEvent::query()->where('entity_type', EstablishmentStatutoryProfile::class)->where('entity_id', (string) $this->epf->id)->where('action', 'UPDATE')->latest('id')->first();
    $change = AuditEventChange::query()->where('audit_event_id', $event->id)->where('field', 'effective_to')->sole();
    expect($events())->toBe($before + 1)
        ->and($event->reason)->toBe('EPF code surrendered from November')
        ->and($change->before)->toBeNull()
        ->and((string) $change->after)->toContain('2026-10-31');
});

it('leaves the finalised run, its lines and its rule versions untouched by profile changes', function () {
    $run = ($this->finalise)(9);
    $snapshot = fn () => [
        PayrollRun::query()->whereKey($run->id)->value('rule_versions'),
        PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->pluck('id'))->orderBy('id')->get(['code', 'amount', 'basis'])->toArray(),
    ];
    $before = $snapshot();

    expect(fn () => $this->epf->update(['effective_to' => '2026-08-31']))->toThrow(RuntimeException::class);
    $this->epf->refresh()->update(['effective_to' => '2026-10-31']);
    $this->epf->refresh()->update(['effective_to' => null]);

    expect($snapshot())->toBe($before)
        ->and(PayrollRun::query()->whereKey($run->id)->value('status'))->toBe('finalized');
});

it('lets payroll not yet past review follow a correction, then holds it once approved', function () {
    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->admin), $this->admin);
    expect(($this->codes)($run))->toContain('PF_EE');

    // Calculated but not approved: the correction is allowed and a recalculation applies it.
    $this->epf->update(['effective_to' => '2026-08-31']);
    $run = $this->runs->calculate($run->fresh(), $this->admin);
    expect(($this->codes)($run))->not->toContain('PF_EE');

    PayrollRun::query()->whereKey($run->id)->update(['status' => 'approved']);
    expect(fn () => $this->epf->fresh()->update(['effective_to' => null]))->toThrow(RuntimeException::class, 'This would change whether EPF applied in Sep 2026');
});

it('decides a pack as payroll did when the company has no saved statutory settings', function () {
    CompanyStatutoryProfile::query()->where('company_id', $this->company->id)->delete();
    $staff = salariedEmployee(240000);
    app(EstablishmentAssignments::class)->assign($staff, $this->ka, '2025-01-01', 'Bengaluru');
    $run = ($this->finalise)(9);

    // Bengaluru has no profiles and the company no settings: payroll applies the defaults (EPF on), so must the pack.
    expect(PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->where('employee_id', $staff->id)->pluck('id'))->pluck('code')->all())->toContain('PF_EE')
        ->and(collect(app(ComplianceReports::class)->plan($this->company, ReportPeriod::month(2026, 9)))
            ->first(fn ($i) => $i['definition']->key === 'pf_challan' && $i['target']['id'] === $this->ka->id)['applicable'])->toBeTrue();
});

it('does not hold the move from the company settings to establishment profiles (ADR-0001)', function () {
    $staff = salariedEmployee(240000);
    app(EstablishmentAssignments::class)->assign($staff, $this->ka, '2025-01-01', 'Bengaluru');
    $run = ($this->finalise)(9);
    expect($run->entries()->where('employee_id', $staff->id)->value('inputs')['statutory_context']['profile_source'])->toBe('legacy_company_profile');

    $this->artisan('peopleos:legal-entities:backfill', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    expect(EstablishmentStatutoryProfile::query()->where('establishment_id', $this->ka->id)->count())->toBe(5);
});

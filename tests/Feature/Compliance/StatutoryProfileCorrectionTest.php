<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\StatutoryProfileCorrection;
use App\Domain\Compliance\Services\StatutoryProfileCorrections;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

// C12-W: correcting an establishment statutory profile for a month whose payroll is already finalised. The original
// payroll and context are kept; a different person approves; only a correction that moves no payroll amount can be
// applied; everything is audited and disclosed in later packs.
beforeEach(function () {
    $this->travelTo('2026-10-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->requester = tenantUser($this->tenant, ['compliance.registrations.view', 'compliance.registrations.manage']);
    $this->reviewer = tenantUser($this->tenant, ['compliance.registrations.view', 'compliance.profile_corrections.approve']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $entity = LegalEntity::query()->where('company_id', $this->company->id)->sole();
    $this->ka = Establishment::query()->where('company_id', $this->company->id)->sole();
    $this->ka->update(['state' => 'KA', 'effective_from' => '2020-01-01']);
    $this->mh = Establishment::query()->create(['legal_entity_id' => $entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $this->epf = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->mh->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $this->esi = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->mh->id, 'statute' => 'ESI', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $this->employee = salariedEmployee(240000);
    app(EstablishmentAssignments::class)->assign($this->employee, $this->mh, '2025-01-01', 'Pune');

    $this->corrections = app(StatutoryProfileCorrections::class);
    $this->finalise = function (int $month): PayrollRun {
        $runs = app(PayrollRuns::class);
        $run = $runs->calculate($runs->open($this->company, 2026, $month, $this->admin), $this->admin);
        PayrollRun::query()->whereKey($run->id)->update(['status' => 'finalized']);

        return $run->fresh();
    };
    $this->lines = fn (PayrollRun $run) => PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->pluck('id'))->orderBy('id')->get(['code', 'amount', 'basis'])->toArray();
    $this->pfChallan = fn (int $month) => collect(app(ComplianceReports::class)->plan($this->company, ReportPeriod::month(2026, $month)))
        ->first(fn ($i) => $i['definition']->key === 'pf_challan' && $i['target']['id'] === $this->mh->id);
    // Everyone at Pune exempt from EPF: EPF "applies" to the establishment but payroll carries no EPF amount.
    $this->exemptFromEpf = fn () => EmployeeStatutoryDetail::query()->create(['employee_id' => $this->employee->id, 'pf_applicable' => false]);
});

it('records a request for a finalised month with its original context and type, changing nothing yet', function () {
    ($this->exemptFromEpf)();
    $run = ($this->finalise)(9);
    $lines = ($this->lines)($run);

    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered on 31 Aug; profile not closed in time', 'EPFO letter 2026/123');

    expect($correction->status)->toBe('requested')
        ->and($correction->classification)->toBe(StatutoryProfileCorrection::CONFIGURATION_ONLY)
        ->and($correction->original)->toMatchArray(['id' => $this->epf->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01', 'effective_to' => null])
        ->and($correction->affected)->toHaveCount(1)
        ->and($correction->affected[0])->toMatchArray(['period_end' => '2026-09-30', 'statute' => 'EPF', 'before' => true, 'after' => false, 'run_ids' => [$run->id], 'amount' => 0, 'impact' => false])
        ->and($correction->required_actions)->toBe([])
        // Nothing changes until it is approved and applied: the profile, payroll and the guard all stand.
        ->and($this->epf->fresh()->effective_to)->toBeNull()
        ->and(($this->lines)($run))->toBe($lines)
        ->and(fn () => $this->epf->fresh()->update(['effective_to' => '2026-08-31']))->toThrow(RuntimeException::class, 'or request a correction');
});

it('classifies a correction that would move payroll amounts and names the follow-up decisions', function () {
    $run = ($this->finalise)(9); // EPF deducted

    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'Not covered from September');
    $reopen = $this->corrections->requestNew(['establishment_id' => $this->mh->id, 'statute' => 'LWF', 'applicable' => true, 'effective_from' => '2026-09-01'], $this->requester, 'Maharashtra LWF applies from September');

    expect($correction->classification)->toBe(StatutoryProfileCorrection::PAYROLL_IMPACT)
        ->and($correction->affected[0]['amount'])->toBeGreaterThan(0)
        ->and($correction->required_actions[0])->toContain('EPF Sep 2026')->toContain('C12-D1')
        // A statute payroll did not apply cannot be judged without recalculating: impact.
        ->and($reopen->classification)->toBe(StatutoryProfileCorrection::PAYROLL_IMPACT)
        ->and($reopen->affected[0])->toMatchArray(['statute' => 'LWF', 'before' => false, 'after' => true, 'impact' => true])
        ->and($reopen->action)->toBe('create')->and($reopen->profile_id)->toBeNull();
});

it('refuses requests that need no correction, have no reason or come from someone without the right', function () {
    ($this->finalise)(9);

    expect(fn () => $this->corrections->requestChange($this->epf, ['effective_to' => '2026-10-31'], $this->requester, 'Surrendered from November'))
        ->toThrow(RuntimeException::class, 'Make the change directly')
        ->and(fn () => $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, ' '))->toThrow(RuntimeException::class, 'needs a reason')
        ->and(fn () => $this->corrections->requestChange($this->epf, ['statute' => 'ESI'], $this->requester, 'x'))->toThrow(RuntimeException::class, 'changes only')
        ->and(fn () => $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->reviewer, 'x'))->toThrow(RuntimeException::class, 'compliance.registrations.manage')
        ->and(StatutoryProfileCorrection::query()->count())->toBe(0);
});

it('keeps a request awaiting approval inert, and lets only a different person with the right review it', function () {
    ($this->exemptFromEpf)();
    ($this->finalise)(9);
    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered');

    expect(fn () => $this->corrections->apply($correction, $this->requester))->toThrow(RuntimeException::class, 'Only an approved correction')
        ->and(fn () => $this->corrections->approve($correction, $this->requester, 'Looks right'))->toThrow(RuntimeException::class, 'compliance.profile_corrections.approve')
        ->and(fn () => $this->corrections->approve($correction, $this->reviewer, ''))->toThrow(RuntimeException::class, 'needs a note')
        ->and(($this->pfChallan)(9)['applicable'])->toBeTrue()
        ->and($this->epf->fresh()->effective_to)->toBeNull();

    // A reviewer who is also the requester is refused even with the right.
    $both = tenantUser($this->tenant, ['compliance.registrations.manage', 'compliance.profile_corrections.approve']);
    $own = $this->corrections->requestChange($this->esi->fresh(), ['applicable' => true, 'effective_to' => '2026-08-31'], $both, 'Test');
    expect(fn () => $this->corrections->approve($own, $both, 'Mine'))->toThrow(RuntimeException::class, 'cannot review it');
});

it('lets only someone with the registrations right apply an approved correction, never its reviewer alone', function () {
    ($this->exemptFromEpf)();
    ($this->finalise)(9);
    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered');
    $this->corrections->approve($correction, $this->reviewer, 'Matches the EPFO letter');

    expect(fn () => $this->corrections->apply($correction->fresh(), $this->reviewer))->toThrow(RuntimeException::class, 'compliance.registrations.manage')
        ->and(fn () => $this->corrections->apply($correction->fresh(), tenantUser($this->tenant, ['compliance.registrations.view'])))->toThrow(RuntimeException::class, 'compliance.registrations.manage')
        ->and($correction->fresh()->status)->toBe('approved')
        ->and($this->epf->fresh()->effective_to)->toBeNull();
});

it('applies only the first of two approved corrections of the same profile; the second finds the profile changed', function () {
    ($this->exemptFromEpf)();
    ($this->finalise)(9);
    $first = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered on 31 Aug');
    $second = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'The same surrender, raised twice');
    foreach ([$first, $second] as $correction) {
        $this->corrections->approve($correction, $this->reviewer, 'Matches the EPFO letter');
    }

    $this->corrections->apply($first->fresh(), $this->requester);
    expect(fn () => $this->corrections->apply($second->fresh(), $this->requester))->toThrow(RuntimeException::class, 'profile changed')
        ->and([$first->fresh()->status, $second->fresh()->status])->toBe(['applied', 'approved'])
        ->and($this->epf->fresh()->effective_to->toDateString())->toBe('2026-08-31');
});

it('rejects a correction for good, leaving the profile, payroll and reports as they were', function () {
    ($this->exemptFromEpf)();
    $run = ($this->finalise)(9);
    $lines = ($this->lines)($run);
    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered');

    $this->corrections->reject($correction, $this->reviewer, 'The code was surrendered on 30 Sep, not 31 Aug');

    expect($correction->fresh()->status)->toBe('rejected')
        ->and($correction->fresh()->review_note)->toBe('The code was surrendered on 30 Sep, not 31 Aug')
        ->and(fn () => $this->corrections->approve($correction->fresh(), $this->reviewer, 'Changed my mind'))->toThrow(RuntimeException::class, 'Only a requested correction')
        ->and(fn () => $this->corrections->apply($correction->fresh(), $this->requester))->toThrow(RuntimeException::class, 'Only an approved correction')
        ->and($this->epf->fresh()->effective_to)->toBeNull()
        ->and(($this->lines)($run))->toBe($lines)
        ->and(($this->pfChallan)(9)['applicable'])->toBeTrue();
});

it('applies an approved configuration-only correction, keeping the original retrievable and the corrected result distinguishable', function () {
    ($this->exemptFromEpf)();
    $run = ($this->finalise)(9);
    $snapshot = [PayrollRun::query()->whereKey($run->id)->value('rule_versions'), ($this->lines)($run)];
    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered on 31 Aug', 'EPFO letter 2026/123');
    $this->corrections->approve($correction, $this->reviewer, 'Matches the EPFO letter');

    $profile = $this->corrections->apply($correction->fresh(), $this->requester);

    $correction->refresh();
    expect($correction->status)->toBe('applied')
        ->and($correction->applied_by)->toBe($this->requester->id)
        ->and($profile->effective_to->toDateString())->toBe('2026-08-31')
        // The original stays retrievable: the request's own record, and finalised payroll untouched.
        ->and($correction->original['effective_to'])->toBeNull()
        ->and($correction->affected[0]['before'])->toBeTrue()
        ->and([PayrollRun::query()->whereKey($run->id)->value('rule_versions'), ($this->lines)($run)])->toBe($snapshot)
        ->and(PayrollRun::query()->whereKey($run->id)->value('status'))->toBe('finalized');

    // The corrected outcome is distinguishable: a pack generated now says EPF does not apply, and says why.
    $september = ($this->pfChallan)(9);
    expect($september['applicable'])->toBeFalse()
        ->and($september['reason'])->toContain("correction #{$correction->id}")->toContain('EPF changed from applying to not applying for Sep 2026')
        ->and(($this->pfChallan)(8)['reason'] ?? '')->not->toContain('correction #');

    // Once applied it is done: no second application, and the request's record cannot be rewritten or removed.
    expect(fn () => $this->corrections->apply($correction, $this->requester))->toThrow(RuntimeException::class, 'Only an approved correction')
        ->and(fn () => $correction->update(['reason' => 'Edited']))->toThrow(RuntimeException::class, 'written once')
        ->and(fn () => $correction->delete())->toThrow(RuntimeException::class, 'kept as history');
});

it('approves but never applies a correction with payroll impact until its treatment is decided', function () {
    $run = ($this->finalise)(9);
    $lines = ($this->lines)($run);
    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'Not covered from September');
    $this->corrections->approve($correction, $this->reviewer, 'The establishment was not covered');

    expect(fn () => $this->corrections->apply($correction->fresh(), $this->requester))->toThrow(RuntimeException::class, 'C12-D1')
        ->and($correction->fresh()->status)->toBe('approved')
        ->and($this->epf->fresh()->effective_to)->toBeNull()
        ->and(($this->lines)($run))->toBe($lines)
        ->and(($this->pfChallan)(9)['applicable'])->toBeTrue();
});

it('refuses to approve or apply a correction once the profile or payroll moved on', function () {
    ($this->exemptFromEpf)();
    ($this->finalise)(9);
    $stale = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered');
    $approved = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'Same, second request');
    $this->corrections->approve($approved, $this->reviewer, 'Fine');

    // October payroll is finalised after the requests: closing at 31 Aug would now also change October.
    ($this->finalise)(10);
    expect(fn () => $this->corrections->approve($stale, $this->reviewer, 'Fine'))->toThrow(RuntimeException::class, 'Raise the correction again')
        ->and(fn () => $this->corrections->apply($approved->fresh(), $this->requester))->toThrow(RuntimeException::class, 'Raise the correction again')
        ->and($this->epf->fresh()->effective_to)->toBeNull();

    // A profile changed by an allowed edit after approval also makes the approval stale.
    $again = $this->corrections->requestChange($this->epf->fresh(), ['effective_to' => '2026-09-30'], $this->requester, 'Surrendered on 30 Sep');
    $this->corrections->approve($again, $this->reviewer, 'Fine');
    $this->epf->fresh()->update(['effective_to' => '2026-12-31']);
    expect(fn () => $this->corrections->apply($again->fresh(), $this->requester))->toThrow(RuntimeException::class, 'profile changed');
});

it('audits every step and the profile change itself, with reasons', function () {
    ($this->exemptFromEpf)();
    ($this->finalise)(9);
    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered');
    $this->corrections->approve($correction, $this->reviewer, 'Matches the EPFO letter');
    $this->corrections->apply($correction->fresh(), $this->requester);

    $events = AuditEvent::query()->where('entity_type', StatutoryProfileCorrection::class)->where('entity_id', (string) $correction->id)->orderBy('id')->get();
    expect($events->map(fn (AuditEvent $e) => $e->action->value)->all())->toBe(['CREATE', 'UPDATE', 'UPDATE'])
        ->and($events[1]->reason)->toBe('Matches the EPFO letter')
        ->and($events[2]->reason)->toBe("Correction #{$correction->id}: EPF code surrendered")
        ->and(AuditEvent::query()->where('entity_type', EstablishmentStatutoryProfile::class)->where('entity_id', (string) $this->epf->id)->latest('id')->value('reason'))
        ->toBe("Correction #{$correction->id}: EPF code surrendered");
});

it('keeps corrections inside their tenant and establishment', function () {
    ($this->exemptFromEpf)();
    ($this->finalise)(9);
    $correction = $this->corrections->requestChange($this->epf, ['effective_to' => '2026-08-31'], $this->requester, 'EPF code surrendered');
    $this->corrections->approve($correction, $this->reviewer, 'Fine');

    // An approved correction never lets a different change through, even handed to the model directly: it must match
    // exactly (here another statute, and the right statute with another date).
    $other = $this->esi->fresh();
    $other->applyingCorrection = $correction->fresh();
    $wrongDate = $this->epf->fresh();
    $wrongDate->applyingCorrection = $correction->fresh();
    expect(fn () => $other->update(['effective_to' => '2026-08-31']))->toThrow(RuntimeException::class, 'ESI applied in Sep 2026')
        ->and(fn () => $wrongDate->update(['effective_to' => '2026-07-31']))->toThrow(RuntimeException::class, 'EPF applied in Sep 2026');

    $this->corrections->apply($correction->fresh(), $this->requester);

    // Another establishment's reports carry no disclosure, and its profiles are not covered by this correction.
    $ka = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->ka->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01']);
    expect($this->corrections->disclosures($this->ka->id, 'EPF', ['2026-09-30']))->toBe([])
        ->and($this->corrections->disclosures($this->mh->id, 'EPF', ['2026-09-30']))->toHaveCount(1);

    // Another tenant sees none of it.
    $elsewhere = provisionTenant();
    actAsTenant($elsewhere);
    expect(StatutoryProfileCorrection::query()->count())->toBe(0)
        ->and(app(StatutoryProfileCorrections::class)->disclosures($this->mh->id, 'EPF', ['2026-09-30']))->toBe([]);
    actAsTenant($this->tenant);
    expect(StatutoryProfileCorrection::query()->count())->toBe(1)->and($ka->exists)->toBeTrue();
});

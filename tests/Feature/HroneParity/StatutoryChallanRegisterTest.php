<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\StatutoryChallan;
use App\Domain\Compliance\Models\StatutoryChallanAllocation;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\TdsAnnualLedger;
use App\Domain\Compliance\Models\TdsProfile;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Compliance\Services\StatutoryChallans;
use App\Domain\Compliance\Services\Tds\TdsQuarterlyReturns;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Pages\ComplianceReportsPage;
use App\Filament\Pages\PayrollDesk;
use App\Filament\Pages\StatutoryChallansPage;
use App\Http\Controllers\ParityFileController;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Compliance/ComplianceTestHelpers.php';

/*
 | HROne parity PO-13 (matrix 25 §25.6.12 #1, #4, #8, #10–#13, #15–#17): the statutory challan register and its financial
 | year × month grid — states, recording a payment (never by the return's approver), TDS allocation from finalized payroll
 | only (D-PO7), the quarterly statement picking the register's challans, coverage display, scope and permissions.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->establishment] = complianceCompany();
    TdsProfile::query()->create(['legal_entity_id' => $this->entity->id, 'tan_registration_id' => StatutoryRegistration::query()->where('registration_type', 'tan')->sole()->id,
        'responsible_person_name' => 'R. Mehta', 'responsible_person_designation' => 'Director', 'responsible_person_pan' => 'AAAPM1234C']);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->payrollApprover = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    ['generator' => $this->generator, 'approver' => $this->approver, 'filer' => $this->filer] = complianceUsers($this->tenant);
    $this->verifier = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.returns.file']);

    // One PF member at the wage ceiling, and two higher earners outside PF (one with a PAN); every salary may carry TDS.
    $this->anita = statutoryEmployee(600000, $this->establishment, '100200300400');
    $this->withPan = statutoryEmployee(2400000, $this->establishment, null, ['pan' => 'ABCDE1234F', 'pf_applicable' => false]);
    $this->noPan = statutoryEmployee(2400000, $this->establishment, null, ['pf_applicable' => false]);
    $this->september = finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);

    $this->challans = app(StatutoryChallans::class);
    $this->returns = app(StatutoryReturns::class);
    $this->epf = app(EpfReturns::class);
    $this->pfPayment = fn (array $overrides = []) => $overrides + ['statute' => 'pf', 'unit_id' => $this->establishment->id, 'month' => '2026-09', 'challan_number' => 'TRRN2026090001',
        'paid_on' => '2026-10-04', 'payment_mode' => 'net_banking', 'amount' => 3600, 'interest' => 0, 'penalty' => 0];
    $this->tdsPayment = fn (array $overrides = []) => $overrides + ['statute' => 'tds', 'unit_id' => $this->entity->id, 'month' => '2026-09', 'challan_number' => '00123',
        'bsr_code' => '0510001', 'paid_on' => '2026-10-04', 'payment_mode' => 'net_banking', 'interest' => 0, 'penalty' => 0];
    $this->deducted = fn ($employee) => round((float) PayrollEntryLine::query()->where('code', 'TDS')
        ->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $this->september->id)->where('employee_id', $employee->id))->sum('amount'), 2);
    $this->taxed = fn () => collect([$this->anita, $this->withPan, $this->noPan])->filter(fn ($e) => ($this->deducted)($e) > 0)->map->id->sort()->values()->all();
    $this->deductedTotal = fn () => round(collect([$this->anita, $this->withPan, $this->noPan])->sum(fn ($e) => ($this->deducted)($e)), 2);
});

it('shows each month of the financial year as not generated, generated, approved, paid and filed', function () {
    $cell = fn (string $month) => collect($this->challans->grid('pf', '2026-27'))->firstWhere('unit.id', $this->establishment->id)['cells'][$month];

    expect(array_map(fn (CarbonImmutable $m) => $m->format('Y-m'), $this->challans->months('2026-27')))->toBe(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'])
        ->and($cell('2026-09')['state'])->toBe('not_generated')
        ->and($cell('2026-10')['state'])->toBe('not_generated')
        ->and($cell('2026-11')['state'])->toBe('not_due')
        ->and(collect($this->challans->grid('pf', '2026-27'))->firstWhere('unit.id', $this->establishment->id)['registration'])->toEndWith('5000');

    $return = $this->epf->generate($this->establishment, 2026, 9, $this->generator);
    expect($cell('2026-09')['state'])->toBe('generated')->and($cell('2026-09')['return']->id)->toBe($return->id);

    $return = $this->returns->approve($this->returns->validate($return, $this->generator), $this->approver);
    expect($return->status)->toBe('approved')->and($cell('2026-09')['state'])->toBe('approved');

    $challan = $this->challans->record(($this->pfPayment)(), $this->filer);
    expect($cell('2026-09')['state'])->toBe('paid')
        ->and($cell('2026-09')['paid'])->toBe(3600.0)
        ->and($challan->statutory_return_id)->toBe($return->id)
        ->and($challan->financial_year)->toBe('2026-27');

    $return = $this->returns->recordSubmission($this->returns->export($return, $this->generator), $this->filer, 'TRRN2026090001', now()->subHour());
    expect($cell('2026-09')['state'])->toBe('filed')->and($cell('2026-09')['payment_missing'])->toBeFalse();

    // Another establishment's month stays on its own state.
    $other = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'MYS', 'name' => 'Mysuru', 'state' => 'KA', 'effective_from' => '2020-01-01']);
    expect(collect($this->challans->grid('pf', '2026-27'))->firstWhere('unit.id', $other->id)['cells']['2026-09']['state'])->toBe('not_generated');

    // The page: tabs, the grid with each cell's state, the cell panel with the return and the challan.
    Filament::getPanel('admin')->boot();
    $this->actingAs($this->filer);
    $this->get(StatutoryChallansPage::getUrl())->assertOk()->assertSee('Provident fund')->assertSee('Income tax')->assertSee('2026-27')
        ->assertSee('data-cell="'.$this->establishment->id.'|2026-09" data-state="filed"', false)
        ->assertSee('data-cell="'.$this->establishment->id.'|2026-11" data-state="not_due"', false);
    Livewire::test(StatutoryChallansPage::class)->call('selectCell', $this->establishment->id, '2026-09')
        ->assertSee('TRRN2026090001')->assertSee($return->label())->assertSee('3,600.00')
        ->assertActionVisible('viewChallan', ['challan' => $challan->id])
        ->assertActionHidden('verifyChallan', ['challan' => $challan->id])   // the filer recorded it
        ->assertActionHidden('generateReturn', ['statute' => 'pf', 'unit' => $this->establishment->id, 'month' => '2026-09']);   // filed, and no generate right
});

it('records a payment through the wizard with its receipt, keeps it as recorded and lets a second person verify it', function () {
    Filament::getPanel('admin')->boot();
    $return = $this->returns->approve($this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator), $this->generator), $this->approver);
    $this->actingAs($this->filer);

    Livewire::test(StatutoryChallansPage::class)
        ->callAction('recordPayment', data: ($this->pfPayment)(['alias' => 'September PF', 'acknowledgement_number' => 'CRN998877', 'bank_name' => 'State Bank of India', 'branch' => 'MG Road',
            'interest' => 18.5, 'penalty' => 25, 'receipt' => UploadedFile::fake()->createWithContent('trrn-receipt.pdf', "%PDF-1.4\n%test\n"), 'notes' => 'Paid on the EPFO portal']),
            arguments: ['statute' => 'pf', 'unit' => $this->establishment->id, 'month' => '2026-09'])
        ->assertHasNoActionErrors()->assertNotified('Challan recorded');

    $challan = StatutoryChallan::query()->sole();
    expect($challan->statute)->toBe('pf')
        ->and($challan->establishment_id)->toBe($this->establishment->id)
        ->and($challan->company_id)->toBe($this->company->id)
        ->and($challan->statutory_return_id)->toBe($return->id)
        ->and($challan->statutory_registration_id)->toBe(StatutoryRegistration::query()->where('registration_type', 'epf_establishment_code')->sole()->id)
        ->and($challan->period_month->format('Y-m'))->toBe('2026-09')
        ->and($challan->alias)->toBe('September PF')
        ->and($challan->acknowledgement_number)->toBe('CRN998877')
        ->and($challan->paid_on->toDateString())->toBe('2026-10-04')
        ->and($challan->bank_name)->toBe('State Bank of India')->and($challan->branch)->toBe('MG Road')
        ->and($challan->total())->toBe(3643.5)
        ->and($challan->status)->toBe('recorded')
        ->and($challan->recorded_by)->toBe($this->filer->id)
        ->and($challan->receipt_name)->toBe('trrn-receipt.pdf')
        ->and($challan->receipt_path)->toStartWith('tenants/'.$this->tenant->id.'/statutory-challans/');
    Storage::disk('local')->assertExists($challan->receipt_path);
    expect(AuditEvent::withoutTenancy()->where('module', 'compliance')->where('entity_id', (string) $challan->id)->where('action', 'CREATE')->exists())->toBeTrue();

    // The receipt opens through a signed, authorised, audited link only.
    $url = ParityFileController::url('statutory-challan', $challan, 0);
    $this->get($url)->assertOk();
    expect(AuditEvent::withoutTenancy()->where('entity_id', (string) $challan->id)->where('action', 'ATTACHMENT_DOWNLOADED')->exists())->toBeTrue();
    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    $this->get($url)->assertForbidden();
    $this->actingAs($this->filer);

    // Never edited, never deleted, never recorded twice.
    expect(fn () => $challan->update(['amount' => 1]))->toThrow(RuntimeException::class, 'not edited')
        ->and(fn () => $challan->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => $this->challans->record(($this->pfPayment)(['challan_number' => ' trrn2026090001 ']), $this->filer))->toThrow(RuntimeException::class, 'already recorded');

    // Verification is someone else's; a verified challan is not cancelled.
    expect(fn () => $this->challans->verify($challan->refresh(), $this->filer))->toThrow(RuntimeException::class, 'cannot verify');
    $this->actingAs($this->verifier);
    Livewire::test(StatutoryChallansPage::class)->call('selectCell', $this->establishment->id, '2026-09')
        ->assertActionVisible('verifyChallan', ['challan' => $challan->id])
        ->callAction('verifyChallan', data: ['note' => 'Matches the bank statement'], arguments: ['challan' => $challan->id])->assertNotified('Challan verified');
    expect($challan->refresh()->status)->toBe('verified')->and($challan->verified_by)->toBe($this->verifier->id)
        ->and(AuditEvent::withoutTenancy()->where('entity_id', (string) $challan->id)->where('action', 'REVIEWED')->exists())->toBeTrue()
        ->and(fn () => $this->challans->cancel($challan, $this->verifier, 'Wrong'))->toThrow(RuntimeException::class, 'not yet verified');

    // A mistaken one is cancelled with a reason, stays in the register and frees its number.
    $wrong = $this->challans->record(($this->pfPayment)(['challan_number' => 'TRRN-TYPO', 'amount' => 10]), $this->filer);
    expect(fn () => $this->challans->cancel($wrong, $this->filer, ' '))->toThrow(RuntimeException::class, 'reason');
    $this->challans->cancel($wrong, $this->filer, 'Typed the wrong TRRN');
    expect($wrong->refresh()->status)->toBe('cancelled')->and($wrong->cancel_reason)->toBe('Typed the wrong TRRN')->and($wrong->active_key)->toBeNull()
        ->and($this->challans->cell('pf', $this->establishment, CarbonImmutable::parse('2026-09-01'))['paid'])->toBe(3643.5)
        ->and($this->challans->record(($this->pfPayment)(['challan_number' => 'TRRN-TYPO', 'amount' => 10]), $this->filer)->status)->toBe('recorded');

    // Refusals: future month, a payment date in the future or before the month, a missing amount or mode.
    expect(fn () => $this->challans->record(($this->pfPayment)(['month' => '2026-11', 'challan_number' => 'X1']), $this->filer))->toThrow(RuntimeException::class, 'future')
        ->and(fn () => $this->challans->record(($this->pfPayment)(['paid_on' => '2026-10-09', 'challan_number' => 'X2']), $this->filer))->toThrow(RuntimeException::class, 'cannot be in the future')
        ->and(fn () => $this->challans->record(($this->pfPayment)(['paid_on' => '2026-08-30', 'challan_number' => 'X3']), $this->filer))->toThrow(RuntimeException::class, 'before Sep 2026')
        ->and(fn () => $this->challans->record(($this->pfPayment)(['amount' => 0, 'challan_number' => 'X4']), $this->filer))->toThrow(RuntimeException::class, 'more than zero')
        ->and(fn () => $this->challans->record(($this->pfPayment)(['payment_mode' => 'barter', 'challan_number' => 'X5']), $this->filer))->toThrow(RuntimeException::class, 'how the challan was paid');
});

it('never lets the approver of a return record its challan payment', function () {
    $approverFiler = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.returns.approve', 'compliance.returns.file']);
    $return = $this->returns->approve($this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator), $this->generator), $approverFiler);

    expect(fn () => $this->challans->record(($this->pfPayment)(), $approverFiler))->toThrow(RuntimeException::class, 'Separation of duties');

    Filament::getPanel('admin')->boot();
    $this->actingAs($approverFiler);
    Livewire::test(StatutoryChallansPage::class)
        ->callAction('recordPayment', data: ($this->pfPayment)(), arguments: ['statute' => 'pf', 'unit' => $this->establishment->id, 'month' => '2026-09'])
        ->assertNotified('Not recorded');
    expect(StatutoryChallan::query()->count())->toBe(0)
        ->and((new StatutoryChallansPage)->returnHint('pf', $this->establishment->id, '2026-09'))->toContain('You approved it');

    // Someone else with the filing right records it.
    expect($this->challans->record(($this->pfPayment)(), $this->filer)->statutory_return_id)->toBe($return->id);
});

it('allocates a TDS challan per employee from finalized payroll only, never more than was deducted and never editing it', function () {
    $linesBefore = PayrollEntryLine::query()->where('code', 'TDS')->orderBy('id')->pluck('amount', 'id')->all();
    $withPan = ($this->deducted)($this->withPan);
    $noPan = ($this->deducted)($this->noPan);
    $total = ($this->deductedTotal)();
    expect($withPan)->toBeGreaterThan(0)->and($noPan)->toBeGreaterThan(0)->and(($this->taxed)())->toContain($this->withPan->id, $this->noPan->id);

    $rows = $this->challans->tdsAllocation($this->entity, CarbonImmutable::parse('2026-09-01'), $this->filer);
    $mine = $rows->firstWhere('employee_id', $this->withPan->id);
    expect($rows->pluck('employee_id')->sort()->values()->all())->toBe(($this->taxed)())
        ->and($mine['deducted'])->toBe($withPan)->and($mine['to_allocate'])->toBe($withPan)->and($mine['allocated'])->toBe(0.0)
        ->and($mine['code'])->toBe($this->withPan->employee_code)
        ->and($mine['pan'])->toBe('••••••234F')
        ->and($rows->firstWhere('employee_id', $this->noPan->id)['pan'])->toBe('PAN not available');

    // A BSR code is the bank branch's seven digits.
    expect(fn () => $this->challans->record(($this->tdsPayment)(['bsr_code' => '12AB']), $this->filer))->toThrow(RuntimeException::class, '7-digit BSR');

    // Through the wizard: one employee's TDS, with interest recorded on the challan.
    Filament::getPanel('admin')->boot();
    $this->actingAs($this->filer);
    Livewire::test(StatutoryChallansPage::class)
        // (the choice of employees comes last: choosing the statute, unit and month refills it, as on screen)
        ->callAction('recordPayment', data: array_merge(($this->tdsPayment)(['interest' => 150]), ['employee_ids' => [$this->withPan->id]]), arguments: ['statute' => 'tds', 'unit' => $this->entity->id, 'month' => '2026-09'])
        ->assertHasNoActionErrors()->assertNotified('Challan recorded');
    $first = StatutoryChallan::query()->sole();
    expect($first->statute)->toBe('tds')->and($first->establishment_id)->toBeNull()->and($first->legal_entity_id)->toBe($this->entity->id)
        ->and((float) $first->amount)->toBe($withPan)->and((float) $first->interest)->toBe(150.0)->and($first->total())->toBe(round($withPan + 150, 2))
        ->and($first->nature_of_payment)->toBe(config('peopleos.compliance.formats.TDS_FORM_138.section_code'))
        ->and($first->statutory_registration_id)->toBe(StatutoryRegistration::query()->where('registration_type', 'tan')->sole()->id)
        ->and(StatutoryChallanAllocation::query()->where('statutory_challan_id', $first->id)->pluck('employee_id')->all())->toBe([$this->withPan->id])
        ->and(AuditEvent::withoutTenancy()->where('entity_id', (string) $first->id)->where('action', 'ASSIGN')->value('metadata')['employee_ids'] ?? null)->toBe([$this->withPan->id]);

    // Already deposited: that employee is left out; the rest goes into the next challan; nothing is left after it.
    expect(fn () => $this->challans->record(($this->tdsPayment)(['challan_number' => '00124', 'employee_ids' => [$this->withPan->id]]), $this->filer))->toThrow(RuntimeException::class, 'no TDS of Sep 2026 left');
    $second = $this->challans->record(($this->tdsPayment)(['challan_number' => '00124']), $this->filer);
    expect((float) $second->amount)->toBe(round($total - $withPan, 2))
        ->and(fn () => $this->challans->record(($this->tdsPayment)(['challan_number' => '00125']), $this->filer))->toThrow(RuntimeException::class, 'left to deposit');
    $month = $this->challans->tdsMonths($this->entity, '2026-27')['2026-09'];
    expect($month)->toBe(['employees' => count(($this->taxed)()), 'deducted' => $total, 'deposited' => $total, 'to_deposit' => 0.0, 'employees_deposited' => count(($this->taxed)())]);

    // Cancelling a challan frees its allocation.
    $this->challans->cancel($second, $this->filer, 'Paid under the wrong TAN');
    expect($this->challans->tdsAllocation($this->entity, CarbonImmutable::parse('2026-09-01'), $this->filer)->firstWhere('employee_id', $this->noPan->id)['to_allocate'])->toBe($noPan)
        ->and(fn () => StatutoryChallanAllocation::query()->first()->update(['amount' => 1]))->toThrow(RuntimeException::class, 'never changed');

    // Only finalized payroll counts: October is calculated, not finalized.
    $runs = app(PayrollRuns::class);
    $runs->calculate($runs->open($this->company, 2026, 10, $this->preparer), $this->preparer);
    expect($this->challans->tdsAllocation($this->entity, CarbonImmutable::parse('2026-10-01'), $this->filer))->toBeEmpty()
        ->and(fn () => $this->challans->record(($this->tdsPayment)(['month' => '2026-10', 'challan_number' => '00200', 'paid_on' => '2026-10-05']), $this->filer))->toThrow(RuntimeException::class, 'No TDS deducted by finalized payroll for Oct 2026');

    // D-PO7: finalized TDS is never edited, and the ledger stays as payroll wrote it.
    expect(PayrollEntryLine::query()->whereIn('id', array_keys($linesBefore))->orderBy('id')->pluck('amount', 'id')->all())->toBe($linesBefore)
        ->and(TdsAnnualLedger::query()->where('status', 'active')->where('month', '>=', '2026-09-01')->where('month', '<=', '2026-09-30 23:59:59')->sum('tds_deducted'))->toEqual($total);

    // The grid's income tax tab shows deducted / deposited per month.
    $this->get(StatutoryChallansPage::getUrl(['statute' => 'tds']))->assertOk()
        ->assertSee('data-cell="'.$this->entity->id.'|2026-09" data-state="paid"', false)
        ->assertSee('data-tds="2026-09"', false);
});

it('feeds the quarterly TDS statement with the register\'s challans and keeps typed challans working', function () {
    $tds = app(TdsQuarterlyReturns::class);
    $total = ($this->deductedTotal)();

    // Empty register: as before, the statement asks for the deposit details.
    $return = $this->returns->validate($tds->generate($this->entity, '2026-27', 2, $this->generator), $this->generator);
    expect($return->attestations['challans'])->toBe([])->and($return->attestations)->not->toHaveKey('challan_source')
        ->and(collect($return->validation)->where('severity', 'blocking')->pluck('code'))->toContain('deposit_details_missing');

    // Typed challans still win when given.
    $typed = [['bsr_code' => '0510001', 'deposit_date' => '2026-10-07', 'challan_serial' => '00999', 'amount' => $total]];
    $return = $tds->generate($this->entity, '2026-27', 2, $this->generator, $typed);
    expect($return->attestations['challans'])->toEqual($typed)->and($return->attestations)->not->toHaveKey('challan_source');

    // With the register: the quarter's challans (not cancelled) are picked, interest kept apart from the tax deposited.
    $one = $this->challans->record(($this->tdsPayment)(['employee_ids' => [$this->withPan->id], 'interest' => 40]), $this->filer);
    $cancelled = $this->challans->record(($this->tdsPayment)(['challan_number' => '00124']), $this->filer);
    $this->challans->cancel($cancelled, $this->filer, 'Recorded against the wrong month');
    $two = $this->challans->record(($this->tdsPayment)(['challan_number' => '00125', 'paid_on' => '2026-10-05']), $this->filer);

    $return = $this->returns->validate($tds->generate($this->entity, '2026-27', 2, $this->generator), $this->generator);
    expect($return->attestations['challan_source'])->toBe('register')
        ->and(collect($return->attestations['challans'])->pluck('statutory_challan_id')->all())->toBe([$one->id, $two->id])
        ->and($return->attestations['challans'][0])->toMatchArray(['bsr_code' => '0510001', 'deposit_date' => '2026-10-04', 'challan_serial' => '00123', 'interest' => 40.0])
        ->and($return->totals['tax_deposited'])->toEqual($total)
        ->and($return->totals['tax_deducted'])->toEqual($total)
        ->and(collect($return->validation)->where('severity', 'blocking')->pluck('code')->all())->not->toContain('deposit_details_missing')->not->toContain('deposit_short')
        ->and($return->status)->toBe('validated');

    // Once the statement is approved, a challan it carries is not cancelled.
    $this->returns->approve($return, $this->approver);
    expect(fn () => $this->challans->cancel($one->refresh(), $this->filer, 'Late change'))->toThrow(RuntimeException::class, 'revise the statement');

    // The grid shows the quarter's statement on each of its months.
    expect(collect($this->challans->grid('tds', '2026-27'))->firstWhere('unit.id', $this->entity->id)['cells']['2026-08']['state'])->toBe('approved')
        ->and(collect($this->challans->grid('tds', '2026-27'))->firstWhere('unit.id', $this->entity->id)['cells']['2026-09']['state'])->toBe('paid');
});

it('shows locations without a statutory unit and salaries without a professional-tax state on the grid', function () {
    $before = count($this->challans->coverage('2026-27')['locations']);
    Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Hosur warehouse', 'state_code' => 'TN', 'establishment_id' => null]);
    $stateless = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'NOSTATE', 'name' => 'Field office', 'state' => null, 'effective_from' => '2020-01-01']);
    statutoryEmployee(600000, $stateless, '100200300499');
    // No company-level PT state to fall back on: that salary has no professional-tax state.
    CompanyStatutoryProfile::query()->where('company_id', $this->company->id)->update(['pt_state' => null]);
    finalizedPayroll($this->company, 2026, 8, $this->preparer, $this->payrollApprover);

    $coverage = $this->challans->coverage('2026-27');
    expect(count($coverage['locations']))->toBe($before + 1)
        ->and(collect($coverage['locations'])->firstWhere('name', 'Hosur warehouse'))->toBe(['name' => 'Hosur warehouse', 'state' => 'TN', 'company' => $this->company->name])
        ->and($coverage['state_missing'])->toBeGreaterThanOrEqual(1);

    $this->actingAs($this->filer);
    $this->get(StatutoryChallansPage::getUrl(['statute' => 'pt']))->assertOk()->assertSee('Hosur warehouse')->assertSee('not mapped to an establishment')
        ->assertSee('data-coverage-state="'.$coverage['state_missing'].'"', false);
    // Display only: nothing was written.
    expect(Location::query()->where('name', 'Hosur warehouse')->value('establishment_id'))->toBeNull();
});

it('keeps the register and its page to compliance.returns.view, files payments with compliance.returns.file, and stays in scope', function () {
    Filament::getPanel('admin')->boot();

    // No page without the view right; viewing alone records nothing.
    $this->actingAs(tenantUser($this->tenant, ['payroll.view', 'payroll.calculate']));
    $this->get(StatutoryChallansPage::getUrl())->assertForbidden();
    $viewer = tenantUser($this->tenant, ['compliance.returns.view']);
    $this->actingAs($viewer);
    $this->get(StatutoryChallansPage::getUrl())->assertOk();
    Livewire::test(StatutoryChallansPage::class)->assertActionHidden('recordPayment');
    expect(fn () => $this->challans->record(($this->pfPayment)(), $viewer))->toThrow(RuntimeException::class, 'compliance.returns.file');

    // The launcher: ?action=recordPayment opens the wizard for those who may file, filled from its arguments.
    $this->actingAs($this->filer);
    $this->get(StatutoryChallansPage::getUrl(['action' => 'recordPayment']))->assertOk()->assertSee("mountAction('recordPayment'", false);
    Livewire::test(StatutoryChallansPage::class)->mountAction('recordPayment', ['statute' => 'esi', 'unit' => $this->establishment->id, 'month' => '2026-09'])
        ->assertActionMounted('recordPayment')->assertActionDataSet(['statute' => 'esi', 'unit_id' => $this->establishment->id, 'month' => '2026-09', 'payment_mode' => 'net_banking']);

    // Establishment scope: only that establishment's units and challans; no entity-level (TDS) challan.
    $pune = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $blr = $this->challans->record(($this->pfPayment)(), $this->filer);
    $tds = $this->challans->record(($this->tdsPayment)(), $this->filer);
    $scoped = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.returns.file']);
    app(AccessScopes::class)->assign($scoped, ['establishment' => [$pune->id]]);
    app(AccessScopes::class)->forget();
    $this->actingAs($scoped);
    expect($this->challans->units('pf')->modelKeys())->toBe([$pune->id])
        ->and(StatutoryChallan::query()->pluck('id')->all())->toBe([])
        ->and($scoped->can('view', $blr))->toBeFalse()
        ->and(fn () => $this->challans->record(($this->pfPayment)(['challan_number' => 'TRRN-OTHER']), $scoped))->toThrow(RuntimeException::class, 'Choose the establishment')
        ->and(fn () => $this->challans->record(($this->tdsPayment)(['challan_number' => '00777']), $scoped))->toThrow(RuntimeException::class)
        ->and(StatutoryChallan::query()->withoutGlobalScope(AccessScope::class)->where('challan_number', '00777')->exists())->toBeFalse();
    $own = $this->challans->record(($this->pfPayment)(['unit_id' => $pune->id, 'challan_number' => 'TRRN-PUNE']), $scoped);
    expect(StatutoryChallan::query()->pluck('id')->all())->toBe([$own->id])
        ->and(fn () => $this->challans->cancel($blr, $scoped, 'Not mine'))->toThrow(RuntimeException::class, 'outside your access');
    expect($this->get(ParityFileController::url('statutory-challan', $tds, 0))->status())->toBeIn([403, 404]);

    // Another tenant finds nothing.
    $other = provisionTenant('Other');
    actAsTenant($other);
    $this->actingAs(tenantUser($other, ['*']));
    expect(StatutoryChallan::query()->find($blr->id))->toBeNull()->and(StatutoryChallanAllocation::query()->count())->toBe(0);
    actAsTenant($this->tenant);

    // The payroll desk's "Generate challan" opens the grid; compliance reports keep their own entry.
    $this->actingAs($this->admin);
    $item = fn (string $label) => collect(collect((new PayrollDesk)->deskSections())->firstWhere('label', 'Payroll processing')['items'])->firstWhere('label', $label);
    expect($item('Generate challan')['url'])->toBe(StatutoryChallansPage::getUrl())
        ->and($item('Compliance reports')['url'])->toBe(ComplianceReportsPage::getUrl());
});

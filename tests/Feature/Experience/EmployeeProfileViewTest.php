<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| Owner, 2026-11-27: the Employee 360 shows every detail of the person in view mode (a Profile view beside Now: personal,
| contact and addresses, employment, statutory, government IDs, bank, family, education and experience, other details),
| with Edit beside each area for someone who may change it; sensitive numbers stay masked until a stated purpose reveals
| them (audited). The Edit page holds every detail an employee record needs: Position & reporting and Statutory steps,
| written by the same governed actions as Transfer / promote, Change manager and Edit statutory details.
*/

beforeEach(function () {
    $this->travelTo('2026-11-27 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create();
    $this->engineering = Department::factory()->create(['name' => 'Engineering']);
    $this->finance = Department::factory()->create(['name' => 'Finance']);
    $this->manager = forceLifecycle(app(HireEmployeeAction::class)->handle(['first_name' => 'Anita', 'last_name' => 'Rao'], ['joining_date' => '2020-01-06'], ['company_id' => $this->company->id]), LifecycleState::Active);
    $this->employee = forceLifecycle(app(HireEmployeeAction::class)->handle(
        ['first_name' => 'Priya', 'last_name' => 'Shah', 'date_of_birth' => '1992-05-14', 'gender' => 'female', 'personal_email' => 'priya@example.test'],
        ['joining_date' => '2024-04-01', 'work_email' => 'priya.shah@acme.test'],
        ['company_id' => $this->company->id, 'department_id' => $this->engineering->id],
    ), LifecycleState::Active);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    app(ChangeStatutoryIdentityAction::class)->handle($this->employee, ['pan' => 'ABCDE1234F', 'uan' => '100200300400'], $this->hr);
    app(ChangeBankAccountAction::class)->add($this->employee, ['account_holder_name' => 'Priya Shah', 'bank_name' => 'HDFC Bank', 'ifsc' => 'HDFC0000123', 'account_number' => '50100012345678', 'account_type' => 'savings', 'is_primary' => true], $this->hr);
});

it('adds a Profile view with every detail by area and names the key details not recorded yet', function () {
    $html = $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()
        ->assertSee('href="#profile"', false)->getContent();
    $profile = Str::between($html, '<section id="profile"', '<section id="journey"');

    expect($profile)
        ->toContain('Personal')->toContain('Contact &amp; addresses')->toContain('Employment')->toContain('Statutory')->toContain('Bank &amp; payment')
        ->toContain('Family &amp; nominees')->toContain('Education &amp; experience')
        ->toContain('14 May 1992')->toContain('priya.shah@acme.test')->toContain('Engineering')->toContain('HDFC Bank')
        // Key details not on file are named (no Aadhaar, no address, no emergency contact here).
        ->toContain('key details are not recorded yet')->toContain('Not recorded yet: Aadhaar')->toContain('Emergency contact')
        // Numbers are masked until revealed.
        ->toContain('••••••234F')->not->toContain('ABCDE1234F')->not->toContain('50100012345678');
});

it('keeps statutory, ID and bank areas from viewers without sensitive access, and offers no Edit to someone who cannot edit', function () {
    $viewer = tenantUser($this->tenant, ['employee.view']);
    $profile = Str::between($this->actingAs($viewer)->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->getContent(), '<section id="profile"', '<section id="journey"');

    expect($profile)->toContain('pos-prof-personal')->toContain('pos-prof-employment')
        ->not->toContain('pos-prof-statutory')->not->toContain('pos-prof-bank')->not->toContain('pos-prof-identity')
        ->not->toContain('••••••234F')->not->toContain('HDFC Bank')
        ->not->toContain('Edit personal details')->not->toContain('Transfer / promote');
});

it('puts an Edit beside each area for HR, opening the step of the same name on the Edit page', function () {
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->assertSee('Edit personal details')->assertSee('Edit position &amp; reporting', false)->assertSee('Edit statutory details')
        ->assertSee('Edit bank accounts')->assertSee('Edit government IDs')->assertSee('Edit contact &amp; addresses', false)
        ->assertSee('edit?step=personal', false)->assertSee('edit?step=contact-addresses', false)->assertSee('edit?step=position-reporting', false)
        ->assertSee('edit?step=statutory', false)->assertSee('edit?step=bank-payment', false)->assertSee('edit?step=government-ids', false)
        ->assertDontSee('relation=', false);
});

it('names and orders the Edit page steps as the Profile areas, every detail in one step', function () {
    $page = Livewire::test(EditEmployee::class, ['record' => $this->employee->id]);
    $wizard = $page->instance()->getSchema('form')->getComponents()[0];
    $steps = collect($wizard->getChildSchema()->getComponents())->filter->isVisible()->map(fn ($step) => (string) $step->getLabel())->values()->all();

    expect($steps)->toBe(['Personal', 'Contact & addresses', 'Employment', 'Position & reporting', 'Statutory', 'Government IDs', 'Bank & payment',
        'Family & nominees', 'Education & experience', 'Health insurance', 'Review']);
    $areas = collect($page->instance()->getSchema('form')->getFlatFields(withHidden: true))->keys();
    // Each field once: the contact fields left Personal and Employment, the fresher flag sits with previous employment.
    expect($areas->duplicates())->toBeEmpty();

    // Old links still open the right step.
    Livewire::withQueryParams(['step' => 'person'])->test(EditEmployee::class, ['record' => $this->employee->id])->assertSet('openStep', 'personal');
});

it('shows an area\'s numbers only after a stated purpose, records it, and never lets the browser reveal them', function () {
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction('revealProfile', data: ['purpose' => ''], arguments: ['area' => 'statutory'])->assertHasActionErrors(['purpose' => 'required'])
        ->assertDontSee('ABCDE1234F');

    $page = Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])->assertDontSee('ABCDE1234F');
    $page->callAction('revealProfile', data: ['purpose' => 'Checking the UAN for a PF transfer'], arguments: ['area' => 'statutory'])
        ->assertHasNoActionErrors()->assertSee('ABCDE1234F')->assertSee('100200300400')->assertDontSee('50100012345678');

    $audit = AuditEvent::query()->where('entity_type', $this->employee->getMorphClass())->where('entity_id', $this->employee->id)->latest('id')->first();
    expect($audit->metadata['scope'] ?? null)->toBe('profile:statutory')->and($audit->metadata['purpose'] ?? null)->toBe('Checking the UAN for a PF transfer');

    $page->call('hideProfileNumbers', 'statutory')->assertDontSee('ABCDE1234F');
    expect(fn () => $page->set('profileRevealed', ['bank']))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('saves a changed position, manager and PAN from the Edit page through the governed actions', function () {
    Livewire::withQueryParams(['step' => 'statutory'])->test(EditEmployee::class, ['record' => $this->employee->id])
        ->assertSet('openStep', 'statutory')
        ->assertSet('data.statutory.pan', 'ABCDE1234F')->assertSet('data.placement.department_id', $this->engineering->id)
        ->fillForm([
            'placement.department_id' => $this->finance->id, 'placement.manager_id' => $this->manager->id,
            'placement.change_type' => 'transfer', 'placement.effective_from' => '2026-12-01',
            'statutory.pan' => 'PQRSX6789K', 'statutory.esic_applicable' => true,
            'audit_reason' => 'Moved to Finance',
        ])
        ->call('save')->assertHasNoFormErrors();

    $positions = EmployeePosition::query()->where('employee_id', $this->employee->id)->orderBy('effective_from')->get();
    expect($positions)->toHaveCount(2)
        ->and($positions->last()->department_id)->toBe($this->finance->id)->and($positions->last()->effective_from->toDateString())->toBe('2026-12-01')
        ->and($positions->first()->department_id)->toBe($this->engineering->id)
        ->and(ReportingRelationship::query()->where('employee_id', $this->employee->id)->where('manager_id', $this->manager->id)->exists())->toBeTrue()
        ->and(EmployeeStatutoryDetail::query()->where('employee_id', $this->employee->id)->first())
        ->pan->toBe('PQRSX6789K')->esic_applicable->toBeTrue()->uan->toBe('100200300400');
});

it('writes no position or statutory change for an unrelated edit, even when today\'s department has since been deactivated', function () {
    $this->engineering->forceFill(['status' => 'inactive'])->save();
    $last = (int) AuditEvent::query()->max('id');

    Livewire::test(EditEmployee::class, ['record' => $this->employee->id])
        ->fillForm(['work_email' => 'priya@acme.test', 'audit_reason' => 'New mailbox'])
        ->call('save')->assertHasNoFormErrors();

    expect($this->employee->fresh()->work_email)->toBe('priya@acme.test')
        ->and(EmployeePosition::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('id', '>', $last)->where('entity_type', (new EmployeeStatutoryDetail)->getMorphClass())->exists())->toBeFalse();
});

it('keeps the whole save back when the position change is refused', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->employee->id])
        ->fillForm(['work_email' => 'other@acme.test', 'placement.department_id' => $this->finance->id, 'placement.effective_from' => '2024-01-01',
            'statutory.pan' => 'PQRSX6789K', 'statutory.pan_copy' => UploadedFile::fake()->createWithContent('pan.pdf', '%PDF-1.4 pan'), 'audit_reason' => 'Back-dated'])
        ->call('save')->assertNotified('Not saved');

    // Nothing of the save is kept: not the e-mail, the position, the PAN or its card copy (the placement is checked first).
    expect($this->employee->fresh()->work_email)->toBe('priya.shah@acme.test')
        ->and(EmployeePosition::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and($this->employee->fresh()->statutoryDetail?->pan)->toBe('ABCDE1234F')
        ->and(EmployeeDocument::query()->where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

it('shows the Position & reporting and Statutory steps only to someone who may make those changes', function () {
    $editor = tenantUser($this->tenant, ['employee.view', 'employee.update']);

    Livewire::actingAs($editor)->test(EditEmployee::class, ['record' => $this->employee->id])
        ->assertSet('data.statutory.pan', null)->assertSet('data.placement.department_id', null)->assertDontSee('ABCDE1234F')
        ->assertDontSee('Position &amp; reporting', false)
        ->fillForm(['work_email' => 'priya@acme.test', 'audit_reason' => 'New mailbox'])->call('save')->assertHasNoFormErrors();

    expect($this->employee->fresh()->work_email)->toBe('priya@acme.test')
        ->and(EmployeeStatutoryDetail::query()->where('employee_id', $this->employee->id)->value('pan'))->toBe('ABCDE1234F');
});

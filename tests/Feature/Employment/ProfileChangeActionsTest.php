<?php

use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\ChangeStatutoryApplicabilityAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Actions\ChangeEmergencyContactAction;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Models\PersonAddress;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\BankAccountsRelationManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
 | Phase 12 people domain change actions: the one write path for bank accounts, statutory identity and
 | applicability, addresses, emergency contacts and family members — and the domain control fixes the
 | owner asked for (letters, regularisation, documents, manager change, own documents).
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = activeEmployee();
    $this->bank = ['account_holder_name' => 'A Rao', 'bank_name' => 'HDFC', 'ifsc' => 'HDFC0000123', 'account_number' => '50100012345678', 'account_type' => 'savings', 'is_primary' => true];
});

it('changes bank accounts only through the action: authorized, validated, deduplicated, masked, recorded without values', function () {
    Event::fake([EmploymentEvent::class]);
    $action = app(ChangeBankAccountAction::class);
    $origin = new ChangeOrigin('service_desk', 'TKT-2026-00042', 'op-1');

    $account = $action->add($this->employee, $this->bank, $this->hr, 'New salary account', $origin);
    expect($account->account_number)->toBe('50100012345678')->and($account->account_number_last4)->toBe('5678')
        ->and(fn () => $action->add($this->employee, $this->bank, $this->hr))->toThrow(ProfileChangeRefused::class, 'already on file')
        ->and(fn () => $action->add($this->employee, ['ifsc' => 'bad'] + $this->bank, $this->hr))->toThrow(ProfileChangeRefused::class)
        ->and(fn () => $action->add($this->employee, $this->bank, tenantUser($this->tenant, ['employee.view', 'employee.update'])))->toThrow(ProfileChangeRefused::class, 'employee.sensitive.update');

    $created = AuditEvent::query()->where('entity_type', EmployeeBankAccount::class)->where('action', 'CREATE')->with('fieldChanges')->sole();
    expect($created->approval_reference)->toBe('TKT-2026-00042')->and($created->reason)->toBe('New salary account')
        ->and($created->fieldChanges->firstWhere('field', 'account_number')->after)->toBe(config('peopleos.audit.mask'));
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.bank_account_changed' && $e->context === ['operation' => 'added', 'source' => 'service_desk', 'reference' => 'TKT-2026-00042', 'operation_id' => 'op-1']);
    expect(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'bank')->value('title'))->toBe('Bank account added');

    $action->update($account, ['account_holder_name' => 'A. Rao', 'bank_name' => 'HDFC', 'account_number' => ''], $this->hr);
    expect($account->refresh()->account_number)->toBe('50100012345678')->and($account->account_holder_name)->toBe('A. Rao');   // blank keeps the number
    $action->remove($account, $this->hr, 'Closed');
    expect(EmployeeBankAccount::query()->count())->toBe(0);
});

it('keeps organisation scope and tenant boundaries inside every action', function () {
    $other = Company::factory()->create();
    $scoped = tenantUser($this->tenant, ['employee.view', 'employee.create', 'employee.update', 'employee.sensitive.update']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$other->id]], 'Other company');

    expect(fn () => app(ChangeBankAccountAction::class)->add($this->employee, $this->bank, $scoped))->toThrow(ProfileChangeRefused::class, 'outside your organisation scope')
        ->and(fn () => app(ChangeAddressAction::class)->add($this->employee, ['type' => 'current', 'country_code' => 'IN', 'address_line_1' => '1 MG Road'], $scoped))->toThrow(ProfileChangeRefused::class, 'outside your organisation scope');

    $tenantB = provisionTenant('B');
    actAsTenant($tenantB);
    $userB = tenantUser($tenantB, ['*']);
    expect(fn () => app(ChangeBankAccountAction::class)->add($this->employee, $this->bank, $userB))->toThrow(ProfileChangeRefused::class);
});

it('changes statutory identity and applicability through separate actions with masked audit', function () {
    Event::fake([EmploymentEvent::class]);
    expect(fn () => app(ChangeStatutoryIdentityAction::class)->handle($this->employee, ['pan' => 'BADPAN'], $this->hr))->toThrow(ProfileChangeRefused::class);

    $detail = app(ChangeStatutoryIdentityAction::class)->handle($this->employee, ['pan' => 'ABCDE1234F', 'uan' => '100200300400', 'tax_regime' => 'old'], $this->hr, 'Onboarding');
    expect($detail->pan)->toBe('ABCDE1234F')->and($detail->tax_regime)->toBeNull();   // identity action ignores applicability fields
    app(ChangeStatutoryApplicabilityAction::class)->handle($this->employee, ['tax_regime' => 'old', 'esic_applicable' => true], $this->hr);
    expect($detail->refresh()->tax_regime)->toBe('old')->and($detail->esic_applicable)->toBeTrue();

    $masked = AuditEvent::query()->where('action', 'CREATE')->whereHas('fieldChanges', fn ($q) => $q->where('field', 'pan'))->with('fieldChanges')->sole();
    expect($masked->fieldChanges->firstWhere('field', 'pan')->after)->toBe(config('peopleos.audit.mask'));
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.statutory_identity_changed' && ! str_contains(json_encode($e->context), 'ABCDE'));
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.statutory_applicability_changed');
});

it('changes person records with the existing permission rules and never another person\'s record', function () {
    $address = ['type' => 'current', 'country_code' => 'IN', 'address_line_1' => '1 MG Road', 'city' => 'Bengaluru'];
    $creator = tenantUser($this->tenant, ['employee.view', 'employee.create']);
    $editor = tenantUser($this->tenant, ['employee.view', 'employee.update']);

    $row = app(ChangeAddressAction::class)->add($this->employee, $address, $creator);
    expect(fn () => app(ChangeAddressAction::class)->update($this->employee, $row, ['city' => 'Mysuru'] + $address, $creator))->toThrow(ProfileChangeRefused::class, 'employee.update')
        ->and(app(ChangeAddressAction::class)->update($this->employee, $row, ['city' => 'Mysuru'] + $address, $editor)->city)->toBe('Mysuru')
        ->and(fn () => app(ChangeAddressAction::class)->remove($this->employee, $row, $editor))->toThrow(ProfileChangeRefused::class, 'employee.delete')
        ->and(fn () => app(ChangeAddressAction::class)->update(activeEmployee(), $row, $address, $this->hr))->toThrow(ProfileChangeRefused::class, 'does not belong');

    $contact = app(ChangeEmergencyContactAction::class)->add($this->employee, ['name' => 'Ravi', 'phone' => '9999999999', 'priority' => 1], $this->hr);
    $member = app(ChangeFamilyMemberAction::class)->add($this->employee, ['name' => 'Asha', 'relation' => 'spouse', 'is_nominee' => true, 'nominee_share' => 100], $this->hr);
    expect(fn () => app(ChangeFamilyMemberAction::class)->add($this->employee, ['name' => 'X', 'relation' => 'neighbour'], $this->hr))->toThrow(ProfileChangeRefused::class)
        ->and($contact->person_id)->toBe($this->employee->person_id)->and($member->relation)->toBe('spouse')
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'personal')->count())->toBe(4);
});

it('routes the Employee 360 profile screens through the change actions', function () {
    Event::fake([EmploymentEvent::class]);
    // Profile tabs are editable on the Edit page (relation managers on the View page are read-only).
    Livewire::test(AddressesRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => EditEmployee::class])
        ->callTableAction('create', data: ['type' => 'current', 'country_code' => 'IN', 'address_line_1' => '2 Brigade Road'])->assertHasNoTableActionErrors();
    Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => EditEmployee::class])
        ->callTableAction('create', data: $this->bank)->assertHasNoTableActionErrors();
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction('editStatutory', data: ['pan' => 'PQRSX6789K', 'pf_applicable' => true, 'pt_applicable' => true, 'esic_applicable' => false, 'audit_reason' => 'Correction']);

    expect(PersonAddress::query()->where('person_id', $this->employee->person_id)->value('address_line_1'))->toBe('2 Brigade Road');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.address_changed');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.bank_account_changed');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.statutory_identity_changed');
});

it('applies the domain control fixes: manager change permission, own documents, regularisation review', function () {
    $manager = activeEmployee();
    $noPosition = tenantUser($this->tenant, ['employee.view', 'employee.update']);
    expect(fn () => app(ChangeManagerAction::class)->change($this->employee, $manager, $noPosition))->toThrow(InvalidArgumentException::class, 'employee.position')
        ->and(app(ChangeManagerAction::class)->change($this->employee, $manager, $this->hr)->manager_id)->toBe($manager->id);

    Storage::fake(config('peopleos.documents.disk'));
    $doc = app(Documents::class)->store($this->employee, UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'), null, 'ID proof');
    $colleague = activeEmployee(null, ['document.own']);
    expect($this->employee->user->can('view', $doc))->toBeFalse();   // no document.own yet
    $this->employee->user->roles()->first()->permissions()->syncWithoutDetaching(app(PermissionRegistry::class)->idsMatching(['document.own']));
    expect($this->employee->user->fresh()->can('view', $doc))->toBeTrue()
        ->and($colleague->user->can('view', $doc))->toBeFalse()
        ->and(EmployeeDocument::query()->count())->toBe(1);

    $regularisations = app(Regularisations::class);
    $approver = activeEmployee(null, ['attendance.approve', 'attendance.view', 'attendance.regularise']);
    $own = $regularisations->request($approver, '2026-09-18', 'late', 'Traffic', null, null, $approver->user);
    expect(fn () => $regularisations->approve($own, 'mine', $approver->user))->toThrow(RuntimeException::class, 'own regularisation')
        ->and($approver->user->can('approve', $own))->toBeFalse();
    $theirs = $regularisations->request($this->employee, '2026-09-18', 'late', 'Traffic', null, null, $this->employee->user);
    $regularisations->approve($theirs, 'ok', $approver->user);
    expect($theirs->refresh()->reviewed_by)->toBe($approver->user->id);
});

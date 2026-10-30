<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\ChangeIntelligence;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\Services\MyProfileSections;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyProfile;
use App\Filament\Resources\Employees\EmployeeResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne parity MS-2 — My space → Profile: the employee's own profile read by section, own identifiers and bank numbers
| masked with an audited reveal behind employee.self_sensitive (owner decision PRV-1 / D-MS1 open: not in the employee
| template, so hidden by default as before), documents with "Download all", and "My changes".
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->travelTo('2026-10-05 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->checker = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    // The employee role's self-service keys that matter here (no employee.self_sensitive, as in the role template).
    $this->employee = forceLifecycle(employeeWithUser(null, ['employee.self', 'document.own']), LifecycleState::Active, ['joining_date' => '2020-04-01']);
    $this->colleague = forceLifecycle(employeeWithUser(null, ['employee.self', 'document.own']), LifecycleState::Active);
});

function ms2Statutory(Employee $employee): void
{
    app(ChangeStatutoryIdentityAction::class)->handle($employee, ['pan' => 'ABCDE1234F', 'uan' => '100200300400'], test()->hr);
}

function ms2Bank(Employee $employee, string $number = '001122334455'): int
{
    return (int) app(ChangeBankAccountAction::class)->add($employee, ['account_holder_name' => 'Holder', 'bank_name' => 'Canara Bank', 'branch_name' => 'MG Road', 'ifsc' => 'CNRB0001234', 'account_number' => $number, 'account_type' => 'savings', 'is_primary' => true], test()->hr)->id;
}

function ms2Reveals(): int
{
    return AuditEvent::query()->where('action', 'VIEW')->get()->filter(fn (AuditEvent $e) => ($e->metadata['scope'] ?? null) === 'self_reveal')->count();
}

it('is the avatar menu\'s "My profile" and opens only the signed-in person\'s own record', function () {
    $this->actingAs($this->employee->user);
    $this->get(MyHr::getUrl())->assertOk()->assertSee(MyProfile::getUrl(), false);
    $this->get(MyProfile::getUrl())->assertOk()->assertSee('Snapshot')->assertSee($this->employee->employee_code);
    // The Employee 360 is still there (My HR "Your record"), for HR and managers too.
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk();

    // No employee record, or no right to open one's own record: no page.
    $this->actingAs(tenantUser($this->tenant, ['employee.self']));
    expect(MyProfile::canAccess())->toBeFalse();
    $this->get(MyProfile::getUrl())->assertForbidden();
    $noSelf = forceLifecycle(employeeWithUser(null, ['document.own']), LifecycleState::Active);
    $this->actingAs($noSelf->user);
    $this->get(MyProfile::getUrl())->assertForbidden();
});

it('shows the stored-but-hidden facts by section, read from their owning domains', function () {
    app(UpdatePersonAction::class)->handle($this->employee->person, ['date_of_birth' => '1970-03-15', 'blood_group' => 'O+', 'nationality' => 'Indian', 'marital_status' => 'married', 'personal_email' => 'asha@example.com'], 'test');
    $policy = Policy::create(['type' => 'exit', 'name' => 'Exit', 'code' => 'EXIT_MS2']);
    app(Policies::class)->draft($policy, ['retirement_age' => 60, 'retire_on' => 'birthday']);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'exit', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    ExitCase::create(['number' => 'EXIT-2026-00001', 'employee_id' => $this->employee->id, 'type' => 'resignation', 'status' => 'notice', 'initiated_on' => '2026-10-01', 'resignation_date' => '2026-10-01', 'last_working_day' => '2026-12-31']);

    $this->actingAs($this->employee->user);
    $page = Livewire::test(MyProfile::class)->assertOk();
    expect(array_keys($page->instance()->sections()))->toBe(['snapshot', 'personal', 'family', 'professional', 'skills', 'bank', 'documents', 'previous_employer', 'other_details', 'history']);
    $page->assertSee('O+')->assertSee('Indian')->assertSee('15 Mar 2030')->assertSee('31 Dec 2026')->assertSee('Date of resignation');
    $page->call('setSection', 'personal')->assertSee('asha@example.com');
    $page->call('setSection', 'family')->assertSee('Married');
    $page->call('setSection', 'bank')->assertSee('Bank transfer');
    $page->call('setSection', 'nonsense')->assertSet('section', 'snapshot');
});

it('keeps own identifiers and bank numbers hidden by default (PRV-1 open: not in the employee template)', function () {
    expect(config('peopleos.permissions.employee'))->toHaveKey('employee.self_sensitive')
        ->and(config('peopleos.roles.employee.permissions'))->not->toContain('employee.self_sensitive')
        ->and(config('peopleos.roles.manager.permissions'))->not->toContain('employee.self_sensitive');
    ms2Statutory($this->employee);
    ms2Bank($this->employee);

    $this->actingAs($this->employee->user);
    $page = Livewire::test(MyProfile::class)->call('setSection', 'personal')
        ->assertDontSee('1234F')->assertDontSee('0400')->assertDontSee('Identification');
    $page->call('reveal', 'pan')->assertSet('revealed', [])->assertDontSee('ABCDE1234F');
    $page->call('setSection', 'bank')->assertSee('Canara Bank')->assertSee('CNRB0001234')->assertDontSee('4455')->assertDontSee('Account number');
    expect(ms2Reveals())->toBe(0);

    // payroll.bank.show_ifsc_branch off hides the IFSC and branch here too, as on the HR Bank tab.
    app(SettingsRepository::class)->set('payroll.bank.show_ifsc_branch', false, 'test');
    Livewire::test(MyProfile::class)->call('setSection', 'bank')->assertSee('Canara Bank')->assertDontSee('CNRB0001234')->assertDontSee('MG Road');
});

it('masks own identifiers with employee.self_sensitive and audits each reveal, own record only', function () {
    $me = forceLifecycle(employeeWithUser(null, ['employee.self', 'document.own', 'employee.self_sensitive']), LifecycleState::Active);
    $user = $me->user;
    ms2Statutory($me);
    $mine = ms2Bank($me);
    $theirs = ms2Bank($this->colleague, '998877665544');

    $this->actingAs($user);
    $page = Livewire::test(MyProfile::class)->call('setSection', 'personal')
        ->assertSee('••••••234F')->assertDontSee('ABCDE1234F')->assertSee('••••••••0400');
    $page->call('reveal', 'pan')->assertSee('ABCDE1234F')->assertSet('revealed', ['pan']);
    expect(ms2Reveals())->toBe(1);
    $page->call('reveal', 'pan');
    expect(ms2Reveals())->toBe(1);
    $page->call('conceal', 'pan')->assertDontSee('ABCDE1234F');

    $page->call('setSection', 'bank')->assertSee('••••4455')->assertDontSee('001122334455');
    $page->call('reveal', 'bank:'.$mine)->assertSee('001122334455');
    expect(ms2Reveals())->toBe(2);
    // Another person's account, an unknown field: refused, nothing audited.
    $page->call('reveal', 'bank:'.$theirs)->call('reveal', 'salary')->call('reveal', 'bank:abc');
    expect(ms2Reveals())->toBe(2)
        ->and($page->get('revealed'))->toBe(['bank:'.$mine]);
    // The browser cannot add to the revealed list without the audited call.
    expect(fn () => Livewire::test(MyProfile::class)->set('revealed', ['uan']))->toThrow(CannotUpdateLockedPropertyException::class);

    // The matrix hides the personal section from employees: no identifiers, no reveal.
    app(SettingsRepository::class)->set('profile.section_visibility', ['personal' => ['employee' => false, 'manager' => true, 'others' => true]] + config('peopleos.settings')['profile.section_visibility'], 'test');
    $fresh = Livewire::test(MyProfile::class);
    expect(array_keys($fresh->instance()->sections()))->not->toContain('personal');
    $fresh->call('reveal', 'uan')->assertSet('revealed', []);
    expect(ms2Reveals())->toBe(2);
});

it('lists every document type the employee may see and downloads them all as one audited ZIP', function () {
    $documents = app(Documents::class);
    $pan = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    $passport = DocumentType::query()->where('code', 'PASSPORT')->firstOrFail();
    $passport->update(['sample_instructions' => 'Both pages, in colour.']);
    $medical = DocumentType::create(['name' => 'Medical report', 'code' => 'MED', 'category' => 'medical', 'employee_can_view' => false, 'status' => 'active']);
    $file = fn (string $name) => UploadedFile::fake()->createWithContent($name, '%PDF-1.4 '.$name);
    $documents->store($this->employee, $file('pan-old.pdf'), $pan);
    $current = $documents->store($this->employee, $file('pan.pdf'), $pan);
    $this->actingAs($this->checker);
    $documents->review($current, true, 'Clear scan');
    $this->actingAs($this->hr);
    $documents->store($this->employee, $file('offer.pdf'), null, 'Offer letter');
    $documents->store($this->employee, $file('medical.pdf'), $medical);
    $documents->store($this->colleague, $file('colleague.pdf'), $pan);

    $this->actingAs($this->employee->user);
    Livewire::test(MyProfile::class)->call('setSection', 'documents')
        ->assertSee('pan.pdf')->assertSee('Clear scan')->assertSee('Not uploaded')->assertSee('Both pages, in colour.')
        ->assertSee('Download all')->assertDontSee('medical.pdf')->assertDontSee('colleague.pdf')->assertDontSee('pan-old.pdf');

    $before = AuditEvent::query()->where('action', 'DOWNLOAD')->count();
    $response = $this->get(route('me.documents.zip'))->assertOk();
    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
    $zip->close();
    expect($names)->toBe(['Offer letter.pdf', 'PAN card (v2).pdf'])
        ->and(AuditEvent::query()->where('action', 'DOWNLOAD')->count() - $before)->toBe(2);
    $downloads = AuditEvent::query()->where('action', 'DOWNLOAD')->get()->filter(fn (AuditEvent $e) => ($e->metadata['purpose'] ?? null) === 'download_all');
    expect($downloads->pluck('entity_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe(collect([$current->id])->push(EmployeeDocument::query()->where('title', 'Offer letter')->value('id'))->sort()->values()->all());

    // The documents row unticked for employees: no section, no ZIP.
    app(SettingsRepository::class)->set('profile.section_visibility', ['documents' => ['employee' => false, 'manager' => true, 'others' => true]] + config('peopleos.settings')['profile.section_visibility'], 'test');
    expect(array_keys(Livewire::test(MyProfile::class)->instance()->sections()))->not->toContain('documents');
    $this->get(route('me.documents.zip'))->assertNotFound();
});

it('never puts another person\'s or another organisation\'s documents in the ZIP', function () {
    $pan = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    app(Documents::class)->store($this->employee, UploadedFile::fake()->createWithContent('pan.pdf', '%PDF-1.4 x'), $pan);

    // A colleague with no documents gets nothing (never someone else's).
    $this->actingAs($this->colleague->user);
    $this->get(route('me.documents.zip'))->assertNotFound();

    // A person of another organisation: nothing of this tenant's.
    $other = provisionTenant('Other');
    actAsTenant($other);
    $foreign = forceLifecycle(employeeWithUser(null, ['employee.self', 'document.own']), LifecycleState::Active);
    $this->actingAs($foreign->user);
    $this->get(route('me.documents.zip'))->assertNotFound();
    expect(app(MyProfileSections::class)->downloadable($foreign->user, $this->employee))->toHaveCount(0);
});

it('lists my own non-sensitive profile changes with earlier and new values', function () {
    app(UpdatePersonAction::class)->handle($this->employee->person, ['blood_group' => 'O+'], 'Recorded');
    app(UpdatePersonAction::class)->handle($this->employee->person, ['blood_group' => 'B+'], 'Corrected');
    ms2Statutory($this->employee);
    ms2Bank($this->employee);
    app(UpdatePersonAction::class)->handle($this->colleague->person, ['blood_group' => 'AB-'], 'Colleague');

    $changes = app(ChangeIntelligence::class)->ownProfileChanges($this->employee->user, $this->employee);
    $fields = collect($changes)->flatMap(fn ($c) => $c['fields']);
    expect($fields->where('field', 'Blood group')->pluck('after')->all())->toContain('B+', 'O+')
        ->and($fields->firstWhere('after', 'B+')['before'])->toBe('O+')
        ->and(json_encode($changes))->not->toContain('1234F')
        ->and(json_encode($changes))->not->toContain('4455')
        ->and(json_encode($changes))->not->toContain('AB-')
        // Only for one's own record.
        ->and(app(ChangeIntelligence::class)->ownProfileChanges($this->colleague->user, $this->employee))->toBe([]);

    $this->actingAs($this->employee->user);
    Livewire::test(MyProfile::class)->call('setSection', 'history')->assertSee('My changes')->assertSee('Blood group')->assertDontSee('ABCDE1234F');
});

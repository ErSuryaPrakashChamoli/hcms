<?php

use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Compliance\Support\RegistrationNumberFormat;
use App\Domain\Configuration\Models\CodeSeries;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\TemporaryPasswordMail;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Partners\Models\BusinessPartner;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Services\BusinessPartners;
use App\Domain\Partners\Services\Vendors;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\BusinessPartners\BusinessPartnerResource;
use App\Filament\Resources\BusinessPartners\Pages\ManageBusinessPartners;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Filament\Resources\Vendors\VendorResource;
use App\Support\Numbering\NumberSeries;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| HROne parity WO-15 (matrix 23 §23.8 #1–6, #10; §23.9 #21; setup matrix 01 §1.33): the vendor master, business partners
| (series or typed codes, validation in the services), the invitation that links a partner sign-in with no employee record
| and no permission, disable / enable login, the permission gates, tenant isolation, the register, desk and report.
*/

function wo15Gst(string $first14): string
{
    return $first14.RegistrationNumberFormat::luhnMod36($first14);
}

/** @return array<string, list<string>> the field errors of a ValidationException thrown by $call */
function wo15Errors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

beforeEach(function () {
    $this->travelTo('2026-10-31 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    Notification::fake();
    $this->vendors = app(Vendors::class);
    $this->partners = app(BusinessPartners::class);
    $this->partnerData = fn (array $extra = []) => [
        'salutation' => 'dr', 'first_name' => 'Asha', 'last_name' => 'Rao', 'partner_type' => 'bgv',
        'isd_code' => '91', 'mobile' => '098450 12345', 'official_email' => 'Asha.Rao@verify.example', ...$extra,
    ];
});

it('creates and edits vendors through the service: code, kind, GST number, PAN kept encrypted', function () {
    $gst = wo15Gst('29ABCDE1234F1Z');
    $vendor = $this->vendors->create(['code' => 'V-001', 'name' => 'Acme Staffing', 'kind' => 'agency', 'gstin' => strtolower($gst), 'pan' => 'abcde1234f',
        'contact_name' => 'Ravi', 'email' => 'Ravi@Acme.example', 'phone' => '+91 80 4000 1234', 'city' => 'Bengaluru', 'state' => 'ka', 'postal_code' => '560001', 'tenant_id' => 999], $this->hr);

    expect($vendor->tenant_id)->toBe($this->tenant->id)
        ->and($vendor->gstin)->toBe($gst)
        ->and($vendor->pan)->toBe('ABCDE1234F')
        ->and($vendor->pan_last4)->toBe('234F')
        ->and($vendor->maskedPan())->toBe('••••••234F')
        ->and($vendor->email)->toBe('ravi@acme.example')
        ->and($vendor->country)->toBe('IN')
        ->and($vendor->status)->toBe('active')
        ->and($vendor->toArray())->not->toHaveKey('pan')
        ->and(DB::table('vendors')->value('pan'))->not->toBe('ABCDE1234F');
    $create = AuditEvent::query()->withoutGlobalScopes()->where('entity_type', $vendor->getMorphClass())->where('entity_id', (string) $vendor->id)->where('action', AuditAction::Create->value)->firstOrFail();
    $pan = AuditEventChange::query()->where('audit_event_id', $create->id)->where('field', 'pan')->firstOrFail();
    expect($pan->after)->not->toContain('ABCDE1234F');

    // Validation lives in the service (the upload uses the same rules).
    expect(wo15Errors(fn () => $this->vendors->create(['code' => 'v-001', 'name' => 'Copy', 'kind' => 'agency'], $this->hr)))->toHaveKey('code')
        ->and(wo15Errors(fn () => $this->vendors->create(['code' => 'V 2', 'name' => 'Space', 'kind' => 'agency'], $this->hr)))->toHaveKey('code')
        ->and(wo15Errors(fn () => $this->vendors->create(['code' => 'V-002', 'name' => 'Bad check', 'kind' => 'agency', 'gstin' => substr($gst, 0, 14).($gst[14] === 'A' ? 'B' : 'A')], $this->hr)))->toHaveKey('gstin')
        ->and(wo15Errors(fn () => $this->vendors->create(['code' => 'V-002', 'name' => 'Wrong state', 'kind' => 'agency', 'gstin' => $gst, 'state' => 'MH'], $this->hr)))->toHaveKey('gstin')
        ->and(wo15Errors(fn () => $this->vendors->create(['code' => 'V-002', 'name' => 'Other PAN', 'kind' => 'agency', 'gstin' => $gst, 'pan' => 'ZZZZZ9999Z'], $this->hr)))->toHaveKey('pan')
        ->and(wo15Errors(fn () => $this->vendors->create(['code' => 'V-002', 'name' => 'Bad PAN', 'kind' => 'agency', 'pan' => 'ABC123'], $this->hr)))->toHaveKey('pan')
        ->and(wo15Errors(fn () => $this->vendors->create(['code' => 'V-002', 'name' => 'Odd kind', 'kind' => 'reseller'], $this->hr)))->toHaveKey('kind')
        ->and(wo15Errors(fn () => $this->vendors->create(['code' => 'V-002', 'kind' => 'agency'], $this->hr)))->toHaveKey('name');

    // An update changes only the fields given and keeps the PAN; it is audited with the reason.
    $this->vendors->update($vendor, ['status' => 'inactive', 'audit_reason' => 'Contract ended'], $this->hr);
    expect($vendor->fresh())->status->toBe('inactive')->name->toBe('Acme Staffing')->pan->toBe('ABCDE1234F')->gstin->toBe($gst)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', $vendor->getMorphClass())->where('action', AuditAction::Update->value)->where('reason', 'Contract ended')->exists())->toBeTrue();
});

it('numbers business partners from their series or takes a typed code: only a-z, A-Z, 0-9 and _', function () {
    $first = $this->partners->create(($this->partnerData)(), $this->hr);
    $second = $this->partners->create(($this->partnerData)(['first_name' => 'Ben', 'official_email' => null]), $this->hr);
    $typed = $this->partners->create(($this->partnerData)(['code' => 'ext_Consultant_7', 'first_name' => 'Chitra']), $this->hr);

    expect([$first->code, $second->code, $typed->code])->toBe(['BP00001', 'BP00002', 'ext_Consultant_7'])
        ->and($first->displayName())->toBe('Dr Asha Rao')
        ->and($first->mobile)->toBe('+919845012345')
        ->and($first->mobileDisplay())->toBe('+91 9845012345')
        ->and($first->official_email)->toBe('asha.rao@verify.example')
        ->and($first->user_id)->toBeNull()
        ->and($this->partners->previewCode())->toBe('BP00003');
    // Numbers from a sheet or a select keyed by dialling codes arrive as integers: taken as text.
    expect($this->partners->create(($this->partnerData)(['isd_code' => 91, 'mobile' => 9845012399]), $this->hr)->mobile)->toBe('+919845012399');

    expect(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['code' => 'bad-code']), $this->hr))['code'][0])->toBe('Only a-z, A-Z, 0-9 and _ are allowed in a business partner code.')
        ->and(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['code' => 'two words']), $this->hr)))->toHaveKey('code')
        ->and(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['code' => 'EXT_CONSULTANT_7']), $this->hr)))->toHaveKey('code')
        ->and(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['partner_type' => 'recruiter']), $this->hr)))->toHaveKey('partner_type')
        ->and(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['first_name' => null]), $this->hr)))->toHaveKey('first_name')
        ->and(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['mobile' => '12']), $this->hr)))->toHaveKey('mobile')
        ->and(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['official_email' => 'not-an-address']), $this->hr)))->toHaveKey('official_email')
        ->and(wo15Errors(fn () => $this->partners->create(($this->partnerData)(['date_of_birth' => '2027-01-01']), $this->hr)))->toHaveKey('date_of_birth')
        ->and(wo15Errors(fn () => $this->partners->update($first, ['code' => null], $this->hr)))->toHaveKey('code');

    // A series for partner codes may not use characters a code may not hold.
    $series = app(NumberSeries::class);
    expect($series->problemWith(new CodeSeries(['key' => 'business_partner', 'prefix' => 'BP-', 'suffix' => '', 'digits' => 5, 'start_number' => 1])))->toBe(['prefix', 'Use at most 24 characters: letters, digits, _ and {YYYY} {YY} {MM} {FY}.'])
        ->and($series->problemWith(new CodeSeries(['key' => 'business_partner', 'prefix' => 'EXT_{YY}', 'suffix' => '', 'digits' => 4, 'start_number' => 1])))->toBeNull()
        ->and($series->problemWith(new CodeSeries(['key' => 'loan', 'prefix' => 'LN-', 'suffix' => '', 'digits' => 5, 'start_number' => 1])))->toBeNull();

    // A vendor link: only a vendor of this organisation that is active (an existing link survives inactivation).
    $vendor = $this->vendors->create(['code' => 'V1', 'name' => 'Verify Co', 'kind' => 'supplier'], $this->hr);
    $this->partners->update($first, ['vendor_id' => $vendor->id], $this->hr);
    $this->vendors->update($vendor, ['status' => 'inactive'], $this->hr);
    $this->partners->update($first->fresh(), ['nationality' => 'Indian'], $this->hr);
    expect($first->fresh()->vendor_id)->toBe($vendor->id)
        ->and(wo15Errors(fn () => $this->partners->update($second, ['vendor_id' => $vendor->id], $this->hr))['vendor_id'][0])->toContain('inactive');
});

it('invites a partner: a partner sign-in with no employee record and no permission, by the normal invitation', function () {
    $partner = $this->partners->create(($this->partnerData)(), $this->hr);
    $user = $this->partners->invite($partner, $this->hr);

    expect($user->isPartner())->toBeTrue()
        ->and($user->fresh()->kind)->toBe(UserKind::Partner)
        ->and($user->status)->toBe(UserStatus::Invited)
        ->and($user->email)->toBe('asha.rao@verify.example')
        ->and($user->name)->toBe('Dr Asha Rao')
        ->and($partner->fresh()->user_id)->toBe($user->id)
        ->and($partner->fresh()->signInState())->toBe('invited')
        ->and(Employee::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('role_user')->where('user_id', $user->id)->count())->toBe(0)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => $user->fresh()->permissionKeys()->all()))->toBe([])
        ->and($user->fresh()->hasPermission('employee.view'))->toBeFalse()
        ->and($user->mustReplacePassword())->toBeTrue();
    Notification::assertSentTo($user, TemporaryPasswordMail::class);
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::TemporaryPasswordIssued->value)->where('entity_id', (string) $user->id)->exists())->toBeTrue();

    // Never an employee's sign-in, and the Users list tells partners apart.
    $employee = Employee::factory()->create();
    expect(fn () => $employee->forceFill(['user_id' => $user->id])->save())->toThrow(RuntimeException::class, 'A business partner\'s sign-in cannot be linked to an employee.');
    Livewire::test(ListUsers::class)->filterTable('kind', UserKind::Partner->value)->assertCanSeeTableRecords([$user])->assertCanNotSeeTableRecords([$this->hr]);

    // Still invited: a new invitation (the earlier temporary password stops working).
    $this->partners->invite($partner->fresh(), $this->hr);
    Notification::assertSentToTimes($user, TemporaryPasswordMail::class, 2);
    expect(User::query()->where('kind', 'partner')->count())->toBe(1);

    // Refusals, with the reason.
    $noMail = $this->partners->create(($this->partnerData)(['official_email' => null, 'personal_email' => null]), $this->hr);
    $taken = $this->partners->create(($this->partnerData)(['official_email' => $this->hr->email]), $this->hr);
    $inactive = $this->partners->create(($this->partnerData)(['official_email' => 'gone@verify.example', 'status' => 'inactive']), $this->hr);
    expect($this->partners->inviteRefusal($noMail, $this->hr))->toBe('No e-mail on file: add an official or personal e-mail first.')
        ->and($this->partners->inviteRefusal($taken, $this->hr))->toBe('The e-mail '.mb_strtolower($this->hr->email).' is already used by another user.')
        ->and($this->partners->inviteRefusal($inactive, $this->hr))->toBe('The business partner is inactive: make them active first.')
        ->and(fn () => $this->partners->invite($noMail, $this->hr))->toThrow(RuntimeException::class, 'No e-mail on file');
    $user->forceFill(['status' => UserStatus::Active, 'email_verified_at' => now()])->save();
    expect($this->partners->inviteRefusal($partner->fresh(), $this->hr))->toStartWith('Already signs in.');
});

it('gives a partner only the roles chosen, within the delegation ceiling', function () {
    $employeeRole = Role::query()->where('slug', 'employee')->firstOrFail();
    $partner = $this->partners->create(($this->partnerData)(), $this->hr);
    $user = $this->partners->invite($partner, $this->hr, [$employeeRole->id]);
    expect($user->fresh()->roleSlugs())->toBe(['employee']);

    // Without user.assign_roles, or a custom role beyond the inviter's own permissions: refused, nobody is created.
    $inviter = tenantUser($this->tenant, ['partner.manage', 'user.create', 'user.update']);
    $other = $this->partners->create(($this->partnerData)(['official_email' => 'b@verify.example']), $this->hr);
    expect(fn () => $this->partners->invite($other, $inviter, [$employeeRole->id]))->toThrow(RuntimeException::class, 'You cannot give roles.');
    $assigner = tenantUser($this->tenant, ['partner.manage', 'user.create', 'user.update', 'user.assign_roles']);
    $payroll = Role::factory()->create(['name' => 'Payroll viewer']);
    $payroll->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['payroll.view']));
    expect(fn () => $this->partners->invite($other, $assigner, [$payroll->id]))->toThrow(RuntimeException::class, 'Payroll viewer')
        ->and($other->fresh()->user_id)->toBeNull()
        ->and(User::query()->where('email', 'b@verify.example')->exists())->toBeFalse();
});

it('disables and enables a partner login', function () {
    $partner = $this->partners->create(($this->partnerData)(), $this->hr);
    $user = $this->partners->invite($partner, $this->hr);
    $epoch = (int) $user->fresh()->session_epoch;

    // Invited partner: suspended, the unused temporary password withdrawn, sessions ended.
    $this->partners->disableLogin($partner->fresh(), $this->hr, 'Engagement paused');
    $user->refresh();
    expect($user->status)->toBe(UserStatus::Suspended)
        ->and($user->mustReplacePassword())->toBeFalse()
        ->and((int) $user->session_epoch)->toBe($epoch + 1)
        ->and($partner->fresh()->signInState())->toBe('disabled')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_id', (string) $user->id)->where('action', AuditAction::Update->value)->where('reason', 'like', '%login disabled (Engagement paused)')->exists())->toBeTrue()
        ->and($this->partners->disableRefusal($partner->fresh(), $this->hr))->toBe('The login is already disabled.');
    // Never chose a password: back to invited, to be invited again.
    expect($this->partners->enableLogin($partner->fresh(), $this->hr))->toBe(UserStatus::Invited)
        ->and($this->partners->enableRefusal($partner->fresh(), $this->hr))->toBe('The login is not disabled.')
        ->and($this->partners->inviteRefusal($partner->fresh(), $this->hr))->toBeNull();

    // A partner who signs in keeps their own password across disable / enable.
    $user->refresh()->forceFill(['status' => UserStatus::Active, 'email_verified_at' => now()])->save();
    $this->partners->disableLogin($partner->fresh(), $this->hr);
    expect($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($this->partners->enableLogin($partner->fresh(), $this->hr))->toBe(UserStatus::Active)
        ->and($user->fresh()->status)->toBe(UserStatus::Active);

    // Making the partner inactive disables the login too; enabling waits for the partner to be active again.
    $this->partners->update($partner->fresh(), ['status' => 'inactive', 'audit_reason' => 'Contract over'], $this->hr);
    expect($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($this->partners->enableRefusal($partner->fresh(), $this->hr))->toBe('The business partner is inactive: make them active first.');
    $noUser = $this->partners->create(($this->partnerData)(['official_email' => 'n@verify.example']), $this->hr);
    expect($this->partners->disableRefusal($noUser, $this->hr))->toBe('Has no sign-in.');
});

it('gates partners and vendors by partner.view and partner.manage, and the sign-in by the user permissions', function () {
    $partner = $this->partners->create(($this->partnerData)(), $this->hr);
    $nobody = tenantUser($this->tenant, ['employee.view']);
    $viewer = tenantUser($this->tenant, ['partner.view']);
    $manager = tenantUser($this->tenant, ['partner.manage']);

    $this->actingAs($nobody);
    expect(BusinessPartnerResource::canAccess())->toBeFalse()->and(VendorResource::canAccess())->toBeFalse();
    $this->get(BusinessPartnerResource::getUrl())->assertForbidden();
    expect(fn () => $this->vendors->create(['code' => 'V9', 'name' => 'X', 'kind' => 'other'], $nobody))->toThrow(AuthorizationException::class);

    $this->actingAs($viewer);
    expect(BusinessPartnerResource::canAccess())->toBeTrue()->and(VendorResource::canAccess())->toBeTrue()
        ->and($viewer->can('create', BusinessPartner::class))->toBeFalse()
        ->and(fn () => $this->partners->create(($this->partnerData)(), $viewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->partners->update($partner, ['first_name' => 'Z'], $viewer))->toThrow(AuthorizationException::class)
        ->and($this->partners->inviteRefusal($partner, $viewer))->toBe('You cannot manage business partners.');
    Livewire::test(ManageBusinessPartners::class)->assertCanSeeTableRecords([$partner])->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('invite')->table($partner));

    // partner.manage alone: the record, not the sign-in (user.create / user.update).
    $this->actingAs($manager);
    expect($this->partners->inviteRefusal($partner, $manager))->toBe('You cannot create users.');
    $this->partners->invite($partner, $this->hr);
    expect($this->partners->inviteRefusal($partner->fresh(), $manager))->toBe('You cannot send a new invitation to this sign-in.')
        ->and($this->partners->disableRefusal($partner->fresh(), $manager))->toBe('You cannot change this sign-in.')
        ->and($this->partners->update($partner->fresh(), ['nationality' => 'Kenyan'], $manager)->nationality)->toBe('Kenyan');
    $this->get(BusinessPartnerResource::getUrl())->assertOk();

    // The keys are declared, and the HR admin template holds them.
    $hrAdmin = Role::query()->where('slug', 'tenant-hr-admin')->firstOrFail();
    expect(config('peopleos.permissions.partner'))->toHaveKeys(['partner.view', 'partner.manage'])
        ->and($hrAdmin->permissions()->pluck('key')->all())->toContain('partner.view', 'partner.manage');
});

it('keeps partners and vendors inside their organisation', function () {
    $vendor = $this->vendors->create(['code' => 'V1', 'name' => 'Home vendor', 'kind' => 'agency'], $this->hr);
    $partner = $this->partners->create(($this->partnerData)(['vendor_id' => $vendor->id]), $this->hr);

    $other = provisionTenant('Globex');
    actAsTenant($other);
    $otherHr = tenantUser($other, ['*']);
    $this->actingAs($otherHr);
    $theirs = $this->partners->create(($this->partnerData)(['first_name' => 'Zed']), $otherHr);
    expect($theirs->code)->toBe('BP00001')
        ->and(BusinessPartner::query()->pluck('id')->all())->toBe([$theirs->id])
        ->and(Vendor::query()->count())->toBe(0)
        ->and(wo15Errors(fn () => $this->partners->update($theirs, ['vendor_id' => $vendor->id], $otherHr))['vendor_id'][0])->toBe('Choose a vendor of this organisation.')
        ->and(fn () => $this->partners->update($partner, ['first_name' => 'Hijack'], $otherHr))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->vendors->update($vendor, ['name' => 'Hijack'], $otherHr))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->partners->disableLogin($partner, $otherHr))->toThrow(AuthorizationException::class);
    // The same code and e-mail may be used in another organisation's partner list; a sign-in e-mail is unique platform-wide.
    $this->vendors->create(['code' => 'V1', 'name' => 'Their vendor', 'kind' => 'agency'], $otherHr);
    $this->partners->invite($theirs, $otherHr);
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);
    expect($this->partners->inviteRefusal($partner->fresh(), $this->hr))->toBe('The e-mail asha.rao@verify.example is already used by another user.')
        ->and($partner->fresh()->first_name)->toBe('Asha');
    // A user id from elsewhere cannot be written through the service.
    $this->partners->update($partner, ['user_id' => $otherHr->id, 'tenant_id' => $other->id], $this->hr);
    expect($partner->fresh())->user_id->toBeNull()->tenant_id->toBe($this->tenant->id);
});

it('opens "Add business partner" from the URL and saves through the wizard, with the service errors on the fields', function () {
    // ?action=create makes the page mount its "create" action as it loads (Filament's default action).
    $this->get(BusinessPartnerResource::getUrl('index', ['action' => 'create']))->assertOk()->assertSee("mountAction('create'", false);
    $this->get(VendorResource::getUrl('index', ['action' => 'create']))->assertOk()->assertSee("mountAction('create'", false);
    Livewire::test(ManageBusinessPartners::class)->mountAction('create')->assertActionMounted('create');
    Livewire::test(ManageVendors::class)->mountAction('create')->assertActionMounted('create');

    Livewire::test(ManageBusinessPartners::class)
        ->callAction('create', ['first_name' => 'Meera', 'last_name' => 'Iyer', 'partner_type' => 'customer', 'status' => 'active', 'isd_code' => '91', 'mobile' => '9845012345', 'official_email' => 'meera@client.example'])
        ->assertHasNoActionErrors();
    $meera = BusinessPartner::query()->where('first_name', 'Meera')->firstOrFail();
    expect($meera->code)->toBe('BP00001')->and($meera->mobile)->toBe('+919845012345');

    Livewire::test(ManageBusinessPartners::class)
        ->callAction('create', ['code' => 'bp00001', 'first_name' => 'Dup', 'partner_type' => 'customer', 'status' => 'active'])
        ->assertHasActionErrors(['code']);
    expect(BusinessPartner::query()->count())->toBe(1);

    Livewire::test(ManageVendors::class)
        ->callAction('create', ['code' => 'ACME', 'name' => 'Acme', 'kind' => 'supplier', 'status' => 'active', 'country' => 'IN', 'pan' => 'ABCDE1234F'])
        ->assertHasNoActionErrors();
    $acme = Vendor::query()->where('code', 'ACME')->firstOrFail();
    // Editing with the PAN left blank keeps the one on file.
    Livewire::test(ManageVendors::class)->callAction(TestAction::make('edit')->table($acme), ['name' => 'Acme Ltd', 'pan' => null])->assertHasNoActionErrors();
    expect($acme->fresh())->name->toBe('Acme Ltd')->pan->toBe('ABCDE1234F');

    // The ⋮ actions: invite, disable, enable.
    Livewire::test(ManageBusinessPartners::class)->callAction(TestAction::make('invite')->table($meera), ['roles' => []])->assertNotified('Invitation sent to meera@client.example');
    expect($meera->fresh()->signInState())->toBe('invited');
    Livewire::test(ManageBusinessPartners::class)->assertActionHidden(TestAction::make('enableLogin')->table($meera->fresh()))
        ->callAction(TestAction::make('disableLogin')->table($meera->fresh()), ['reason' => 'Paused'])->assertNotified('Login disabled');
    Livewire::test(ManageBusinessPartners::class)->callAction(TestAction::make('enableLogin')->table($meera->fresh()))
        ->assertNotified('Login enabled: send them a new invitation to sign in');
});

it('lists partners as a table or as cards, filtered by type, status and sign-in', function () {
    $bgv = $this->partners->create(($this->partnerData)(), $this->hr);
    $customer = $this->partners->create(($this->partnerData)(['first_name' => 'Kiran', 'partner_type' => 'customer', 'official_email' => 'kiran@client.example']), $this->hr);
    $old = $this->partners->create(($this->partnerData)(['first_name' => 'Old', 'partner_type' => 'other', 'status' => 'inactive', 'official_email' => null]), $this->hr);
    $this->partners->invite($customer, $this->hr);

    Livewire::test(ManageBusinessPartners::class)->assertCanSeeTableRecords([$bgv, $customer, $old])
        ->assertSee('Dr Asha Rao')->assertSee('asha.rao@verify.example')->assertSee('+91 9845012345')->assertSee('Background verification')
        ->filterTable('partner_type', 'customer')->assertCanSeeTableRecords([$customer])->assertCanNotSeeTableRecords([$bgv, $old]);
    Livewire::test(ManageBusinessPartners::class)->filterTable('status', 'inactive')->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$bgv, $customer]);
    Livewire::test(ManageBusinessPartners::class)->filterTable('sign_in', 'invited')->assertCanSeeTableRecords([$customer])->assertCanNotSeeTableRecords([$bgv, $old]);
    Livewire::test(ManageBusinessPartners::class)->filterTable('sign_in', 'none')->assertCanSeeTableRecords([$bgv, $old])->assertCanNotSeeTableRecords([$customer]);
    Livewire::test(ManageBusinessPartners::class)->searchTable('kiran@client')->assertCanSeeTableRecords([$customer])->assertCanNotSeeTableRecords([$bgv]);
    Livewire::withQueryParams(['layout' => 'cards'])->test(ManageBusinessPartners::class)->assertCanSeeTableRecords([$bgv, $customer])->assertSee('Invited')->assertSee('Not invited');
    Livewire::withQueryParams(['view' => 'not_invited'])->test(ManageBusinessPartners::class)->assertCanSeeTableRecords([$bgv])->assertCanNotSeeTableRecords([$customer, $old]);
    $this->get(BusinessPartnerResource::getUrl('index', ['layout' => 'cards']))->assertOk();
    $this->get(VendorResource::getUrl())->assertOk();
});

it('puts partners on the lifecycle desk, in Setup and in the report library; classifies both tables', function () {
    $sections = collect((new WorkforceDesk)->deskSections());
    $items = collect($sections->firstWhere('label', 'Business partners')['items'])->keyBy('label');
    expect($items->keys()->all())->toContain('Business partners', 'Add business partner', 'Not invited yet', 'Vendors')
        ->and($items['Add business partner']['url'])->toBe(BusinessPartnerResource::getUrl('index', ['action' => 'create']));
    expect(collect(config('peopleos_setup.modules.core.links'))->pluck('target'))->toContain(VendorResource::class)
        ->and(collect(config('peopleos_setup.modules.workforce.links'))->pluck('target'))->toContain(BusinessPartnerResource::class);

    $partner = $this->partners->create(($this->partnerData)(['date_of_birth' => '1990-05-04']), $this->hr);
    $this->partners->invite($partner, $this->hr);
    $template = app(ReportLibrary::class)->find('business_partners');
    $rows = app(ReportRunner::class)->execute('partners', $template['definition'], $this->hr)->rows;
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['code' => 'BP00001', 'name' => 'Dr Asha Rao', 'partner_type' => 'Background verification', 'sign_in' => 'Invited', 'status' => 'Active']);
    $viewer = tenantUser($this->tenant, ['partner.view']);
    expect(app(ReportRunner::class)->execute('partners', ['fields' => ['code', 'date_of_birth']], $viewer)->columns)->not->toHaveKey('date_of_birth')
        ->and(collect(app(ReportRunner::class)->execute('partners', ['fields' => ['code', 'date_of_birth']], $this->hr)->rows)->first())->toHaveKey('date_of_birth');

    expect(DataInventory::categoryOf('business_partners'))->toBe('identity')
        ->and(DataInventory::categoryOf('vendors'))->toBe('organisation')
        ->and(config('peopleos.data_classification.highly_sensitive')[Vendor::class])->toBe(['pan']);
});

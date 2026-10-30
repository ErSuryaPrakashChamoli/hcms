<?php

use App\Domain\Analytics\Datasets\EmployeesDataset;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Support\RegistrationNumberFormat;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\SettingFiles;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Letters\Services\LetterContext;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\OrganisationContact;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Domain\People\Models\Skill;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Services\SkillScales;
use App\Filament\Pages\PlatformOperationsPage;
use App\Filament\Pages\SetupCentre;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\OrganisationContacts\OrganisationContactResource;
use App\Filament\Support\PolicySettingsSchema;
use App\Filament\Support\TenantBranding;
use App\Http\Controllers\ParityFileController;
use Filament\Support\Colors\Color;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity — Core HR (CA-1 Setup plumbing, CA-2 Organisation master fields). */

beforeEach(function () {
    Storage::fake(config('peopleos.documents.disk'));
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->catalogue = app(SetupCatalogue::class);
});

/** The bytes of a real PNG image. */
function coreHrPng(): string
{
    $image = UploadedFile::fake()->image('logo.png', 40, 40);

    return (string) file_get_contents($image->getRealPath());
}

/** A real PNG stored in this organisation's settings folder (as the Setup upload stores it). */
function coreHrStoreLogo(string $name = 'logo.png'): string
{
    $path = app(SettingFiles::class)->directory().'/'.$name;
    Storage::disk(SettingFiles::disk())->put($path, coreHrPng());

    return $path;
}

/** Adds a matrix setting to Core HR for the test (no shipped setting uses one yet). */
function coreHrMatrixSetting(): void
{
    config(['peopleos.settings' => config('peopleos.settings') + ['parity.section_matrix' => ['bank' => ['employee' => true, 'manager' => true]]]]);
    config(['peopleos_setup.modules.core.settings' => [...config('peopleos_setup.modules.core.settings'),
        ['key' => 'parity.section_matrix', 'label' => 'Profile sections', 'type' => 'matrix', 'rows' => ['bank' => 'Bank', 'family' => 'Family'], 'columns' => ['employee' => 'Employee', 'manager' => 'Manager']]]]);
}

it('keeps a matrix setting as rows of tick boxes, stored as booleans, and refuses unknown rows or columns', function () {
    coreHrMatrixSetting();
    $field = SetupCatalogue::field('parity.section_matrix');
    $setting = collect($this->catalogue->settingsFor('core'))->firstWhere('key', 'parity.section_matrix');
    expect(SetupCatalogue::matrixToForm($setting, $setting['value']))->toBe(['bank' => ['employee', 'manager'], 'family' => []]);

    $changed = $this->catalogue->save('core', [$field => ['bank' => ['employee'], 'family' => ['employee', 'manager']]], 'Hide bank from managers', $this->admin);
    expect($changed)->toBe(['parity.section_matrix'])
        ->and(app(SettingsRepository::class)->get('parity.section_matrix'))->toBe(['bank' => ['employee' => true, 'manager' => false], 'family' => ['employee' => true, 'manager' => true]])
        ->and($this->catalogue->save('core', [$field => ['bank' => ['employee' => true], 'family' => ['employee' => true, 'manager' => true]]], 'Same', $this->admin))->toBe([]);

    expect(fn () => $this->catalogue->save('core', [$field => ['bank' => ['others']]], 'x', $this->admin))->toThrow(RuntimeException::class, 'column that does not exist')
        ->and(fn () => $this->catalogue->save('core', [$field => ['salary' => ['employee']]], 'x', $this->admin))->toThrow(RuntimeException::class, 'row that does not exist');

    Livewire::test(SetupCentre::class)->assertOk()->assertSee('Profile sections')->assertSee('Family')
        ->assertSet("data.{$field}.bank", ['employee']);
});

it('keeps a logo as a Setup file: an image in the organisation\'s folder, replaced files removed, served signed and audited', function () {
    $field = SetupCatalogue::field('branding.logo_path');
    $disk = Storage::disk(SettingFiles::disk());
    $first = coreHrStoreLogo('first.png');

    $this->catalogue->save('core', [$field => $first], 'Brand', $this->admin);
    expect(app(SettingsRepository::class)->get('branding.logo_path'))->toBe($first);

    // Not an image, outside the folder, or missing: refused.
    $disk->put(app(SettingFiles::class)->directory().'/notes.png', 'plain text, not an image');
    expect(fn () => $this->catalogue->save('core', [$field => app(SettingFiles::class)->directory().'/notes.png'], 'x', $this->admin))->toThrow(RuntimeException::class, 'PNG or JPEG')
        ->and(fn () => $this->catalogue->save('core', [$field => 'tenants/999/settings/logo.png'], 'x', $this->admin))->toThrow(RuntimeException::class, 'not available');

    $second = coreHrStoreLogo('second.png');
    $this->catalogue->save('core', [$field => $second], 'New brand', $this->admin);
    $disk->assertMissing($first);
    $disk->assertExists($second);

    // Every signed-in person of the organisation may fetch the logo (a public setting); the link is signed and audited.
    $url = app(SettingFiles::class)->url('branding.logo_path');
    $this->get(route('settings.file', ['key' => 'branding.logo_path']))->assertForbidden();
    $this->actingAs(tenantUser($this->tenant, ['employee.self']))->get($url)->assertOk();
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', 'ATTACHMENT_DOWNLOADED')->where('module', 'configuration')->exists())->toBeTrue()
        ->and(TenantBranding::logoUrl())->not->toBeNull();

    // Another organisation's people never reach it: the setting is read in their own organisation, which has no logo.
    $other = provisionTenant('Other');
    $this->actingAs(tenantUser($other, ['*']))->get($url)->assertNotFound();
    actAsTenant($this->tenant);

    // A setting that is not public needs settings.view.
    config(['peopleos_setup.modules.core.settings' => collect(config('peopleos_setup.modules.core.settings'))->map(fn ($s) => $s['key'] === 'branding.logo_path' ? ['public' => false] + $s : $s)->all()]);
    $this->actingAs(tenantUser($this->tenant, ['employee.self']))->get($url)->assertForbidden();

    // Clearing the setting removes the file.
    $this->actingAs($this->admin);
    $this->catalogue->save('core', [$field => null], 'Back to PeopleOS', $this->admin);
    $disk->assertMissing($second);
    expect(app(SettingsRepository::class)->get('branding.logo_path'))->toBeNull();
});

it('offers articles, skill scales, dashboards and industries as option sources in Setup and in policy forms', function () {
    $article = Article::create(['title' => 'Privacy policy', 'slug' => 'privacy', 'category' => 'hr_policy', 'body' => 'Text', 'status' => 'published', 'version' => 1, 'published_version' => 1]);
    Article::create(['title' => 'Draft only', 'slug' => 'draft', 'category' => 'hr_policy', 'body' => 'Text', 'status' => 'draft', 'version' => 1]);
    $policyOptions = fn (string $source) => (new ReflectionMethod(PolicySettingsSchema::class, 'options'))->invoke(null, ['options_from' => $source]);

    expect($this->catalogue->optionsFor(['type' => 'select', 'options_from' => 'articles']))->toBe([$article->id => 'Privacy policy'])
        ->and($policyOptions('articles'))->toBe([$article->id => 'Privacy policy'])
        ->and($this->catalogue->optionsFor(['type' => 'select', 'options_from' => 'skill_scales']))->toHaveKey(0)
        ->and($this->catalogue->optionsFor(['type' => 'select', 'options_from' => 'industries']))->toHaveKey('it_ites')
        ->and($policyOptions('industries'))->toHaveKey('manufacturing')
        ->and(array_values($this->catalogue->optionsFor(['type' => 'select', 'options_from' => 'dashboards'])))->toBe(array_values($policyOptions('dashboards')));
});

it('lists an Access rights module with roles, users and their data access', function () {
    expect(array_keys($this->catalogue->visibleModules($this->admin)))->toContain('access')
        ->and(collect($this->catalogue->links('access', $this->admin))->pluck('label')->all())->toContain('Roles (page and action access)', 'Users and their data access');
    Livewire::test(SetupCentre::class)->call('selectModule', 'access')->assertOk()->assertSee('Roles by person');

    // HROne parity CA-9: the module now carries settings (widget, calendar and geo access), which settings.view reads like
    // every other module's settings; its screens (roles, users, data access groups) still need their own permissions.
    $this->actingAs(tenantUser($this->tenant, ['settings.view']));
    expect(array_keys($this->catalogue->visibleModules(auth()->user())))->toContain('access')
        ->and($this->catalogue->links('access', auth()->user()))->toBe([]);
});

it('keeps blank selects and validates formats of Enterprise settings', function () {
    $this->catalogue->save('core', [SetupCatalogue::field('organisation.industry') => 'it_ites', SetupCatalogue::field('branding.website') => 'https://acme.example'], 'Enterprise', $this->admin);
    expect(app(SettingsRepository::class)->get('organisation.industry'))->toBe('it_ites');
    $this->catalogue->save('core', [SetupCatalogue::field('organisation.industry') => ''], 'Unknown', $this->admin);
    expect(app(SettingsRepository::class)->get('organisation.industry'))->toBeNull()
        ->and(fn () => $this->catalogue->save('core', [SetupCatalogue::field('branding.theme_colour') => 'teal'], 'x', $this->admin))->toThrow(RuntimeException::class, 'expected format')
        ->and(fn () => $this->catalogue->save('core', [SetupCatalogue::field('organisation.industry') => 'space'], 'x', $this->admin))->toThrow(RuntimeException::class, 'Choose a value');
});

it('applies the organisation colour and theme switch rule, and changes nothing by default', function () {
    $teal = Color::hex('#0f766e')[600];
    expect(TenantBranding::head())->toBe('')
        ->and(TenantBranding::colours())->toBe([])
        ->and(TenantBranding::mayChangeTheme($employee = tenantUser($this->tenant, ['employee.self'])))->toBeTrue()
        ->and(TenantBranding::logoUrl())->toBeNull();

    $settings = app(SettingsRepository::class);
    $settings->set('branding.theme_colour', '#0F766E', 'Brand');
    $settings->set('branding.employee_may_change_theme', false, 'One look');
    expect(TenantBranding::themeColour())->toBe('#0f766e')
        ->and(TenantBranding::colours())->toBe(['primary' => Color::hex('#0f766e')])
        ->and(TenantBranding::mayChangeTheme($employee))->toBeFalse()
        ->and(TenantBranding::mayChangeTheme($this->admin))->toBeTrue();
    // Filament reads the colour when the page first needs one (one request per process; the test app keeps its cache).
    $this->get('/admin')->assertOk()->assertSee('--primary-600:'.$teal, false);
    $this->actingAs($employee);
    expect(TenantBranding::head())->toContain('localStorage.removeItem');
});

it('gives companies an industry, contact details, social links and a logo; establishments inherit the industry', function () {
    app(SettingsRepository::class)->set('organisation.industry', 'manufacturing', 'Factories');
    $company = Company::factory()->create(['name' => 'Acme Works', 'code' => 'ACMEW']);
    $establishment = Establishment::query()->where('company_id', $company->id)->firstOrFail();
    expect($company->industry)->toBe('manufacturing')->and($establishment->industry)->toBe('manufacturing');

    $retail = Company::factory()->create(['code' => 'SHOP', 'industry' => 'retail']);
    expect(Establishment::query()->where('company_id', $retail->id)->value('industry'))->toBe('retail');

    $contact = employeeWithUser();
    $logo = Company::logoDirectory().'/acme.png';
    Storage::disk(config('peopleos.documents.disk'))->put($logo, coreHrPng());
    $company->update(['phone' => '+91 80 4000 1000', 'fax' => '+91 80 4000 1001', 'contact_employee_id' => $contact->id, 'social_links' => ['linkedin' => 'https://www.linkedin.com/company/acme'], 'logo_path' => $logo]);

    $this->get(CompanyResource::getUrl('view', ['record' => $company]))->assertOk()->assertSee('+91 80 4000 1000')->assertSee('LinkedIn');
    $this->get(CompanyResource::getUrl('edit', ['record' => $company]))->assertOk();
    $this->get(ParityFileController::url('company-logo', $company, 0))->assertOk();
    $this->actingAs(tenantUser($this->tenant, ['employee.self']))->get(ParityFileController::url('company-logo', $company, 0))->assertForbidden();

    // Letters print the company's contact details and the organisation's website.
    $this->actingAs($this->admin);
    app(SettingsRepository::class)->set('branding.website', 'https://acme.example', 'Site');
    EmployeePosition::query()->where('employee_id', $contact->id)->update(['company_id' => $company->id]);
    $context = app(LetterContext::class)->build($contact->refresh());
    expect($context['company']['phone'])->toBe('+91 80 4000 1000')
        ->and($context['company']['industry'])->toBe('Manufacturing')
        ->and($context['company']['contact_name'])->toBe($contact->person->full_name)
        ->and($context['organisation']['website'])->toBe('https://acme.example');
});

it('checks a GSTIN for its format, check character and state code', function () {
    expect(RegistrationNumberFormat::luhnMod36('27AAPFU0939F1Z'))->toBe('V');
    $establishment = Establishment::query()->where('company_id', Company::factory()->create()->id)->firstOrFail();
    $establishment->update(['state' => 'MH']);
    $register = fn (string $number) => StatutoryRegistration::create(['establishment_id' => $establishment->id, 'registration_type' => 'gstin', 'registration_number' => $number, 'effective_from' => '2026-04-01', 'status' => 'active']);

    expect($register('27aapfu0939f1zv')->registration_number)->toBe('27AAPFU0939F1ZV')
        ->and(fn () => $register('27AAPFU0939F1ZA'))->toThrow(RuntimeException::class, 'check character')
        ->and(fn () => $register('27AAPFU0939'))->toThrow(RuntimeException::class, 'wrong format (15 characters');

    $establishment->update(['state' => 'KA']);
    expect(fn () => $register('27AAPFU0939F1ZV'))->toThrow(RuntimeException::class, 'not the code of the registration\'s state (29)');
});

it('records a designation\'s experience range, job description, hazardous work and wage skill category', function () {
    $designation = Designation::factory()->create(['min_experience_years' => 2, 'max_experience_years' => 5.5, 'job_description' => '<p>Builds <strong>things</strong></p>', 'is_hazardous' => true, 'wage_skill_category' => 'skilled']);
    expect((float) $designation->fresh()->max_experience_years)->toBe(5.5)
        ->and($designation->fresh()->is_hazardous)->toBeTrue()
        ->and(fn () => $designation->update(['min_experience_years' => 6]))->toThrow(RuntimeException::class, 'experience range');
    $designation->refresh();
    expect(fn () => $designation->update(['wage_skill_category' => 'expert']))->toThrow(RuntimeException::class, 'wage skill category')
        ->and(Designation::factory()->create()->refresh()->is_hazardous)->toBeFalse();
});

it('names at most one primary skill in a role\'s requirements', function () {
    $scale = SkillScale::create(['code' => 'S5', 'name' => 'Five levels']);
    app(SkillScales::class)->publish($scale, [['value' => 1, 'label' => 'Aware'], ['value' => 3, 'label' => 'Practised'], ['value' => 5, 'label' => 'Expert']]);
    $go = Skill::create(['name' => 'Go', 'code' => 'GO', 'skill_scale_id' => $scale->id]);
    $sql = Skill::create(['name' => 'SQL', 'code' => 'SQL', 'skill_scale_id' => $scale->id]);
    $role = Designation::factory()->create();
    $architecture = app(CareerArchitecture::class);

    expect(fn () => $architecture->publishRequirements($role, null, ['skills' => [['skill_id' => $go->id, 'level' => 3, 'primary' => true], ['skill_id' => $sql->id, 'level' => 3, 'primary' => true]]], '2026-01-01', $this->admin))
        ->toThrow(RuntimeException::class, 'Only one skill can be the primary skill');
    $version = $architecture->publishRequirements($role, null, ['skills' => [['skill_id' => $go->id, 'level' => 3, 'primary' => true], ['skill_id' => $sql->id, 'level' => 1]]], '2026-01-01', $this->admin);
    expect(collect($version->skills)->firstWhere('primary', true)['skill_id'])->toBe($go->id)
        ->and(collect($version->skills)->firstWhere('skill_id', $sql->id))->not->toHaveKey('primary');
});

it('places sub-departments under a parent, refuses cycles, keeps the tree in step and gives policies and reports the parent', function () {
    $engineering = Department::factory()->create(['name' => 'Engineering']);
    $platform = Department::factory()->create(['name' => 'Platform', 'parent_department_id' => $engineering->id]);
    expect($platform->parentDepartment->is($engineering))->toBeTrue()
        ->and(fn () => $engineering->update(['parent_department_id' => $platform->id]))->toThrow(InvalidHierarchyException::class)
        ->and(fn () => $platform->refresh()->update(['parent_department_id' => $platform->id]))->toThrow(InvalidHierarchyException::class);

    // The organisation tree follows the parent set on the form, and the form follows a move in the designer.
    $tree = app(OrganisationTree::class);
    $root = $tree->attach(Company::factory()->create());
    $engineeringNode = $tree->attach($engineering->refresh(), $root);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $salesNode = $tree->attach($sales, $root);
    $platformNode = $tree->attach($platform->refresh(), $root);
    $platform->refresh()->update(['parent_department_id' => $sales->id]);
    expect($platformNode->refresh()->parent_id)->toBe($salesNode->id);
    $tree->move($platformNode->refresh(), $engineeringNode, 'Reorganised');
    expect($platform->refresh()->parent_department_id)->toBe($engineering->id);
    $tree->move($platformNode->refresh(), $root, 'Top level');
    expect($platform->refresh()->parent_department_id)->toBeNull();

    // Policies and reports see the parent department.
    $platform->update(['parent_department_id' => $engineering->id]);
    $employee = employeeWithUser();
    EmployeePosition::query()->where('employee_id', $employee->id)->update(['department_id' => $platform->id]);
    expect(app(EmployeeRuleContext::class)->build($employee->refresh())['parent_department_id'])->toBe($engineering->id)
        ->and(config('peopleos.rules.fields.parent_department_id.label'))->toBe('Parent department');
    $dataset = new EmployeesDataset;
    $row = $dataset->query()->whereKey($employee->id)->first();
    expect($dataset->value('department_parent', $row))->toBe('Engineering');
});

it('keeps the organisation\'s contacts, shown to platform operators', function () {
    OrganisationContact::create(['type' => 'technical_admin', 'name' => 'Ravi Iyer', 'email' => 'ravi@acme.example', 'mobile' => '+91 90000 00000']);
    OrganisationContact::create(['type' => 'hr', 'name' => 'Inactive Person', 'status' => 'inactive']);
    $this->get(OrganisationContactResource::getUrl('index'))->assertOk()->assertSee('Ravi Iyer')->assertSee('Technical administrator');

    $operator = platformAdmin();
    $tenant = $this->tenant;
    actAsTenant(null);
    $this->actingAs($operator);
    $contacts = (new PlatformOperationsPage)->contacts($tenant);
    expect($contacts)->toHaveCount(1)->and($contacts[0])->toMatchArray(['type' => 'Technical administrator', 'name' => 'Ravi Iyer', 'email' => 'ravi@acme.example']);

    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['employee.self']))->get(OrganisationContactResource::getUrl('index'))->assertForbidden();
});

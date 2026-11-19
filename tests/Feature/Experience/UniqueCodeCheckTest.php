<?php

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Filament\Pages\OrganisationDesigner;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\AssetCategories\Pages\ManageAssetCategories;
use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Support\Database\DuplicateEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| 2026-11-19 (owner: a duplicate department code ended in the server-error page). A code the database keeps unique
| is checked while it is typed: the field names the record already using it, or says it is available, and the save is
| refused before it reaches the database. A clash that still reaches the database becomes a message on the form.
*/

/** The HTML of the last update: only the typed-in field is re-rendered (a partial), so read that when there is one. */
function uniqueCodeFieldHtml(Testable $page): string
{
    $partials = $page->effects['partials'] ?? [];

    return $partials === [] ? $page->html() : implode('', $partials);
}

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->user = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->user);
    $this->company = Company::query()->first() ?? Company::factory()->create();
    $this->marketing = Department::query()->create(['company_id' => $this->company->id, 'name' => 'Digital Marketing', 'code' => 'DM', 'status' => 'active']);
});

it('says a typed department code is taken, and by which department, before anything is saved', function () {
    $page = Livewire::test(CreateDepartment::class)->set('data.code', 'DM')->assertHasErrors(['data.code']);

    // Only the code field is re-rendered while typing, with the error under it.
    expect($page->effects['partials'] ?? [])->toHaveCount(1)
        ->and($page->errors()->first('data.code'))->toBe('DM is already used by Digital Marketing. Choose another code.')
        ->and(uniqueCodeFieldHtml($page))->toContain('DM is already used by Digital Marketing. Choose another code.')
        ->and(uniqueCodeFieldHtml($page))->not->toContain('Available');
    expect(Department::query()->count())->toBe(1);
});

it('says a free code is available, and clears the error once the code is changed', function () {
    $page = Livewire::test(CreateDepartment::class)->set('data.code', 'DM')->assertHasErrors(['data.code'])
        ->set('data.code', 'SEO')->assertHasNoErrors(['data.code']);

    expect(uniqueCodeFieldHtml($page))->toContain('Available')
        ->and(uniqueCodeFieldHtml($page))->not->toContain('already used');
    $page->call('$refresh')->assertSee('Available');
});

it('checks the format as it is typed too', function () {
    $page = Livewire::test(CreateDepartment::class)->set('data.code', 'D M')->assertHasErrors(['data.code']);

    expect(uniqueCodeFieldHtml($page))->not->toContain('Available');
});

it('refuses to create a department with a code already used, without a server error', function () {
    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Data Management', 'code' => 'DM', 'status' => 'active'])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(Department::query()->where('code', 'DM')->count())->toBe(1);
});

it('lets a department keep its own code on edit but not take another one', function () {
    $seo = Department::query()->create(['company_id' => $this->company->id, 'name' => 'Search', 'code' => 'SEO', 'status' => 'active']);

    $page = Livewire::test(EditDepartment::class, ['record' => $seo->id])->set('data.code', 'SEO')->assertHasNoErrors(['data.code']);
    expect(uniqueCodeFieldHtml($page))->not->toContain('Available');

    $page->set('data.code', 'DM')->assertHasErrors(['data.code']);
    expect($page->errors()->first('data.code'))->toBe('DM is already used by Digital Marketing. Choose another code.');
});

it('does not count codes of another organisation', function () {
    $other = provisionTenant('Globex');
    actAsTenant($other);
    Department::query()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Operations', 'code' => 'OPS', 'status' => 'active']);
    actAsTenant($this->tenant);

    Livewire::test(CreateDepartment::class)
        ->set('data.code', 'OPS')
        ->assertHasNoErrors(['data.code']);
});

it('refuses a code held outside the viewer\'s access scope without naming that department', function () {
    $seo = Department::query()->create(['company_id' => $this->company->id, 'name' => 'Search', 'code' => 'SEO', 'status' => 'active']);
    app(AccessScopes::class)->assign($this->user, ['department' => [$seo->id]]);
    $this->actingAs($this->user->fresh());

    $page = Livewire::test(CreateDepartment::class)->set('data.code', 'DM')->assertHasErrors(['data.code']);

    expect($page->errors()->first('data.code'))->toBe('DM is already used by another department. Choose another code.');
});

it('checks codes typed in a create drawer too', function () {
    $taken = AssetCategory::query()->first() ?? AssetCategory::query()->create(['name' => 'Laptops', 'code' => 'LAPTOP', 'status' => 'active']);

    $page = Livewire::test(ManageAssetCategories::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.code', $taken->code)
        ->assertHasErrors(['mountedActions.0.data.code']);
    expect($page->errors()->first('mountedActions.0.data.code'))->toBe($taken->code.' is already used by '.$taken->name.'. Choose another code.');

    $page->set('mountedActions.0.data.code', 'NOT-USED-YET')->assertHasNoErrors(['mountedActions.0.data.code']);
});

it('checks codes on pages that opt in: the policy wizard, new and when editing', function () {
    $policy = Policy::query()->create(['type' => 'attendance', 'name' => 'General attendance', 'code' => 'ATT_GENERAL', 'status' => 'active']);

    $new = Livewire::withQueryParams(['type' => 'attendance'])->test(PolicyWizard::class)->set('data.code', 'ATT_GENERAL')->assertHasErrors(['data.code']);
    expect($new->errors()->first('data.code'))->toBe('ATT_GENERAL is already used by General attendance. Choose another code.');

    Livewire::withQueryParams(['policy' => $policy->id])->test(PolicyWizard::class)->set('data.code', 'ATT_GENERAL')->assertHasNoErrors(['data.code']);
});

it('checks the code of a unit added in the organisation designer against that unit type', function () {
    $page = Livewire::test(OrganisationDesigner::class)
        ->mountAction('addRoot')
        ->set('mountedActions.0.data.type', 'company')
        ->set('mountedActions.0.data.mode', 'new')
        ->set('mountedActions.0.data.code', $this->company->code)
        ->assertHasErrors(['mountedActions.0.data.code']);

    expect($page->errors()->first('mountedActions.0.data.code'))->toBe($this->company->code.' is already used by '.$this->company->name.'. Choose another code.');
});

it('turns a duplicate the database refuses into a message on the form instead of a server error', function () {
    $page = new class extends Component
    {
        public array $data = ['name' => 'Again', 'code' => 'DM'];

        public function save(): void
        {
            Department::query()->create([...$this->data, 'status' => 'active']);
        }

        public function render(): string
        {
            return '<div>{{ $errors->first("data.code") }}</div>';
        }
    };

    Livewire::test($page::class)
        ->call('save')
        ->assertHasErrors(['data.code'])
        ->assertSee('Another department already has the code DM. Change the code and try again.')
        ->assertNotified('Already in use');

    expect(Department::query()->where('code', 'DM')->count())->toBe(1);
});

it('reads the clashing columns from the database\'s own wording', function () {
    $sql = 'insert into `departments` (`company_id`, `name`, `code`, `tenant_id`) values (?, ?, ?, ?)';
    $bindings = [3, 'Digital Marketing', 'DM', 2];
    $refusal = fn (string $message) => new UniqueConstraintViolationException(config('database.default'), $sql, $bindings, new PDOException($message));

    foreach ([
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '2-DM' for key 'departments.departments_tenant_id_code_unique'",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '2-DM' for key 'departments_tenant_id_code_unique'",
        'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: departments.tenant_id, departments.code',
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"departments_tenant_id_code_unique\"\nDETAIL:  Key (tenant_id, code)=(2, DM) already exists.",
    ] as $message) {
        $entry = DuplicateEntry::from($refusal($message));

        expect($entry)->not->toBeNull()
            ->and($entry->table)->toBe('departments')
            ->and($entry->fields())->toBe(['code'])
            ->and($entry->values)->toBe(['tenant_id' => 2, 'code' => 'DM'])
            ->and($entry->noun())->toBe('department');
    }

    $update = new UniqueConstraintViolationException(config('database.default'), 'update `departments` set `code` = ?, `updated_at` = ? where `id` = ?', ['DM', '2026-11-19', 7],
        new PDOException('UNIQUE constraint failed: departments.tenant_id, departments.code'));
    expect(DuplicateEntry::from($update)?->values)->toBe(['code' => 'DM']);
});

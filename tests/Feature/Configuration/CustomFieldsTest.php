<?php

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Department;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->employee = Employee::factory()->create();

    $this->size = CustomField::create(['entity' => 'employee', 'key' => 'shirt_size', 'label' => 'Shirt size', 'type' => 'dropdown', 'options' => [['value' => 'S', 'label' => 'Small'], ['value' => 'M', 'label' => 'Medium'], ['value' => 'L', 'label' => 'Large']], 'is_required' => true]);
    CustomField::create(['entity' => 'employee', 'key' => 'laptop_count', 'label' => 'Laptops', 'type' => 'number']);
    CustomField::create(['entity' => 'employee', 'key' => 'joined_slack', 'label' => 'Joined Slack', 'type' => 'boolean']);
    CustomField::create(['entity' => 'employee', 'key' => 'languages', 'label' => 'Languages', 'type' => 'multiselect', 'options' => ['en', 'hi', 'ta']]);
    CustomField::create(['entity' => 'department', 'key' => 'floor', 'label' => 'Floor', 'type' => 'text']);
});

it('stores typed values per entity and reads them back', function () {
    $this->employee->setCustomFields(['shirt_size' => 'M', 'laptop_count' => '2', 'joined_slack' => true, 'languages' => ['en', 'ta']], 'Onboarding');

    $values = $this->employee->fresh()->customFields();

    expect($values)->toBe(['shirt_size' => 'M', 'laptop_count' => 2.0, 'joined_slack' => true, 'languages' => ['en', 'ta']])
        ->and($this->employee->customField('shirt_size'))->toBe('M')
        ->and($this->employee->customFieldValues()->first()->auditEvents()->value('reason'))->toBe('Onboarding');

    $department = Department::factory()->create();
    $department->setCustomFields(['floor' => '3rd']);
    expect($department->customField('floor'))->toBe('3rd')
        ->and(app(CustomFields::class)->definitionsFor(Department::class)->pluck('key')->all())->toBe(['floor']);
});

it('validates required fields and dropdown options', function () {
    expect(fn () => $this->employee->setCustomFields(['shirt_size' => null]))->toThrow(ValidationException::class);
    expect(fn () => $this->employee->setCustomFields(['shirt_size' => 'XXL']))->toThrow(ValidationException::class);
    expect(fn () => $this->employee->setCustomFields(['shirt_size' => 'S', 'laptop_count' => 'two']))->toThrow(ValidationException::class);
});

it('ignores retired or not-yet-effective definitions and unknown keys', function () {
    $this->size->update(['effective_from' => now()->addMonth()->toDateString()]);

    $this->employee->setCustomFields(['laptop_count' => 1, 'unknown' => 'x']);

    expect(app(CustomFields::class)->definitionsFor(Employee::class)->pluck('key')->all())->not->toContain('shirt_size')
        ->and($this->employee->customFields())->toBe(['laptop_count' => 1.0]);
});

it('keeps definitions and values per tenant', function () {
    $this->employee->setCustomFields(['shirt_size' => 'L']);

    actAsTenant(provisionTenant('Other'));

    expect(CustomField::query()->count())->toBe(0)
        ->and(app(CustomFields::class)->definitionsFor(Employee::class))->toBeEmpty();
});

it('renders custom fields on the employee edit form and saves them', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->employee->getRouteKey()])
        ->assertSee('Shirt size')
        ->fillForm(['custom_fields.shirt_size' => 'L', 'custom_fields.laptop_count' => 3, 'audit_reason' => 'Corrected'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->employee->fresh()->customFields()['shirt_size'])->toBe('L')
        ->and($this->employee->fresh()->customFields()['laptop_count'])->toBe(3.0);

    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))
        ->assertOk()->assertSee('Additional information')->assertSee('Large');
});

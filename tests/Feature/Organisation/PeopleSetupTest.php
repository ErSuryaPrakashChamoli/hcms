<?php

use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\WorkMode;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

it('seeds the starting people-setup records for a new tenant', function () {
    expect(EmploymentType::query()->pluck('code')->all())->toEqualCanonicalizing(array_column(config('peopleos.organisation.defaults.employment_types'), 'code'))
        ->and(EmployeeCategory::query()->count())->toBe(count(config('peopleos.organisation.defaults.employee_categories')))
        ->and(WorkMode::query()->count())->toBe(count(config('peopleos.organisation.defaults.work_modes')))
        ->and(Level::query()->orderBy('rank')->pluck('code')->first())->toBe('L1');
});

it('does not leak defaults across tenants and keeps codes unique per tenant', function () {
    $other = provisionTenant('Other');

    actAsTenant($other);
    expect(Level::query()->where('code', 'L1')->count())->toBe(1);

    actAsTenant($this->tenant);
    expect(fn () => Level::factory()->create(['code' => 'L1']))->toThrow(QueryException::class);
});

it('links designations to level, grade, job family and employment types', function () {
    $level = Level::query()->where('code', 'L3')->first();
    $grade = Grade::factory()->create(['level_id' => $level->id, 'code' => 'G3']);
    $designation = Designation::factory()->create(['name' => 'Tech Lead', 'code' => 'TL', 'level_id' => $level->id, 'grade_id' => $grade->id, 'default_reporting_level_id' => Level::query()->where('code', 'L4')->value('id')]);
    $designation->employmentTypes()->sync(EmploymentType::query()->whereIn('code', ['FULL_TIME', 'CONTRACT'])->pluck('id'));

    $designation->refresh();

    expect($designation->level->code)->toBe('L3')
        ->and($designation->grade->level->code)->toBe('L3')
        ->and($designation->defaultReportingLevel->code)->toBe('L4')
        ->and($designation->employmentTypes->pluck('code')->all())->toEqualCanonicalizing(['FULL_TIME', 'CONTRACT'])
        ->and($designation->auditEvents()->where('action', 'CREATE')->exists())->toBeTrue();
});

it('effective-dates designations', function () {
    Designation::factory()->create(['name' => 'Old Title', 'effective_from' => '2020-01-01', 'effective_to' => '2024-12-31']);
    Designation::factory()->create(['name' => 'New Title', 'effective_from' => '2025-01-01']);

    expect(Designation::query()->effectiveOn('2026-09-26')->pluck('name')->all())->toBe(['New Title'])
        ->and(Designation::query()->effectiveOn('2022-06-01')->pluck('name')->all())->toBe(['Old Title']);
});

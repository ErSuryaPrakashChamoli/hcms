<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

/**
 * Phase 13: structured audience criteria (resolved in SQL by AudienceQuery, inside the user's scope).
 * Option lists come through the organisation models' own access scope; the service validates again.
 */
final class AudienceCriteriaSchema
{
    public const LABELS = [
        'company_ids' => 'Companies', 'location_ids' => 'Locations', 'business_unit_ids' => 'Business units', 'department_ids' => 'Departments', 'team_ids' => 'Teams',
        'designation_ids' => 'Designations', 'grade_ids' => 'Grades', 'employment_type_ids' => 'Employment types', 'establishment_ids' => 'Establishments',
        'lifecycle_states' => 'Lifecycle states', 'manager_employee_ids' => 'Reports of', 'employee_ids' => 'Named employees',
    ];

    public static function section(string $statePath = 'audience_criteria', string $heading = 'Audience criteria'): Section
    {
        return Section::make($heading)->description('Every dimension you fill must match (values within one dimension are alternatives). Evaluated on effective-dated records, only inside your scope.')
            ->statePath($statePath)->columns(3)->collapsible()->schema([
                Select::make('company_ids')->label('Companies')->multiple()->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('location_ids')->label('Locations')->multiple()->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('business_unit_ids')->label('Business units')->multiple()->options(fn () => BusinessUnit::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('department_ids')->label('Departments')->multiple()->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('team_ids')->label('Teams')->multiple()->options(fn () => Team::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('designation_ids')->label('Designations')->multiple()->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('grade_ids')->label('Grades')->multiple()->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('employment_type_ids')->label('Employment types')->multiple()->options(fn () => EmploymentType::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('establishment_ids')->label('Establishments')->multiple()->options(fn () => Establishment::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('lifecycle_states')->label('Lifecycle states (empty = employed)')->multiple()
                    ->options(collect(LifecycleState::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst(str_replace('_', ' ', $c->value))])->all()),
                Select::make('manager_employee_ids')->label('Reports of (managers)')->multiple()->searchable()->options(fn () => self::employees()),
                Select::make('employee_ids')->label('Named employees')->multiple()->searchable()->options(fn () => self::employees()),
            ]);
    }

    /** @return array<string, list<int|string>> */
    public static function clean(?array $state): array
    {
        return collect($state ?? [])->filter(fn ($v) => is_array($v) && $v !== [])->all();
    }

    public static function describe(?array $criteria): string
    {
        $criteria = self::clean($criteria);

        return $criteria === [] ? 'Everyone employed in the preparer\'s scope' : collect($criteria)->map(fn ($values, $key) => (self::LABELS[$key] ?? $key).': '.count($values))->implode(' · ');
    }

    /** @return array<int, string> */
    private static function employees(): array
    {
        return Employee::query()->with('person')->employed()->orderBy('employee_code')->limit(500)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }
}

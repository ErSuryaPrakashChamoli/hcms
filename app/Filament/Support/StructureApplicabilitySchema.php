<?php

namespace App\Filament\Support;

use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\Company;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a salary structure version applies to: a company (or every company) and, if the administrator
 * chooses, particular grades, designations, levels, departments, divisions or any other position
 * dimension. CompensationStructures validates the ids again; CompensationChanges enforces the match.
 */
final class StructureApplicabilitySchema
{
    /** Icons for the dimension pills, by position column. */
    private const ICONS = [
        'location_id' => 'heroicon-m-map-pin',
        'business_unit_id' => 'heroicon-m-building-office-2',
        'division_id' => 'heroicon-m-squares-2x2',
        'department_id' => 'heroicon-m-building-office',
        'team_id' => 'heroicon-m-user-group',
        'designation_id' => 'heroicon-m-identification',
        'level_id' => 'heroicon-m-bars-arrow-up',
        'grade_id' => 'heroicon-m-star',
        'employment_type_id' => 'heroicon-m-briefcase',
        'employee_category_id' => 'heroicon-m-tag',
        'work_mode_id' => 'heroicon-m-computer-desktop',
        'cost_centre_id' => 'heroicon-m-banknotes',
        'dimension_value_id' => 'heroicon-m-adjustments-horizontal',
    ];

    /** The fields as one titled section, so a wizard shows the dimension pills and their pickers on one step. */
    public static function section(): Section
    {
        return Section::make('Who it applies to')
            ->description('Everyone in the chosen company, or only the people in the groups you pick.')
            ->columns(2)
            ->schema(self::fields());
    }

    /** @return list<Component> */
    public static function fields(): array
    {
        return [
            Select::make('company_id')->label('Company')->placeholder('All companies')
                ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
            ToggleButtons::make('narrow_by')->label('Only for some people? (optional)')->multiple()->inline()->live()->columnSpanFull()
                ->options(SalaryStructureVersion::dimensions())
                ->icons(array_intersect_key(self::ICONS, SalaryStructureVersion::dimensions()))
                ->helperText('Leave these off for everyone in the company. Pick one, e.g. Grade, then choose which grades.'),
            ...collect(SalaryStructureVersion::dimensions())->map(fn (string $label, string $column) => Select::make(self::path($column))->label(str($label)->plural()->toString())->placeholder('Choose '.str($label)->plural()->lower()->toString())
                ->multiple()->searchable()->options(fn () => self::catalogue($column)->newQuery()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => in_array($column, (array) $get('narrow_by'), true))->required())->values()->all(),
        ];
    }

    /** Form state for a version. @return array<string, mixed> */
    public static function fill(SalaryStructureVersion $version): array
    {
        $restrictions = $version->restrictions();

        return ['company_id' => $version->company_id, 'narrow_by' => array_keys($restrictions), 'grade_ids' => $restrictions['grade_id'] ?? [], 'applicability' => array_diff_key($restrictions, ['grade_id' => true])];
    }

    /**
     * Form state → CompensationStructures data. Fields of unticked dimensions are hidden, so they are
     * not dehydrated; they are sent empty here so unticking a dimension clears it.
     *
     * @return array<string, mixed>
     */
    public static function toServiceData(array $data): array
    {
        $ticked = (array) ($data['narrow_by'] ?? []);
        $narrowing = (array) ($data['applicability'] ?? []);
        unset($data['narrow_by']);
        $data['grade_ids'] = in_array('grade_id', $ticked, true) ? (array) ($data['grade_ids'] ?? []) : [];
        $data['applicability'] = collect(SalaryStructureVersion::dimensions())->except('grade_id')
            ->map(fn (string $label, string $column) => in_array($column, $ticked, true) ? (array) ($narrowing[$column] ?? []) : [])->all();

        return $data;
    }

    /** "Demo Technologies · Grade: G1, G2 · Department: Sales" */
    public static function describe(SalaryStructureVersion $version): string
    {
        $labels = SalaryStructureVersion::dimensions();

        return collect($version->restrictions())
            ->map(fn (array $ids, string $column) => $labels[$column].': '.self::catalogue($column)->newQuery()->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', '))
            ->prepend($version->company?->name ?? 'Every company')->implode(' · ');
    }

    /** "Demo Technologies · Grade: G1, G2" for builder form state (company_id, narrow_by, grade_ids, applicability). */
    public static function describeState(array $state): string
    {
        $labels = SalaryStructureVersion::dimensions();
        $company = filled($state['company_id'] ?? null) ? Company::query()->whereKey($state['company_id'])->value('name') : null;
        $parts = [$company ?? 'All companies'];
        foreach ((array) ($state['narrow_by'] ?? []) as $column) {
            $ids = array_filter((array) ($column === 'grade_id' ? ($state['grade_ids'] ?? []) : ($state['applicability'][$column] ?? [])));
            if (isset($labels[$column]) && $ids !== []) {
                $parts[] = $labels[$column].': '.self::catalogue($column)->newQuery()->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ');
            }
        }

        return implode(' · ', $parts);
    }

    private static function path(string $column): string
    {
        return $column === 'grade_id' ? 'grade_ids' : "applicability.{$column}";
    }

    private static function catalogue(string $column): Model
    {
        return (new EmployeePosition)->{EmployeePosition::DIMENSIONS[$column][0]}()->getRelated();
    }
}

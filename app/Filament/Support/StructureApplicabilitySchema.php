<?php

namespace App\Filament\Support;

use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\Company;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a salary structure version applies to: a company (or every company) and, if the administrator
 * chooses, particular grades, designations, levels, departments, divisions or any other position
 * dimension. CompensationStructures validates the ids again; CompensationChanges enforces the match.
 */
final class StructureApplicabilitySchema
{
    /** @return list<Component> */
    public static function fields(): array
    {
        return [
            Select::make('company_id')->label('Company (empty = every company)')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
            CheckboxList::make('narrow_by')->label('Fix this version to particular …')->options(SalaryStructureVersion::dimensions())->columns(4)->live()
                ->helperText('Tick each dimension the structure is meant for, then pick the values. Every ticked dimension must match the employee\'s position; values within one are alternatives. Nothing ticked = everyone in the company.'),
            ...collect(SalaryStructureVersion::dimensions())->map(fn (string $label, string $column) => Select::make(self::path($column))->label(str($label)->plural()->toString())
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

    private static function path(string $column): string
    {
        return $column === 'grade_id' ? 'grade_ids' : "applicability.{$column}";
    }

    private static function catalogue(string $column): Model
    {
        return (new EmployeePosition)->{EmployeePosition::DIMENSIONS[$column][0]}()->getRelated();
    }
}

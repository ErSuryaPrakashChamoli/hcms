<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Employment\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Employment Information Return working sheet (Form XXVI of the Social Security (Central) Rules, 2026, which replaced
 * the quarterly ER-I and biennial ER-II): employment on the last day of the previous year and of the year reported,
 * by employee category and sex, with joiners and leavers. Vacancies, shortages and next year's estimated requirement
 * are completed on the form.
 */
final class EmploymentReturn extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $previousEnd = $period->start->subDay();
        $employees = $context->data->employees($context->company, $previousEnd, $period->end, $context->establishment);
        $on = fn (CarbonImmutable $date) => $employees->filter(fn (Employee $e) => $e->joining_date->toDateString() <= $date->toDateString() && ($e->exit_date === null || $e->exit_date->toDateString() >= $date->toDateString()));
        $category = fn (Employee $e, CarbonImmutable $date) => (string) ($context->data->positionOn($e, $date)?->employeeCategory?->name ?: 'Not categorised');

        $rows = [];
        foreach ([$previousEnd, $period->end] as $date) {
            foreach ($on($date)->groupBy(fn ($e) => $category($e, $date))->sortKeys() as $name => $group) {
                $rows[] = [
                    'date' => $this->date($date), 'category' => $name,
                    'men' => $group->filter(fn ($e) => $this->gender($e->person) === 'M')->count(),
                    'women' => $group->filter(fn ($e) => $this->gender($e->person) === 'F')->count(),
                    'others' => $group->filter(fn ($e) => ! in_array($this->gender($e->person), ['M', 'F'], true))->count(),
                    'total' => $group->count(),
                ];
            }
        }
        $joined = $employees->filter(fn ($e) => $period->contains($e->joining_date));
        $left = $employees->filter(fn ($e) => $e->exit_date !== null && $period->contains($e->exit_date));
        foreach (['Joined during the year' => $joined, 'Left during the year' => $left] as $label => $group) {
            $rows[] = ['date' => $label, 'category' => 'All', 'men' => $group->filter(fn ($e) => $this->gender($e->person) === 'M')->count(),
                'women' => $group->filter(fn ($e) => $this->gender($e->person) === 'F')->count(),
                'others' => $group->filter(fn ($e) => ! in_array($this->gender($e->person), ['M', 'F'], true))->count(), 'total' => $group->count()];
        }

        return [$this->sheet($context, ['date' => 'Employment on', 'category' => 'Category (skill)', 'men' => 'Men', 'women' => 'Women', 'others' => 'Transgender / not recorded', 'total' => 'Total'], $rows, [
            'message' => 'No employees at this establishment in the period.',
            'warnings' => $this->attributionGaps($employees),
            'notes' => ['Form XXVI counts contract and outsourced workers and excludes part-time workers and apprentices: adjust these figures for workers PeopleOS does not hold. Persons with disability are reported separately on the form.'],
        ])];
    }
}

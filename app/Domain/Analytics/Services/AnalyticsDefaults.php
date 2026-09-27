<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;

/** Starter shared reports and the default HR dashboard for every tenant. */
final class AnalyticsDefaults
{
    public function seed(): void
    {
        $reports = [
            ['name' => 'Headcount by department', 'dataset' => 'employees', 'definition' => ['fields' => ['department', 'headcount'], 'filters' => [['field' => 'lifecycle_state', 'operator' => 'in', 'value' => 'joined,probation,confirmed,active,on_leave,notice_period']], 'group_by' => 'department', 'aggregations' => [['fn' => 'count']], 'sort' => ['field' => 'count', 'dir' => 'desc'], 'visualization' => ['type' => 'bar']]],
            ['name' => 'Exits by month', 'dataset' => 'exits', 'definition' => ['fields' => ['month', 'count'], 'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'completed']], 'group_by' => 'month', 'aggregations' => [['fn' => 'count']], 'sort' => ['field' => 'month', 'dir' => 'asc'], 'visualization' => ['type' => 'line']]],
            ['name' => 'Payroll cost by period', 'dataset' => 'payroll', 'definition' => ['fields' => ['period', 'employer_cost', 'net_pay'], 'group_by' => 'period', 'aggregations' => [['fn' => 'sum', 'field' => 'employer_cost'], ['fn' => 'sum', 'field' => 'net_pay'], ['fn' => 'count']], 'sort' => ['field' => 'period', 'dir' => 'asc'], 'visualization' => ['type' => 'bar']]],
            ['name' => 'Absence by department (last 30 days)', 'dataset' => 'attendance', 'definition' => ['fields' => ['department', 'is_absent'], 'filters' => [['field' => 'date', 'operator' => 'last_days', 'value' => '30']], 'group_by' => 'department', 'aggregations' => [['fn' => 'sum', 'field' => 'is_absent'], ['fn' => 'count']], 'visualization' => ['type' => 'bar']]],
            ['name' => 'Mandatory learning completion', 'dataset' => 'learning', 'definition' => ['fields' => ['course', 'is_completed', 'is_overdue'], 'filters' => [['field' => 'is_mandatory', 'operator' => 'equals', 'value' => '1']], 'group_by' => 'course', 'aggregations' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'is_completed'], ['fn' => 'sum', 'field' => 'is_overdue']], 'visualization' => ['type' => 'table']]],
            ['name' => 'Rating distribution (latest cycle)', 'dataset' => 'performance', 'definition' => ['fields' => ['final_label'], 'filters' => [['field' => 'final_label', 'operator' => 'not_empty']], 'group_by' => 'final_label', 'aggregations' => [['fn' => 'count']], 'visualization' => ['type' => 'pie']]],
        ];

        $ids = [];
        foreach ($reports as $row) {
            $ids[$row['name']] = Report::query()->firstOrCreate(['name' => $row['name'], 'dataset' => $row['dataset']], $row + ['is_shared' => true, 'status' => 'active'])->id;
        }

        Dashboard::query()->firstOrCreate(['slug' => 'hr-overview'], [
            'name' => 'HR overview', 'description' => 'Headcount, movement, cost and attention items.', 'is_default' => true, 'status' => 'active', 'widgets' => [
                ['type' => 'kpi', 'title' => 'Headcount', 'metric' => 'headcount', 'size' => 1],
                ['type' => 'kpi', 'title' => 'Attrition (12 mo)', 'metric' => 'attrition_rate', 'size' => 1],
                ['type' => 'kpi', 'title' => 'Absenteeism (30 d)', 'metric' => 'absenteeism_rate', 'size' => 1],
                ['type' => 'kpi', 'title' => 'People cost', 'metric' => 'people_cost', 'size' => 1],
                ['type' => 'trend', 'title' => 'Headcount trend', 'metric' => 'headcount', 'size' => 2],
                ['type' => 'chart', 'title' => 'Headcount by department', 'report_id' => $ids['Headcount by department'], 'size' => 2],
                ['type' => 'alerts', 'title' => 'Needs attention', 'size' => 2],
                ['type' => 'table', 'title' => 'Mandatory learning completion', 'report_id' => $ids['Mandatory learning completion'], 'size' => 2],
            ],
        ]);
    }
}

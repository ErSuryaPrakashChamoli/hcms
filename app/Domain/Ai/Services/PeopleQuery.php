<?php

namespace App\Domain\Ai\Services;

use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Str;

/**
 * Natural-language people search (§58, §86): parses org unit names, lifecycle words and date phrases
 * into employees-dataset filters, then runs them through the report runner (permission-aware).
 */
final class PeopleQuery
{
    public function __construct(private readonly ReportRunner $runner) {}

    /** @return array{filters: array<int, array<string, string>>, rows: array<int, array<string, mixed>>, total: int} */
    public function search(User $user, string $question): array
    {
        $q = strtolower($question);
        $filters = [];

        foreach ([['location', Location::class], ['department', Department::class], ['designation', Designation::class]] as [$field, $model]) {
            foreach ($model::query()->get(['name']) as $unit) {
                if (Str::contains($q, strtolower($unit->name))) {
                    $filters[] = ['field' => $field, 'operator' => 'equals', 'value' => $unit->name, 'label' => ucfirst($field).' = '.$unit->name];
                    break;
                }
            }
        }

        foreach (['probation' => 'probation', 'notice' => 'notice_period', 'confirmed' => 'confirmed', 'active' => 'active', 'exited' => 'exited', 'alumni' => 'alumni'] as $word => $state) {
            if (preg_match('/\b(on|in|under|who are|who is)?\s*'.$word.'\b/', $q)) {
                $filters[] = ['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => $state, 'label' => 'State = '.$state];
                break;
            }
        }
        if (preg_match('/\b(male|female|women|men|non-binary)\b/', $q, $m)) {
            $gender = match ($m[1]) {
                'women', 'female' => 'female', 'men', 'male' => 'male', default => 'non_binary'
            };
            $filters[] = ['field' => 'gender', 'operator' => 'equals', 'value' => $gender, 'label' => 'Gender = '.$gender];
        }

        if (preg_match('/joined (this|last) (year|month)/', $q, $m) || preg_match('/(this|last) (year|month)/', $q, $m)) {
            if (str_contains($q, 'join')) {
                if ($m[1] === 'this') {
                    $filters[] = ['field' => 'joining_date', 'operator' => $m[2] === 'year' ? 'this_year' : 'this_month', 'value' => '', 'label' => 'Joined this '.$m[2]];
                } else {
                    $start = $m[2] === 'year' ? now()->subYear()->startOfYear() : now()->subMonthNoOverflow()->startOfMonth();
                    $end = $m[2] === 'year' ? now()->subYear()->endOfYear() : now()->subMonthNoOverflow()->endOfMonth();
                    $filters[] = ['field' => 'joining_date', 'operator' => 'between', 'value' => $start->toDateString().','.$end->toDateString(), 'label' => 'Joined last '.$m[2]];
                }
            }
        } elseif (preg_match('/joined (in the )?last (\d+) days/', $q, $m)) {
            $filters[] = ['field' => 'joining_date', 'operator' => 'last_days', 'value' => $m[2], 'label' => 'Joined in the last '.$m[2].' days'];
        } elseif (preg_match('/joined (in |since )?(\d{4})/', $q, $m)) {
            $filters[] = ['field' => 'joining_date', 'operator' => 'between', 'value' => "{$m[2]}-01-01,{$m[2]}-12-31", 'label' => 'Joined in '.$m[2]];
        }
        if (preg_match('/tenure (over|more than|above) (\d+)/', $q, $m)) {
            $filters[] = ['field' => 'tenure_months', 'operator' => 'gte', 'value' => (string) ((int) $m[2] * 12), 'label' => 'Tenure ≥ '.$m[2].' years'];
        }

        if ($filters === []) {
            return ['filters' => [], 'rows' => [], 'total' => 0];
        }

        $hasStateFilter = collect($filters)->contains('field', 'lifecycle_state');
        $definition = [
            'fields' => ['employee_code', 'name', 'designation', 'department', 'location', 'joining_date', 'lifecycle_state'],
            'filters' => array_merge(array_map(fn ($f) => ['field' => $f['field'], 'operator' => $f['operator'], 'value' => $f['value']], $filters), $hasStateFilter ? [] : [['field' => 'lifecycle_state', 'operator' => 'in', 'value' => 'joined,probation,confirmed,active,on_leave,notice_period']]),
            'sort' => ['field' => 'name', 'dir' => 'asc'],
        ];
        $result = $this->runner->execute('employees', $definition, $user, 200);

        return ['filters' => $filters, 'rows' => $result->rows, 'total' => $result->total];
    }
}

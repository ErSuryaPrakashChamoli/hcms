<?php

namespace App\Domain\People\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\People\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deterministic duplicate-person detection (contract §3, Phase 1 §54). Exact matches on personal
 * email, personal phone, work email or external reference are "definite"; same first + last name
 * with the same date of birth is "possible" and must be reviewed by a human. Never fuzzy-merges.
 */
final class PersonMatcher
{
    /**
     * @param  array<string, mixed>  $person  first_name, last_name, date_of_birth, personal_email, personal_phone
     * @param  array<string, mixed>  $employee  work_email, external_reference, employee_code
     * @return Collection<int, array{person_id: int, employee_id: ?int, employee_code: ?string, name: string, matched_on: list<string>, definite: bool}>
     */
    public function candidates(array $person, array $employee = []): Collection
    {
        $hits = [];
        $add = function (Person $p, string $on, bool $definite) use (&$hits): void {
            $key = $p->getKey();
            $hits[$key] ??= ['person_id' => $key, 'employee_id' => $p->employee?->getKey(), 'employee_code' => $p->employee?->employee_code, 'name' => $p->display_name, 'matched_on' => [], 'definite' => false];
            $hits[$key]['matched_on'][] = $on;
            $hits[$key]['definite'] = $hits[$key]['definite'] || $definite;
        };

        foreach (['personal_email' => 'personal email', 'personal_phone' => 'personal phone'] as $column => $label) {
            $value = trim((string) ($person[$column] ?? ''));
            if ($value !== '') {
                Person::query()->with('employee')->whereRaw('lower('.$column.') = ?', [mb_strtolower($value)])->get()->each(fn (Person $p) => $add($p, $label, true));
            }
        }

        foreach (['work_email' => 'work email', 'external_reference' => 'external reference', 'employee_code' => 'employee code'] as $column => $label) {
            $value = trim((string) ($employee[$column] ?? ''));
            if ($value !== '') {
                Employee::query()->with('person')->whereRaw('lower('.$column.') = ?', [mb_strtolower($value)])->get()->each(fn (Employee $e) => $add($e->person, $label, true));
            }
        }

        $first = trim((string) ($person['first_name'] ?? ''));
        $last = trim((string) ($person['last_name'] ?? ''));
        $dob = $person['date_of_birth'] ?? null;
        if ($first !== '' && $last !== '' && $dob) {
            Person::query()->with('employee')
                ->whereRaw('lower(first_name) = ?', [mb_strtolower($first)])
                ->whereRaw('lower(last_name) = ?', [mb_strtolower($last)])
                ->whereDate('date_of_birth', Carbon::parse($dob)->toDateString())
                ->get()->each(fn (Person $p) => $add($p, 'name and date of birth', false));
        }

        return collect(array_values($hits));
    }

    /** @param  array<string, mixed>  $person */
    public function definite(array $person, array $employee = []): Collection
    {
        return $this->candidates($person, $employee)->filter(fn (array $c) => $c['definite'])->values();
    }
}

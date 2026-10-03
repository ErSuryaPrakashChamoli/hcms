<?php

namespace App\Domain\People\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\People\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deterministic duplicate-person detection (contract §3, Phase 1 §54). Exact matches on personal
 * email, personal phone, work email or external reference are "definite"; same first + last name
 * with the same date of birth is "possible" and must be reviewed by a human. Never fuzzy-merges.
 *
 * Phase 14: matching runs across the whole tenant, not only the caller's organisation scope, so a
 * scoped HR user cannot create a second Person for someone outside their scope (ADR-0002). A match
 * outside the caller's scope is disclosed minimally: no name, code or ids, only that a match exists
 * and on what (`outside_scope: true`).
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
        return AccessScope::withoutScoping(fn () => $this->match($person, $employee))->map(fn (array $hit) => $this->disclose($hit))->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function match(array $person, array $employee): Collection
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

    /** A hit outside the caller's organisation scope keeps only what is needed to stop a duplicate. */
    private function disclose(array $hit): array
    {
        $user = auth()->user();
        $scopes = app(AccessScopes::class);
        if (! $user instanceof User || ! $scopes->isScoped($user) || ($hit['employee_id'] !== null ? $scopes->allowsEmployeeId($user, (int) $hit['employee_id']) : false)) {
            return $hit + ['outside_scope' => false];
        }

        return ['person_id' => null, 'employee_id' => null, 'employee_code' => null, 'name' => 'A person outside your organisation scope', 'matched_on' => $hit['matched_on'], 'definite' => $hit['definite'], 'outside_scope' => true];
    }

    /** @param  array<string, mixed>  $person */
    public function definite(array $person, array $employee = []): Collection
    {
        return $this->candidates($person, $employee)->filter(fn (array $c) => $c['definite'])->values();
    }
}

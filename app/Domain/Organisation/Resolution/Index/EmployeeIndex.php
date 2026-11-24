<?php

namespace App\Domain\Organisation\Resolution\Index;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Resolution\NameKey;
use Illuminate\Database\Query\Builder;

/**
 * Employees, fetched only for the values asked (an organisation may have tens of thousands): by employee code, by work
 * e-mail, or by full name ("first last", "first middle last", "preferred last"). The company is the one of the
 * position in force on the session's date. No suggestions: guessing between people is never safe.
 */
final class EmployeeIndex implements ReferenceIndex
{
    /** @var array<int, array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>}> */
    private array $rows = [];

    /** @var array<string, int|null> */
    private array $codes = [];

    /** @var array<string, list<int>> */
    private array $names = [];

    public function __construct(private readonly ?User $actor, private readonly string $on) {}

    public function byCode(string $key, string $typed): ?int
    {
        if (! array_key_exists($key, $this->codes)) {
            $column = str_contains($key, '@') ? 'employees.work_email' : 'employees.employee_code';
            $found = $this->fetch(fn (Builder $q) => $q->whereIn($column, self::variants($typed)));
            $this->codes[$key] = null;
            foreach ($found as $row) {
                $value = str_contains($key, '@') ? ($row['email'] ?? null) : $row['code'];
                if ($value !== null && NameKey::key($value) === $key) {
                    $this->codes[$key] = $row['id'];
                }
            }
        }

        return $this->codes[$key];
    }

    /**
     * Fetch many employee codes or work e-mails at once (a large file's managers), 200 to a query, so each later
     * byCode() is answered from memory. The same comparison as byCode(); values already looked up are skipped.
     *
     * @param  list<string>  $typed
     */
    public function prime(array $typed): void
    {
        $todo = [];
        foreach ($typed as $value) {
            $key = NameKey::key($value);
            if ($key !== '' && ! array_key_exists($key, $this->codes)) {
                $todo[str_contains($key, '@') ? 'email' : 'code'][$key] = $value;
            }
        }
        foreach ($todo as $kind => $values) {
            foreach (array_chunk($values, 200, true) as $chunk) {
                $variants = array_merge(...array_map(self::variants(...), array_values($chunk)));
                $found = $this->fetch(fn (Builder $q) => $q->whereIn($kind === 'email' ? 'employees.work_email' : 'employees.employee_code', $variants));
                foreach (array_keys($chunk) as $key) {
                    $this->codes[$key] = null;
                }
                foreach ($found as $row) {
                    $value = $row[$kind] ?? null;
                    if ($value !== null && array_key_exists($key = NameKey::key($value), $chunk)) {
                        $this->codes[$key] = $row['id'];
                    }
                }
            }
        }
    }

    public function byName(string $key, string $typed): array
    {
        if (! array_key_exists($key, $this->names)) {
            $words = preg_split('/\s+/u', trim($typed)) ?: [];
            if (count($words) < 2) {
                return $this->names[$key] = []; // one word is never enough to name a person
            }
            $first = self::variants($words[0]);
            $last = self::variants($words[count($words) - 1]);
            $found = $this->fetch(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->whereIn('people.first_name', $first)->orWhereIn('people.preferred_name', $first))
                ->whereIn('people.last_name', $last));
            $this->names[$key] = array_values(array_map(fn (array $r) => $r['id'], array_filter($found, fn (array $r) => in_array($key, $r['keys'], true))));
        }

        return $this->names[$key];
    }

    public function row(int $id): array
    {
        return $this->rows[$id];
    }

    public function find(int $id): ?array
    {
        if (! isset($this->rows[$id])) {
            $this->fetch(fn (Builder $q) => $q->where('employees.id', $id));
        }

        return $this->rows[$id] ?? null;
    }

    public function appliesTo(int $id, int $companyId): bool
    {
        return $this->rows[$id]['owner'] === $companyId;
    }

    public function suggestible(): iterable
    {
        return [];
    }

    /**
     * @param  callable(Builder): mixed  $where
     * @return list<array{id: int, name: string, code: ?string, owner: ?int, live: bool, hints: array<string, ?int>, email: ?string, keys: list<string>}>
     */
    private function fetch(callable $where): array
    {
        $day = substr($this->on, 0, 10);
        $company = EmployeePosition::query()->withoutGlobalScopes()->select('company_id')
            ->whereColumn('employee_positions.employee_id', 'employees.id')
            ->where('employee_positions.effective_from', '<=', $day.' 23:59:59')
            ->orderByDesc('employee_positions.effective_from')->orderByDesc('employee_positions.id')->limit(1);

        $query = Employee::query()->withoutGlobalScope(AccessScope::class)
            ->join('people', 'people.id', '=', 'employees.person_id')
            ->select(['employees.id', 'employees.employee_code', 'employees.work_email', 'employees.exit_date',
                'people.first_name', 'people.middle_name', 'people.last_name', 'people.preferred_name'])
            ->selectSub($company->toBase(), 'company_id')
            ->toBase();
        $where($query);
        // Enough to see every namesake of a common name in a large organisation (two are already "ambiguous").
        $raw = $query->limit(500)->get();

        $visible = ActorVisibility::ids(Employee::class, $this->actor, $raw->pluck('id')->map(fn ($v) => (int) $v)->all());
        $rows = [];
        foreach ($raw as $r) {
            $id = (int) $r->id;
            if ($visible !== null && ! isset($visible[$id])) {
                continue;
            }
            $first = trim((string) $r->first_name);
            $last = trim((string) $r->last_name);
            $name = trim($first.' '.$last);
            $keys = array_values(array_unique(array_filter([
                NameKey::key($name),
                NameKey::key(trim($first.' '.$r->middle_name.' '.$last)),
                $r->preferred_name ? NameKey::key(trim($r->preferred_name.' '.$last)) : '',
            ])));
            $row = [
                'id' => $id, 'name' => $name, 'code' => $r->employee_code, 'owner' => $r->company_id === null ? null : (int) $r->company_id,
                'live' => $r->exit_date === null || substr((string) $r->exit_date, 0, 10) >= $day, 'hints' => [],
            ];
            $this->rows[$id] = $row;
            $rows[] = $row + ['email' => $r->work_email, 'keys' => $keys];
        }

        return $rows;
    }

    /** @return list<string> the typed value in the common cases (MySQL compares without case anyway; SQLite does not) */
    private static function variants(string $typed): array
    {
        $typed = trim($typed);
        $lower = mb_strtolower($typed);

        return array_values(array_unique([$typed, $lower, mb_strtoupper($typed), mb_convert_case($lower, MB_CASE_TITLE)]));
    }
}

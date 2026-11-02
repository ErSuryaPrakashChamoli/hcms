<?php

namespace App\Domain\People\Imports;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangeNominationsAction;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonNomination;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Data import DI-2 (Workforce → Nominee details): one row per nominee of a benefit (PF, gratuity, insurance …), written
 * through ChangeNominationsAction, exactly as "Set nominees" on the Employee 360: the rows of one employee and benefit
 * are a set, checked together (each nominee a family member on the profile, once; shares above 0 that total 100%; a
 * minor nominee needs a guardian) and saved together, replacing that benefit's nominations. A set is new when the
 * benefit has no nominations on file, an update otherwise (all its rows share the decision), and skipped when the file
 * repeats what is on file. A nominee already on file keeps the address, remarks and guardian on file for blank cells.
 */
final class NominationImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'nominations';

    private const DETAILS = ['address', 'remarks', 'guardian_name', 'guardian_relation'];

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'benefit' => ['Benefit', true], 'nominee' => ['Nominee', true], 'relation' => ['Relation', false],
            'share' => ['Share %', true], 'address' => ['Nominee address', false], 'guardian_name' => ['Guardian name', false],
            'guardian_relation' => ['Guardian relation', false], 'remarks' => ['Remarks', false],
        ];
    }

    public function label(): string
    {
        return 'Nominee details upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonNomination::class;
    }

    protected function aliases(): array
    {
        return ['nomination_type' => 'benefit', 'nomination_for' => 'benefit', 'scheme' => 'benefit', 'nominee_name' => 'nominee', 'name' => 'nominee',
            'relationship' => 'relation', 'percentage' => 'share', 'share_percentage' => 'share', 'nominee_share' => 'share', 'share' => 'share',
            'address' => 'address', 'guardian' => 'guardian_name', 'guardian_s_name' => 'guardian_name', 'guardian_relationship' => 'guardian_relation', 'remark' => 'remarks'];
    }

    /** The rows of each set (employee code + benefit), read once for the file. */
    protected function prepare(EmployeeImport $import): array
    {
        $context = parent::prepare($import) + ['sets' => [], 'verdicts' => []];
        foreach ($import->rows()->orderBy('row_number')->cursor() as $row) {
            $r = $this->recordFor($row, $import->mapping ?? []) + $this->defaults($import);
            $context['sets'][$this->setKey($r)][(int) $row->row_number] = $r;
        }

        return $context;
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        $members = $employee === null ? collect() : $this->members($employee);
        [, , $problems] = $this->parseRow($r, $members);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $this->setKey($r);
        $verdict = $context['verdicts'][$key] ??= $this->verdict($employee, $members, $context['sets'][$key] ?? [(int) $row->row_number => $r], $import);
        if ($verdict['error'] !== null) {
            return ['action' => 'error', 'errors' => [$verdict['error']], 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => ['employee code', 'benefit'], 'benefit' => $verdict['benefit'], 'first_row' => $verdict['first_row']];
        if ($verdict['action'] === 'skip') {
            return ['action' => 'skip', 'match' => $match + ['reason' => 'These nominations are already on file.'], 'employee_id' => $employeeId];
        }
        if ((int) $row->row_number === $verdict['first_row'] && $verdict['note'] !== null) {
            $match['reason'] = $verdict['note'];
        }

        return ['action' => $verdict['action'], 'match' => $match, 'employee_id' => $employeeId];
    }

    /** The first row of a set saves the whole set; the set's other rows confirm their nominee was saved with it. */
    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        $benefit = (string) ($row->match['benefit'] ?? '');
        $first = (int) ($row->match['first_row'] ?? $row->row_number);
        $label = (string) (app(OptionLists::class)->label('nomination_benefits', $benefit) ?? $benefit);
        $members = $this->members($employee);

        if ((int) $row->row_number !== $first) {
            [, $nomination] = $this->parseRow($r, $members);
            $saved = PersonNomination::query()->where('person_id', $employee->person_id)->where('benefit', $benefit)
                ->where('family_member_id', $nomination['family_member_id'] ?? 0)->first();
            if ($saved === null || abs((float) $saved->share - (float) ($nomination['share'] ?? -1)) > 0.001) {
                throw new RuntimeException("Not saved: row {$first}, which saves the {$label} nominations of {$employee->employee_code}, did not go through.");
            }

            return "Saved with row {$first}: {$saved->familyMember?->name} {$this->percent((float) $saved->share)} for {$label}";
        }

        $rows = [];
        $mapping = $import->mapping ?? [];
        foreach ($import->rows()->where('employee_id', $employee->getKey())->whereIn('action', ['create', 'update'])->orderBy('row_number')->get() as $other) {
            if (($other->match['benefit'] ?? null) !== $benefit || (int) ($other->match['first_row'] ?? 0) !== $first) {
                continue;
            }
            [, $nomination, $problems] = $this->parseRow($this->recordFor($other, $mapping) + $this->defaults($import), $members);
            if ($problems !== []) {
                throw new ProfileChangeRefused("Row {$other->row_number}: ".implode(' ', $problems));
            }
            $rows[] = $nomination;
        }
        $saved = app(ChangeNominationsAction::class)->replace($employee, $benefit, $this->keepDetails($employee, $benefit, $rows), $user, $this->why($import), $this->origin($import));

        return "Saved {$saved->count()} nominee(s) for {$label} of {$employee->employee_code}";
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Benefit', 'Yes', 'A benefit from the list below (code or name).', 'pf'],
                ['Nominee', 'Yes', 'A family member on the employee\'s profile, by name (add family members first with the Family details upload).', 'Kiran Rao'],
                ['Relation', 'No', 'The family member\'s relation, when two have the same name.', 'spouse'],
                ['Share %', 'Yes', 'More than 0, at most 100, two decimals at most. The shares of one employee\'s benefit must total 100.', '60'],
                ['Nominee address', 'No', 'Up to 500 characters.', '12 MG Road, Pune'],
                ['Guardian name', 'No', 'Needed when the nominee is a minor (from the family member\'s date of birth).', ''],
                ['Guardian relation', 'No', 'The guardian\'s relation to the nominee; needed with a minor nominee.', ''],
                ['Remarks', 'No', 'Up to 255 characters.', ''],
            ],
            'codes' => app(OptionLists::class)->options('nomination_benefits'),
            'codes_title' => 'Benefits',
            'notes' => [
                'The rows of one employee and benefit are a set, saved together as "Set nominees" on the Employee 360 does: they replace that benefit\'s nominations, so a nominee on file but not in the file is removed (the check names them).',
                'A set whose shares do not total 100%, with a nominee twice, or with a minor nominee without a guardian is refused as a whole.',
                'A nominee already on file keeps the address, remarks and guardian on file when those cells are blank.',
                'Changes need employee.update within your organisation scope; they are audited and shown on the employee\'s timeline.',
            ],
        ];
    }

    protected function examples(): array
    {
        $employee = Employee::query()->orderBy('id')->first();
        $members = $employee === null ? collect() : PersonFamilyMember::query()->where('person_id', $employee->person_id)->orderBy('id')->limit(2)->get();
        $code = $employee?->employee_code ?? 'EMP0001';
        $first = $members->get(0)?->name ?? 'Kiran Rao';
        $second = $members->get(1)?->name ?? 'Lakshmi Rao';

        return [
            [$code, 'pf', $first, '', '60', '12 MG Road, Pune', '', '', ''],
            [$code, 'pf', $second, '', '40', '', '', '', ''],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /** The set a row belongs to: its employee code and benefit (code, or the cell as typed when it is not one). */
    private function setKey(array $r): string
    {
        $errors = [];
        $benefit = $this->optionCode('nomination_benefits', $r['benefit'] ?? null, 'Benefit', $errors) ?? mb_strtolower(trim((string) ($r['benefit'] ?? '')));

        return mb_strtolower(trim((string) ($r['employee_code'] ?? ''))).'|'.$benefit;
    }

    /**
     * One row: the benefit code, the nomination as ChangeNominationsAction takes it, and what is wrong.
     *
     * @param  Collection<int, PersonFamilyMember>  $members
     * @return array{0: ?string, 1: array<string, mixed>, 2: list<string>}
     */
    private function parseRow(array $r, Collection $members): array
    {
        $errors = [];
        $benefit = $this->optionCode('nomination_benefits', $r['benefit'] ?? null, 'Benefit', $errors);
        if (! isset($r['benefit'])) {
            $errors[] = 'Benefit is required.';
        }
        $name = trim((string) ($r['nominee'] ?? ''));
        $relation = isset($r['relation']) ? $this->optionCode('family_relations', $r['relation'], 'Relation', $errors) : null;
        $member = null;
        if ($name === '') {
            $errors[] = 'Nominee is required.';
        } else {
            $matches = $members->filter(fn (PersonFamilyMember $m) => mb_strtolower(trim((string) $m->name)) === mb_strtolower($name) && ($relation === null || $m->relation === $relation))->values();
            if ($matches->count() === 1) {
                $member = $matches->first();
            } else {
                $errors[] = $matches->isEmpty()
                    ? "No family member named '{$name}'".($relation ? " ({$relation})" : '').' is on the profile: add them first.'
                    : "More than one family member is named '{$name}': give the relation.";
            }
        }
        $share = rtrim(trim((string) ($r['share'] ?? '')), '% ');
        if ($share === '' || ! is_numeric($share) || (float) $share <= 0 || (float) $share > 100) {
            $errors[] = 'Share % must be a number above 0 and at most 100.';
        }
        $nomination = ['family_member_id' => $member?->id, 'share' => is_numeric($share) ? (float) $share : null];
        foreach (self::DETAILS as $field) {
            if (isset($r[$field])) {
                $nomination[$field] = trim($r[$field]);
            }
        }

        return [$benefit, $nomination, array_values(array_unique($errors))];
    }

    /**
     * The decision for a whole set: an error for every row, or create / update / skip, with a note naming nominees the set
     * removes.
     *
     * @param  Collection<int, PersonFamilyMember>  $members
     * @param  array<int, array<string, string>>  $set  row number => row
     * @return array{action: string, error: ?string, note: ?string, benefit: ?string, first_row: int}
     */
    private function verdict(Employee $employee, Collection $members, array $set, EmployeeImport $import): array
    {
        ksort($set);
        $first = (int) array_key_first($set);
        $out = ['action' => 'error', 'error' => null, 'note' => null, 'benefit' => null, 'first_row' => $first];
        $rows = [];
        $seen = [];
        foreach ($set as $number => $r) {
            [$benefit, $nomination, $problems] = $this->parseRow($r, $members);
            if ($problems !== []) {
                return ['error' => "The nominations of this benefit are saved together: fix row {$number} first."] + $out;
            }
            if (isset($seen[$nomination['family_member_id']])) {
                return ['error' => "{$r['nominee']} is on rows {$seen[$nomination['family_member_id']]} and {$number} for the same benefit."] + $out;
            }
            $seen[$nomination['family_member_id']] = $number;
            $out['benefit'] = $benefit;
            $rows[] = $nomination;
        }
        if (($refusal = $this->refusal($import, $employee, ChangeNominationsAction::PERMISSION)) !== null) {
            return ['error' => $refusal] + $out;
        }
        $rows = $this->keepDetails($employee, (string) $out['benefit'], $rows);
        try {
            $clean = app(ChangeNominationsAction::class)->check($employee, (string) $out['benefit'], $rows);
        } catch (ProfileChangeRefused $e) {
            return ['error' => $e->getMessage()] + $out;
        }

        $onFile = PersonNomination::query()->where('person_id', $employee->person_id)->where('benefit', $out['benefit'])->get()->keyBy('family_member_id');
        if ($onFile->isEmpty()) {
            return ['action' => 'create'] + $out;
        }
        $same = $onFile->count() === count($clean) && collect($clean)->every(function (array $row) use ($onFile) {
            $nomination = $onFile->get($row['family_member_id']);

            return $nomination !== null && abs((float) $nomination->share - (float) $row['share']) < 0.001
                && collect(self::DETAILS)->every(fn (string $f) => (string) ($nomination->getAttribute($f) ?? '') === (string) ($row[$f] ?? ''));
        });
        if ($same) {
            return ['action' => 'skip'] + $out;
        }
        $removed = $onFile->keys()->diff(array_column($clean, 'family_member_id'))
            ->map(fn ($id) => $members->firstWhere('id', (int) $id)?->name ?? 'a former family member')->values()->all();
        $note = $removed === [] ? null : 'Replaces the nominations on file: removes '.implode(', ', $removed).'.';

        return ['action' => 'update', 'note' => $note] + $out;
    }

    /**
     * A nominee already on file keeps the address, remarks and guardian on file for blank cells.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function keepDetails(Employee $employee, string $benefit, array $rows): array
    {
        $onFile = PersonNomination::query()->where('person_id', $employee->person_id)->where('benefit', $benefit)->get()->keyBy('family_member_id');

        return array_map(function (array $row) use ($onFile) {
            $nomination = $onFile->get($row['family_member_id']);
            foreach (self::DETAILS as $field) {
                if (! array_key_exists($field, $row) && $nomination !== null) {
                    $row[$field] = $nomination->getAttribute($field);
                }
            }

            return $row;
        }, $rows);
    }

    /** @return Collection<int, PersonFamilyMember> */
    private function members(Employee $employee): Collection
    {
        return PersonFamilyMember::query()->where('person_id', $employee->person_id)->orderBy('id')->get();
    }

    private function percent(float $share): string
    {
        return rtrim(rtrim(number_format($share, 2, '.', ''), '0'), '.').'%';
    }
}

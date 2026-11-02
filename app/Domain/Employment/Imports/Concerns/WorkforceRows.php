<?php

namespace App\Domain\Employment\Imports\Concerns;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use RuntimeException;

/**
 * Data import DI-2: what the Workforce row uploads read the same way — the uploader (rows are checked as the person who
 * will run them), the profile-change rights ProfileChangeGuard applies, yes / no cells, option-list codes or labels,
 * genders and the change origin the actions stamp on audit, timeline and events.
 */
trait WorkforceRows
{
    /** @var array<int, ?User> import id => uploader */
    private array $uploaders = [];

    /** The person who uploaded the file (validation checks rows as them; the run checks as the runner). */
    protected function uploader(EmployeeImport $import): ?User
    {
        return $this->uploaders[(int) $import->getKey()] ??= User::query()->find($import->uploaded_by);
    }

    /** Why the uploader may not make this change for this employee (the action's own guard), or null. */
    protected function refusal(EmployeeImport $import, Employee $employee, string $permission): ?string
    {
        $actor = $this->uploader($import);
        if ($actor === null) {
            return 'The uploader of this file no longer exists.';
        }
        try {
            app(ProfileChangeGuard::class)->authorize($actor, $employee, $permission);
        } catch (ProfileChangeRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** The employee a validated row names, as the person running the upload may reach them. */
    protected function employeeForRun(?int $id): Employee
    {
        return ($id === null ? null : Employee::query()->with('person')->find($id))
            ?? throw new RuntimeException('That employee is outside your organisation scope.');
    }

    /** Where the change came from: this upload (references only). */
    protected function origin(EmployeeImport $import): ChangeOrigin
    {
        return new ChangeOrigin('bulk_upload', $this->label()." #{$import->getKey()}");
    }

    /** The reason audited with each change. */
    protected function why(EmployeeImport $import): string
    {
        return $this->label()." #{$import->getKey()} ({$import->original_name})";
    }

    /** yes / no (also y / n, true / false, 1 / 0); blank gives null. @param list<string> $errors */
    protected function yesNo(?string $value, string $what, array &$errors): ?bool
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['yes', 'y', 'true', '1'], true)) {
            return true;
        }
        if (in_array($v, ['no', 'n', 'false', '0'], true)) {
            return false;
        }
        $errors[] = "{$what} must be yes or no.";

        return null;
    }

    /** An option of an organisation list by its code or label (case-insensitive); blank gives null. @param list<string> $errors */
    protected function optionCode(string $list, ?string $value, string $what, array &$errors): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $code = self::codeIn(app(OptionLists::class)->labels($list), $value);
        if ($code === null) {
            $errors[] = "{$what} '{$value}' is not in the list: ".implode(', ', array_keys(app(OptionLists::class)->options($list))).'.';
        }

        return $code;
    }

    /** A gender code (male, female, non_binary, undisclosed) by code or label; blank gives null. @param list<string> $errors */
    protected function genderCode(?string $value, array &$errors): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $genders = (array) config('peopleos.people.genders', []);
        $code = self::codeIn($genders, $value) ?? match (mb_strtolower(trim($value))) {
            'm' => array_key_exists('male', $genders) ? 'male' : null,
            'f' => array_key_exists('female', $genders) ? 'female' : null,
            default => null,
        };
        if ($code === null) {
            $errors[] = 'Gender must be one of: '.implode(', ', array_keys($genders)).'.';
        }

        return $code;
    }

    /** A strict date (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY); blank gives null. @param list<string> $errors */
    protected function dateCell(?string $value, string $what, array &$errors): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $date = $this->date(trim($value));
        if ($date === null) {
            $errors[] = "{$what} must be a date (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY).";
        }

        return $date;
    }

    /** @param array<string, string> $options code => label */
    private static function codeIn(array $options, string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        foreach ($options as $code => $label) {
            if (mb_strtolower((string) $code) === $v || mb_strtolower((string) $label) === $v) {
                return (string) $code;
            }
        }

        return null;
    }

    /** Whether a stored value equals the row's (dates by day, numbers by value, booleans as booleans). */
    protected static function sameValue(mixed $stored, mixed $value): bool
    {
        if ($stored instanceof \DateTimeInterface) {
            $stored = $stored->format('Y-m-d');
        }
        if ($stored instanceof \BackedEnum) {
            $stored = $stored->value;
        }
        if (is_bool($value)) {
            return (bool) $stored === $value;
        }
        if ($value === null) {
            return $stored === null || $stored === '';
        }
        if (is_numeric($value) && is_numeric($stored)) {
            return (float) $value === (float) $stored;
        }

        return (string) $stored === (string) $value;
    }
}

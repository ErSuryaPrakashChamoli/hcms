<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Employment\Actions\ChangeStatutoryApplicabilityAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Imports\PayrollImportCells;

/**
 * Data import DI-4 (Payroll → Employee statutory): PF / ESI / PT applicability, PT state, tax regime and the statutory
 * numbers (PAN, Aadhaar reference, UAN, PF number, ESI number), one employee per row, written only through
 * ChangeStatutoryApplicabilityAction and ChangeStatutoryIdentityAction like the employee's Statutory tab: needs
 * employee.sensitive.update in scope, numbers encrypted at rest and masked in audit, linked documents required first
 * (CA-4). Only the cells filled in change. No number is ever repeated in an error or result. LWF applicability is not an
 * employee setting in PeopleOS (it follows the establishment's statutory profile), so it is not taken here.
 */
final class EmployeeStatutoryImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'employee_statutory';

    private const APPLICABILITY = ['pf_applicable' => 'PF applicable', 'esi_applicable' => 'ESI applicable', 'pt_applicable' => 'PT applicable'];

    /** Upload field => statutory detail column, where they differ. */
    private const COLUMNS = ['esi_applicable' => 'esic_applicable', 'pt_state' => 'pt_state_code'];

    /** Upload field => [statutory detail column, label]. */
    private const NUMBERS = ['pan' => ['pan', 'PAN'], 'aadhaar_reference' => ['aadhaar_reference', 'Aadhaar reference'], 'uan' => ['uan', 'UAN'],
        'pf_number' => ['pf_number', 'PF number'], 'esi_number' => ['esic_number', 'ESI number']];

    /** CA-4 linked field of a number (a document type may be required with it). */
    private const LINKED = ['pan' => 'statutory.pan', 'aadhaar_reference' => 'statutory.aadhaar', 'uan' => 'statutory.uan'];

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'pf_applicable' => ['PF applicable (yes/no)', false], 'esi_applicable' => ['ESI applicable (yes/no)', false],
            'pt_applicable' => ['PT applicable (yes/no)', false], 'pt_state' => ['PT state', false], 'tax_regime' => ['Tax regime', false], 'pan' => ['PAN', false],
            'aadhaar_reference' => ['Aadhaar reference', false], 'uan' => ['UAN', false], 'pf_number' => ['PF number', false], 'esi_number' => ['ESI number', false],
        ];
    }

    public function label(): string
    {
        return 'Employee statutory upload';
    }

    protected function module(): string
    {
        return 'employment';
    }

    protected function entityType(): string
    {
        return EmployeeStatutoryDetail::class;
    }

    protected function aliases(): array
    {
        return ['pf' => 'pf_applicable', 'esi' => 'esi_applicable', 'esic_applicable' => 'esi_applicable', 'pt' => 'pt_applicable', 'pt_state_code' => 'pt_state',
            'regime' => 'tax_regime', 'pan_number' => 'pan', 'aadhaar' => 'aadhaar_reference', 'uan_number' => 'uan', 'esic_number' => 'esi_number', 'esi_ip_number' => 'esi_number'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        [$applicability, $identity, $valueErrors] = $this->values($r);
        $errors = [...$errors, ...$valueErrors];
        if ($applicability === [] && $identity === [] && $valueErrors === []) {
            $errors[] = 'Fill in at least one statutory value to change.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        if (($duplicate = $this->seenBefore((string) $employeeId, $context, $row->row_number, 'employee')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $uploader = $this->uploader($import);
        try {
            if ($uploader === null) {
                throw new ProfileChangeRefused('The uploader of this file no longer exists.');
            }
            app(ProfileChangeGuard::class)->authorize($uploader, $employee, 'employee.sensitive.update');
        } catch (ProfileChangeRefused $e) {
            return ['action' => 'error', 'errors' => [$e->getMessage()], 'employee_id' => $employeeId];
        }

        $detail = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->first();
        $changed = $this->changed($detail, $applicability, $identity);
        if ($changed === []) {
            return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'These statutory values are already on file.']];
        }
        foreach (self::LINKED as $field => $linked) {
            if (in_array($field, $changed, true)) {
                try {
                    app(ProfileChangeGuard::class)->requireLinkedDocument($employee, $linked);
                } catch (ProfileChangeRefused $e) {
                    return ['action' => 'error', 'errors' => [self::fields()[$field][0].': '.$e->getMessage()], 'employee_id' => $employeeId];
                }
            }
        }

        return ['action' => $detail === null ? 'create' : 'update', 'employee_id' => $employeeId,
            'match' => ['reason' => 'Changes '.implode(', ', array_map(fn (string $f) => self::fields()[$f][0], $changed)).'.']];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        [$applicability, $identity] = $this->values($r);
        $detail = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->first();
        $changed = $this->changed($detail, $applicability, $identity);
        $reason = $this->label()." #{$import->getKey()}";
        $identityChanges = array_intersect_key($identity, array_flip($changed));
        if ($identityChanges !== []) {
            app(ChangeStatutoryIdentityAction::class)->handle($employee, collect($identityChanges)->mapWithKeys(fn ($v, $f) => [self::NUMBERS[$f][0] => $v])->all(), $user, $reason);
        }
        $applicabilityChanges = array_intersect_key($applicability, array_flip($changed));
        if ($applicabilityChanges !== []) {
            app(ChangeStatutoryApplicabilityAction::class)->handle($employee, collect($applicabilityChanges)->mapWithKeys(fn ($v, $f) => [self::COLUMNS[$f] ?? $f => $v])->all(), $user, $reason);
        }

        return 'Changed: '.implode(', ', array_map(fn (string $f) => self::fields()[$f][0], $changed));
    }

    /**
     * The row's values: applicability (field => bool / string) and numbers (field => string), and what is wrong (never echoing a number).
     *
     * @return array{0: array<string, bool|string>, 1: array<string, string>, 2: list<string>}
     */
    private function values(array $r): array
    {
        $applicability = [];
        $identity = [];
        $errors = [];
        foreach (self::APPLICABILITY as $field => $label) {
            if (isset($r[$field])) {
                $value = $this->yesNo($r[$field]);
                if ($value === null) {
                    $errors[] = "{$label} must be yes or no.";
                } else {
                    $applicability[$field] = $value;
                }
            }
        }
        if (isset($r['pt_state'])) {
            $state = strtoupper(trim($r['pt_state']));
            if (preg_match('/^[A-Z0-9]{1,16}$/', $state)) {
                $applicability['pt_state'] = $state;
            } else {
                $errors[] = 'PT state must be a state code (letters and digits, up to 16).';
            }
        }
        if (isset($r['tax_regime'])) {
            $regime = mb_strtolower(trim($r['tax_regime']));
            if (in_array($regime, ['old', 'new'], true)) {
                $applicability['tax_regime'] = $regime;
            } else {
                $errors[] = 'Tax regime must be old or new.';
            }
        }
        $rules = ['pan' => ['/^[A-Z]{5}[0-9]{4}[A-Z]$/', 'PAN must look like ABCDE1234F.'], 'aadhaar_reference' => ['/^[0-9A-Za-z]{4,16}$/', 'Aadhaar reference must be 4 to 16 letters or digits.'],
            'uan' => ['/^[0-9]{12}$/', 'UAN must be 12 digits.'], 'pf_number' => ['/^[A-Za-z0-9\/ -]{1,32}$/', 'PF number must be up to 32 letters, digits, spaces, / or -.'],
            'esi_number' => ['/^[0-9A-Za-z -]{1,32}$/', 'ESI number must be up to 32 letters, digits, spaces or -.']];
        foreach ($rules as $field => [$pattern, $message]) {
            if (isset($r[$field])) {
                $value = $field === 'pan' ? strtoupper(trim($r[$field])) : trim($r[$field]);
                if (preg_match($pattern, $value)) {
                    $identity[$field] = $value;
                } else {
                    $errors[] = $message;
                }
            }
        }

        return [$applicability, $identity, $errors];
    }

    /** @return list<string> the upload fields whose value differs from the file (compared decrypted, never shown) */
    private function changed(?EmployeeStatutoryDetail $detail, array $applicability, array $identity): array
    {
        $changed = [];
        foreach ($applicability as $field => $value) {
            $column = self::COLUMNS[$field] ?? $field;
            $current = $detail?->getAttribute($column);
            if ($detail === null || (is_bool($value) ? (bool) $current !== $value || $current === null : (string) $current !== $value)) {
                $changed[] = $field;
            }
        }
        foreach ($identity as $field => $value) {
            if ($detail === null || (string) $detail->getAttribute(self::NUMBERS[$field][0]) !== $value) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['PF applicable (yes/no)', 'No', 'Whether provident fund applies to the employee; blank leaves it.', 'yes'],
                ['ESI applicable (yes/no)', 'No', 'Whether ESI applies; blank leaves it.', 'no'],
                ['PT applicable (yes/no)', 'No', 'Whether professional tax applies; blank leaves it.', 'yes'],
                ['PT state', 'No', 'State code PT is paid in when it differs from the establishment\'s (e.g. KA).', 'KA'],
                ['Tax regime', 'No', 'old or new: the regime on the employee\'s statutory details.', 'new'],
                ['PAN', 'No', 'ABCDE1234F.', 'ABCPE1234K'],
                ['Aadhaar reference', 'No', 'The Aadhaar reference kept on file (4 to 16 letters or digits).', ''],
                ['UAN', 'No', 'Universal account number, 12 digits.', '100200300400'],
                ['PF number', 'No', 'PF member id.', 'KN/BNG/0012345/000/0000123'],
                ['ESI number', 'No', 'ESI insurance (IP) number.', '3100123456'],
            ],
            'notes' => [
                'One row per employee; only the cells filled in change, the rest stay as they are. Values already on file are skipped.',
                'Needs employee.sensitive.update for employees in your scope. Numbers are encrypted at rest, masked in the audit trail and never shown in check results.',
                'A number your organisation requires a document with (Setup → Document rules) needs that document on the employee first.',
                'LWF applicability is not set per employee: it follows the establishment\'s statutory profile.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);

        return [
            [$first, 'yes', 'no', 'yes', 'KA', 'new', '', '', '100200300400', 'KN/BNG/0012345/000/0000123', ''],
            [$second, 'yes', 'yes', 'yes', '', 'old', '', '', '100200300401', 'KN/BNG/0012345/000/0000124', '3100123456'],
        ];
    }
}

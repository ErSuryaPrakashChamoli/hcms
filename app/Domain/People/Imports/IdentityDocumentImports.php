<?php

namespace App\Domain\People\Imports;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangeIdentityDocumentAction;
use App\Domain\People\Models\PersonIdentityDocument;
use App\Support\Geography\Countries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Csv\Reader;
use RuntimeException;
use Throwable;

/**
 * Data import (Workforce → Government IDs; owner, 2026-11-27: every detail on the Employee 360 Profile can be uploaded):
 * one row per government ID of an employee — passport, driving licence, voter ID, national IDs, permits, visas and each
 * country's own ID (PersonIdentityDocument::catalogue()) — written through ChangeIdentityDocumentAction (needs
 * employee.sensitive.update in scope; the number's format checked where it is fixed; a document "required with" the ID
 * first), as the Government IDs tab writes it. A row finds the ID of that kind on file and updates it (a blank number
 * keeps the one on file); otherwise it adds it. PAN and Aadhaar are statutory details (Employee statutory upload).
 *
 * Numbers never sit in clear in staging: like the bank details and health insurance uploads, rows are sealed (encrypted)
 * when the file is read, screens show the employee, the kind of ID and the last four characters only, and once the upload
 * is run or discarded the sealed copies are dropped and the file is deleted. No number is repeated in an error.
 */
final class IdentityDocumentImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'identity_documents';

    public const PERMISSION = ChangeIdentityDocumentAction::PERMISSION;

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'kind' => ['ID type', true], 'number' => ['Number', false], 'issued_on' => ['Issued on', false],
            'expires_on' => ['Valid until', false], 'issuing_country' => ['Issuing country', false], 'place_of_issue' => ['Place of issue', false],
        ];
    }

    public function label(): string
    {
        return 'Government IDs upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonIdentityDocument::class;
    }

    protected function aliases(): array
    {
        return ['id_type' => 'kind', 'document' => 'kind', 'document_type' => 'kind', 'id' => 'kind', 'type' => 'kind', 'id_number' => 'number',
            'document_number' => 'number', 'passport_number' => 'number', 'licence_number' => 'number', 'license_number' => 'number', 'issue_date' => 'issued_on',
            'date_of_issue' => 'issued_on', 'issued' => 'issued_on', 'expiry' => 'expires_on', 'expiry_date' => 'expires_on', 'valid_till' => 'expires_on',
            'valid_upto' => 'expires_on', 'date_of_expiry' => 'expires_on', 'country' => 'issuing_country', 'country_of_issue' => 'issuing_country',
            'place' => 'place_of_issue', 'issued_at' => 'place_of_issue'];
    }

    /** Read the file into sealed rows (the numbers are in them), never into the clear `data` column. */
    public function inspect(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['uploaded', 'inspected']);
        $max = (int) config('peopleos.people.import_max_rows', 5000);

        $csv = Reader::from(Storage::disk($import->disk)->readStream($import->path));
        $csv->setHeaderOffset(0);
        $headers = array_values(array_map(fn ($h) => trim((string) $h), $csv->getHeader()));
        if ($headers === [] || count(array_filter($headers)) !== count(array_unique(array_filter($headers)))) {
            throw new RuntimeException('The file needs a header row with unique column names.');
        }

        DB::transaction(function () use ($import, $csv, $headers, $max) {
            $import->rows()->delete();
            $count = 0;
            foreach ($csv->getRecords() as $record) {
                if (++$count > $max) {
                    throw new RuntimeException("The file has more than {$max} rows; split it.");
                }
                EmployeeImportRow::create(['employee_import_id' => $import->getKey(), 'row_number' => $count, 'data' => [], 'sealed_data' => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $record)]);
            }
            $import->update(['headers' => $headers, 'row_count' => $count, 'mapping' => $import->mapping ?: $this->suggestMapping($headers), 'status' => 'inspected']);
        });

        return $import->refresh();
    }

    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        try {
            return parent::run($import, $user);
        } finally {
            $this->forget($import);
        }
    }

    public function discard(EmployeeImport $import, ?string $reason = null): EmployeeImport
    {
        $import = parent::discard($import, $reason);
        $this->forget($import);

        return $import;
    }

    /** One line naming a row without its number: code, name, kind of ID and the last four characters. */
    public function summary(EmployeeImportRow $row, EmployeeImport $import): string
    {
        $label = $row->match['label'] ?? null;
        if ($label === null && $row->sealed_data !== null) {
            $label = $this->rowLabel($this->recordFor($row, $import->mapping ?? []), null);
        }

        return $label ?: "Row {$row->row_number}";
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        [$data, $problems] = $this->parse($r);
        $errors = [...$errors, ...$problems];
        $label = $this->rowLabel($r, $employee);
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId, 'match' => ['label' => $label]];
        }
        $key = $employeeId.'|'.$data['kind'];
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same employee's ".PersonIdentityDocument::kinds()[$data['kind']]." is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId, 'match' => ['label' => $label]];
        }
        $context['seen'][$key] = $row->row_number;

        $document = $this->onFile($employee, $data['kind']);
        if ($document === null && blank($data['number'] ?? null)) {
            return ['action' => 'error', 'errors' => ['Number is required to add an ID.'], 'employee_id' => $employeeId, 'match' => ['label' => $label]];
        }
        if (($refusal = $this->refusal($import, $employee, self::PERMISSION)) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId, 'match' => ['label' => $label]];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'label' => $label,
            'matched_on' => $document === null ? ['employee code'] : ['employee code', 'ID type']];
        $changed = $document === null ? array_keys($data)
            : array_keys(array_filter($data, fn ($value, $field) => $field !== 'kind' && ! self::sameValue($document->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH));
        if ($document !== null && $changed === []) {
            return ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId];
        }
        if ($document === null || in_array('number', $changed, true)) {
            try {
                app(ProfileChangeGuard::class)->requireLinkedDocument($employee, $data['kind']);
            } catch (ProfileChangeRefused $e) {
                return ['action' => 'error', 'errors' => [$e->getMessage()], 'employee_id' => $employeeId, 'match' => ['label' => $label]];
            }
        }

        return $document === null
            ? ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => $changed], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        [$data, $problems] = $this->parse($r);
        if ($problems !== []) {
            throw new ProfileChangeRefused(implode(' ', $problems));
        }
        $document = $this->onFile($employee, $data['kind']);
        $action = app(ChangeIdentityDocumentAction::class);
        $saved = $document === null
            ? $action->add($employee, $data, $user, $this->why($import), $this->origin($import))
            : $action->update($employee, $document, $data, $user, $this->why($import), $this->origin($import));

        return ($document === null ? 'Added ' : 'Updated ').$saved->auditLabel().' for '.$employee->employee_code;
    }

    public function format(): array
    {
        $kinds = PersonIdentityDocument::kinds();

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['ID type', 'Yes', 'The kind of ID by name or code: '.implode(', ', array_slice($kinds, 0, 12)).' and the rest of the list on the Government IDs tab. PAN and Aadhaar go in the Employee statutory upload.', 'Passport'],
                ['Number', 'To add', 'The ID number; checked where its format is fixed. Blank on an ID already on file keeps its number.', 'K1234567'],
                ['Issued on', 'No', 'YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY; not in the future.', '2019-04-10'],
                ['Valid until', 'No', 'The expiry date, after the issue date.', '2029-04-09'],
                ['Issuing country', 'No', 'By name or two-letter code. Blank: the country an ID of one country belongs to, else as on file.', 'India'],
                ['Place of issue', 'No', 'Where it was issued (up to 128 characters).', 'Bengaluru'],
            ],
            'notes' => [
                'A row finds the ID of that type on the employee\'s profile and updates it; any other row adds the ID. One row per employee and ID type.',
                'Needs the right to change sensitive employee data for the employees in your scope. Numbers are sealed while the upload waits, shown with their last four characters only and never repeated in a check result; the file is deleted once the upload is run or discarded.',
                'Blank cells keep what is on file. Nothing is removed by an upload: remove an ID on the Employee 360 (Government IDs). Copies of the IDs are uploaded with the Employee documents upload.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [
            [$code, 'Passport', 'K1234567', '2019-04-10', '2029-04-09', 'India', 'Bengaluru'],
            [$code, 'Voter ID (EPIC)', 'ABC1234567', '', '', '', ''],
        ];
    }

    /** @return array<string, string> field => non-empty value, read from the sealed copy */
    protected function recordFor(EmployeeImportRow $row, array $mapping): array
    {
        $r = [];
        foreach ($mapping as $header => $field) {
            $v = $row->sealed_data[$header] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                $r[$field] = (string) $v;
            }
        }

        return $r;
    }

    /**
     * The row as ChangeIdentityDocumentAction takes it (kind always; the rest only when given) and what is wrong — never
     * echoing the number.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $r): array
    {
        $errors = [];
        $kind = self::kindCode($r['kind'] ?? null);
        if ($kind === null) {
            $given = mb_strtolower(trim((string) ($r['kind'] ?? '')));
            $errors[] = match (true) {
                $given === '' => 'ID type is required.',
                in_array($given, ['pan', 'pan card', 'aadhaar', 'aadhar', 'aadhaar card', 'uan'], true) => 'PAN, Aadhaar and UAN are statutory details: load them with the Employee statutory upload.',
                default => "ID type '{$r['kind']}' is not in the list of government IDs.",
            };
        }
        $data = ['kind' => (string) $kind];
        if (isset($r['number'])) {
            $data['number'] = mb_strtoupper((string) preg_replace('/\s+/', '', $r['number']));
        }
        foreach (['issued_on' => 'Issued on', 'expires_on' => 'Valid until'] as $field => $what) {
            if (($date = $this->dateCell($r[$field] ?? null, $what, $errors)) !== null) {
                $data[$field] = $date;
            }
        }
        if (isset($r['issuing_country'])) {
            $country = Countries::codeOf($r['issuing_country']);
            if ($country === null) {
                $errors[] = "Issuing country '{$r['issuing_country']}' is not a country name or two-letter code.";
            } else {
                $data['issuing_country'] = $country;
            }
        }
        if (isset($r['place_of_issue'])) {
            $data['place_of_issue'] = trim($r['place_of_issue']);
        }
        if ($errors === []) {
            $rules = array_intersect_key(app(ChangeIdentityDocumentAction::class)->rules(false), $data);
            // A number's own value never appears in a message: the rule names the column only.
            $validator = Validator::make($data, $rules, ['number.regex' => 'Number may only contain letters, digits, spaces, dashes and slashes.'],
                ['issued_on' => 'issued on', 'expires_on' => 'valid until', 'issuing_country' => 'issuing country', 'place_of_issue' => 'place of issue']);
            array_push($errors, ...$validator->errors()->all());
            if (isset($data['issued_on'], $data['expires_on']) && $data['expires_on'] <= $data['issued_on']) {
                $errors[] = 'Valid until must be after the issue date.';
            }
            if (filled($data['number'] ?? null) && ($problem = PersonIdentityDocument::numberProblem($kind, $data['number'], $data['issuing_country'] ?? PersonIdentityDocument::countryOf($kind))) !== null) {
                $errors[] = $problem;
            }
        }

        return [$data, array_values(array_unique($errors))];
    }

    /** The ID of that kind on the employee's profile. */
    private function onFile(Employee $employee, string $kind): ?PersonIdentityDocument
    {
        return PersonIdentityDocument::query()->where('person_id', $employee->person_id)->where('kind', $kind)->first();
    }

    /** A kind of ID by its code or its name (not case-sensitive; "Driving license" for "Driving licence"). */
    private static function kindCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = mb_strtolower(trim($value));
        $plain = fn (string $s) => preg_replace('/[^a-z0-9]+/', '', str_replace('license', 'licence', mb_strtolower($s)));
        foreach (PersonIdentityDocument::kinds() as $code => $label) {
            if ($v === mb_strtolower((string) $code) || $plain($v) === $plain((string) $label) || $plain($v) === $plain(preg_replace('/\s*\(.*\)\s*/', '', (string) $label))) {
                return (string) $code;
            }
        }

        return null;
    }

    private function rowLabel(array $r, ?Employee $employee): string
    {
        $number = isset($r['number']) ? '••••'.mb_substr((string) preg_replace('/\s+/', '', (string) $r['number']), -4) : null;
        $kind = ($code = self::kindCode($r['kind'] ?? null)) !== null ? PersonIdentityDocument::kinds()[$code] : ($r['kind'] ?? null);

        return collect([$employee?->employee_code ?? ($r['employee_code'] ?? null), $employee?->person?->full_name, $kind, $number])->filter()->implode(' · ');
    }

    /** Drop the clear-text sources once the upload is finished with: the sealed rows and the file. */
    private function forget(EmployeeImport $import): void
    {
        $import->rows()->toBase()->update(['sealed_data' => null]);
        try {
            Storage::disk($import->disk)->delete($import->path);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

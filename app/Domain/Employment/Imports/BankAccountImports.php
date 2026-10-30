<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Services\BankAccountRules;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;
use RuntimeException;
use Throwable;

/**
 * Bank details upload: the staged import pipeline (upload → inspect → map → validate → approve → run) for
 * employees' salary accounts. Every row is written through ChangeBankAccountAction, so a file meets the rules,
 * organisation scope, duplicate check, audit and timeline of a change made on Employee 360.
 *
 * Account numbers never sit in clear in staging: rows are sealed (encrypted) when the file is read, screens show
 * the last four digits only, and once the upload is run or discarded the sealed copies are dropped and the file is
 * deleted. Only the uploader works on a bank upload, because its rows hold account numbers.
 */
final class BankAccountImports
{
    public const TYPE = 'bank_accounts';

    /** key => [label, required] */
    public const FIELDS = [
        'employee_code' => ['Employee code', true], 'account_holder_name' => ['Account holder name', true], 'bank_name' => ['Bank name', true],
        'account_number' => ['Account number', true], 'ifsc' => ['IFSC', false], 'branch_name' => ['Branch name', false], 'account_type' => ['Account type', false],
    ];

    /** What a file can be for: mode => [label, description]. */
    public const MODES = [
        'new' => ['Add bank details for new employees', 'For employees with no bank account on file. Employees who already have one are skipped.'],
        'update' => ['Update existing bank details', 'Replaces the details of the salary (primary) account on file. Employees with no account on file are skipped.'],
        'both' => ['Both', 'Adds the account where none is on file and updates the one on file otherwise.'],
    ];

    public function __construct(
        private readonly ChangeBankAccountAction $accounts,
        private readonly ProfileChangeGuard $guard,
        private readonly AuditRecorder $audit,
    ) {}

    /** Uploading bank details is bulk-importing (employee.import) sensitive data (what Employee 360 requires to change it). */
    public static function allowed(?User $user): bool
    {
        return $user !== null && $user->hasPermission('employee.import') && $user->hasPermission(ChangeBankAccountAction::PERMISSION);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    public function register(string $path, string $originalName, User $user, ?string $reason = null, ImportMode $mode = ImportMode::Both): EmployeeImport
    {
        if (! self::allowed($user)) {
            throw new RuntimeException('Uploading bank details needs employee.import and '.ChangeBankAccountAction::PERMISSION.'.');
        }
        $disk = EmployeeImports::disk();
        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The uploaded file could not be found on the private disk.');
        }

        $import = new EmployeeImport(['type' => self::TYPE, 'original_name' => $originalName, 'disk' => $disk, 'path' => $path, 'size_bytes' => (int) Storage::disk($disk)->size($path),
            'status' => 'uploaded', 'uploaded_by' => $user->getKey(), 'options' => ['mode' => $mode->value]]);
        $import->withAuditReason($reason)->save();

        return $import;
    }

    /** Downloadable starting file: every field label as a header, so an upload maps automatically, and one example row. */
    public function template(): string
    {
        $example = ['employee_code' => Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001', 'account_holder_name' => 'Asha Rao', 'bank_name' => 'HDFC Bank',
            'account_number' => '00011122233344', 'ifsc' => 'HDFC0001234', 'branch_name' => 'Andheri East', 'account_type' => 'salary'];

        $csv = Writer::fromString();
        $csv->insertOne(array_column(self::FIELDS, 0));
        $csv->insertOne(array_map(fn ($field) => $example[$field], array_keys(self::FIELDS)));

        return $csv->toString();
    }

    /**
     * The format guide the upload screen shows.
     *
     * @return array{columns: list<array{0: string, 1: string, 2: string, 3: string}>, notes: list<string>}
     */
    public function format(): array
    {
        $what = [
            'employee_code' => ['The employee\'s code.', 'EMP0001'],
            'account_holder_name' => ['Name on the account, as the bank has it.', 'Asha Rao'],
            'bank_name' => ['Name of the bank.', 'HDFC Bank'],
            'account_number' => ['Up to 34 letters or digits; spaces are removed.', '00011122233344'],
            'ifsc' => ['Indian Financial System Code: four letters, a zero, six letters or digits. Blank keeps the IFSC on file.', 'HDFC0001234'],
            'branch_name' => ['Blank keeps the branch on file.', 'Andheri East'],
            'account_type' => ['savings, current or salary. Blank keeps the type on file.', 'salary'],
        ];

        return [
            'columns' => array_map(fn (string $field) => [self::FIELDS[$field][0], self::FIELDS[$field][1] ? 'Yes' : 'No', ...$what[$field]], array_keys(self::FIELDS)),
            'notes' => [
                'Excel turns long account numbers into 5.01E+13 and drops leading zeros. Format the Account number column as Text before you type or paste, and check it before saving.',
                'An added account becomes the employee\'s salary (primary) account. Updating changes the account with the same number if there is one, otherwise the salary account.',
                'Each change is checked and recorded as on Employee 360: your organisation scope, a duplicate account, the audit trail and the employee\'s timeline.',
                'Account numbers are stored encrypted while the file is staged; the file and the staged copies are deleted once the upload is run or discarded.',
            ],
        ];
    }

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

    /** @return array<string, string> header => field */
    public function suggestMapping(array $headers): array
    {
        $aliases = ['code' => 'employee_code', 'emp_code' => 'employee_code', 'employee' => 'employee_code', 'holder' => 'account_holder_name', 'holder_name' => 'account_holder_name',
            'name_as_per_bank' => 'account_holder_name', 'bank' => 'bank_name', 'account' => 'account_number', 'account_no' => 'account_number', 'ac_no' => 'account_number',
            'bank_account_number' => 'account_number', 'ifsc_code' => 'ifsc', 'branch' => 'branch_name', 'type' => 'account_type'];
        foreach (self::FIELDS as $field => [$label]) {
            $aliases[Str::slug($label, '_')] = $field;
            $aliases[$field] = $field;
        }
        $mapping = [];
        foreach ($headers as $header) {
            $key = Str::slug($header, '_');
            if (isset($aliases[$key]) && ! in_array($aliases[$key], $mapping, true)) {
                $mapping[$header] = $aliases[$key];
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, string|null>  $mapping  header => field
     * @param  array{mode?: string|null}  $options
     */
    public function map(EmployeeImport $import, array $mapping, array $options = []): EmployeeImport
    {
        $this->assert($import, ['inspected', 'mapped', 'validated']);
        $mapping = array_filter($mapping, fn ($f) => $f !== null && $f !== '');
        foreach ($mapping as $header => $field) {
            if (! in_array($header, $import->headers ?? [], true) || ! isset(self::FIELDS[$field])) {
                throw new RuntimeException("Column [{$header}] or field [{$field}] is not valid.");
            }
        }
        if (count($mapping) !== count(array_unique($mapping))) {
            throw new RuntimeException('Each field can be mapped from one column only.');
        }
        $missing = collect(self::FIELDS)->filter(fn ($d) => $d[1])->keys()->diff(array_values($mapping));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required fields are not mapped: '.$missing->map(fn ($f) => self::FIELDS[$f][0])->implode(', ').'.');
        }
        $mode = ImportMode::tryFrom((string) ($options['mode'] ?? '')) ?? ImportMode::of($import);

        $import->update(['mapping' => $mapping, 'options' => array_merge($import->options ?? [], ['mode' => $mode->value]), 'status' => 'mapped',
            'valid_count' => 0, 'error_count' => 0, 'review_count' => 0, 'create_count' => 0, 'update_count' => 0, 'skip_count' => 0]);

        return $import->refresh();
    }

    /** Check every row as the uploader, who will run it; touches no bank account. */
    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['mapped', 'validated']);
        $actor = User::query()->find($import->uploaded_by) ?? throw new RuntimeException('The uploader of this file no longer exists.');
        $mode = ImportMode::of($import);
        $employees = $this->employees();
        $counts = ['valid' => 0, 'error' => 0, 'create' => 0, 'update' => 0, 'skip' => 0];
        $seen = ['employee' => [], 'account' => []];

        DB::transaction(function () use ($import, $actor, $mode, $employees, &$counts, &$seen) {
            foreach ($import->rows()->cursor() as $row) {
                [$employee, $data, $errors] = $this->resolve($row, $import, $employees);
                if ($employee !== null) {
                    if (isset($seen['employee'][$employee->id])) {
                        $errors[] = "Employee {$employee->employee_code} also appears in row {$seen['employee'][$employee->id]}.";
                    }
                    $seen['employee'][$employee->id] ??= $row->row_number;
                }
                // A number dragged down a spreadsheet column lands on several people: refuse it on every one but the first.
                if (isset($data['account_number'])) {
                    $first = $seen['account'][$data['account_number']] ?? null;
                    if ($first !== null && $first[1] !== $employee?->id) {
                        $errors[] = "The same account number is on row {$first[0]} for another employee.";
                    }
                    $seen['account'][$data['account_number']] ??= [$row->row_number, $employee?->id];
                }
                if ($errors === []) {
                    try {
                        $this->guard->authorize($actor, $employee, ChangeBankAccountAction::PERMISSION);
                    } catch (ProfileChangeRefused $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                $action = 'error';
                $reason = null;
                if ($errors === []) {
                    [$action, , $reason] = $this->decide($employee, $data, $mode);
                    if ($action === 'error') {
                        $errors[] = $reason;
                        $reason = null;
                    }
                }

                $row->update(['action' => $action, 'errors' => $errors ?: null, 'match' => $this->describe($employee, $data, $reason), 'employee_id' => $employee?->id, 'status' => 'pending', 'result' => null]);
                $counts[$action === 'error' ? 'error' : 'valid']++;
                if ($action !== 'error') {
                    $counts[$action]++;
                }
            }
            $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => 0,
                'create_count' => $counts['create'], 'update_count' => $counts['update'], 'skip_count' => $counts['skip']]);
        });

        return $import->refresh();
    }

    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        $this->assert($import, ['validated']);
        $this->assertApprover($import, $user);
        if ($import->create_count + $import->update_count === 0) {
            throw new RuntimeException('Nothing to import: no row would add or update bank details.');
        }
        $import->withAuditReason($reason)->update(['status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now()]);

        return $import->refresh();
    }

    /** Write the approved rows through ChangeBankAccountAction, deciding each again (facts may have moved since validation). */
    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        $this->assert($import, ['approved']);
        $this->assertUploader($import, $user);
        $import->update(['status' => 'importing']);
        $mode = ImportMode::of($import);
        $employees = $this->employees();
        $why = "Bank details upload #{$import->getKey()} ({$import->original_name})";
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'ids' => []];

        try {
            $operationId = $this->audit->operation('employment', $why, function (string $operationId) use ($import, $user, $mode, $employees, $why, &$result) {
                $origin = new ChangeOrigin('bulk_upload', "Bank details upload #{$import->getKey()}", $operationId);
                foreach ($import->rows()->whereIn('action', ['create', 'update', 'skip'])->cursor() as $row) {
                    if ($row->action === 'skip') {
                        $row->update(['status' => 'skipped', 'result' => 'Skipped: '.($row->match['reason'] ?? 'nothing to change')]);
                        $result['skipped']++;

                        continue;
                    }
                    [$employee, $data, $errors] = $this->resolve($row, $import, $employees);
                    try {
                        if ($errors !== []) {
                            throw new ProfileChangeRefused(implode(' ', $errors));
                        }
                        [$action, $target, $reason] = $this->decide($employee, $data, $mode);
                        if ($action === 'skip') {
                            $row->update(['status' => 'skipped', 'result' => "Skipped: {$reason}"]);
                            $result['skipped']++;

                            continue;
                        }
                        if ($action === 'error') {
                            throw new ProfileChangeRefused($reason);
                        }
                        $account = $action === 'create'
                            ? $this->accounts->add($employee, $data + ['is_primary' => true], $user, $why, $origin)
                            : $this->accounts->update($target, $data, $user, $why, $origin);
                        $row->update(['status' => 'imported', 'result' => ($action === 'create' ? 'Added ' : 'Updated ').$account->auditLabel()]);
                        $result[$action === 'create' ? 'created' : 'updated']++;
                        $result['ids'][] = $account->getKey();
                    } catch (Throwable $e) {
                        if (! $e instanceof ProfileChangeRefused) {
                            report($e);
                        }
                        $row->update(['status' => 'failed', 'result' => Str::limit($e->getMessage(), 500)]);
                        $result['failed']++;
                    }
                }

                return ['succeeded' => $result['created'] + $result['updated'] + $result['skipped'], 'failed' => $result['failed'], 'ids' => $result['ids']];
            }, reason: "Bank details upload {$import->original_name}", entityType: EmployeeBankAccount::class);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
            $this->forget($import);

            throw $e;
        }

        $this->forget($import);
        $import->update(['status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId,
            'create_count' => $result['created'], 'update_count' => $result['updated'], 'skip_count' => $result['skipped'], 'failure_count' => $result['failed']]);

        return $import->refresh();
    }

    public function discard(EmployeeImport $import, ?string $reason = null): EmployeeImport
    {
        $this->assert($import, ['uploaded', 'inspected', 'mapped', 'validated', 'approved', 'failed']);
        $import->withAuditReason($reason)->update(['status' => 'discarded']);
        $this->forget($import);

        return $import;
    }

    /** One line naming a row without its account number: code, name, bank and the last four digits. */
    public function summary(EmployeeImportRow $row, EmployeeImport $import): string
    {
        $label = $row->match['label'] ?? null;
        if ($label === null && $row->sealed_data !== null) {
            $label = $this->label(null, $this->recordFor($row, $import->mapping ?? []));
        }

        return $label ?: "Row {$row->row_number}";
    }

    // ---------------------------------------------------------------------------------------

    /**
     * What a valid row does for its employee: add an account (none on file), update one (on file), skip it (outside
     * the mode, or already on file as given) or refuse it. The account updated is the one with the row's number,
     * else the salary (primary) account, else the only account.
     *
     * @param  array<string, string>  $data
     * @return array{0: string, 1: ?EmployeeBankAccount, 2: ?string} action, the account to update and the reason for a skip or an error
     */
    private function decide(Employee $employee, array $data, ImportMode $mode): array
    {
        /** @var Collection<int, EmployeeBankAccount> $accounts */
        $accounts = EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->orderBy('id')->get();
        if ($accounts->isEmpty()) {
            return $mode->adds() ? ['create', null, null] : ['skip', null, "{$employee->employee_code} has no bank details on file; choose Add bank details for new employees or Both."];
        }
        if (! $mode->updates()) {
            return ['skip', null, "{$employee->employee_code} already has bank details on file; choose Update existing bank details or Both to change them."];
        }

        $target = $accounts->first(fn (EmployeeBankAccount $a) => (string) $a->account_number === $data['account_number'])
            ?? $accounts->firstWhere('is_primary', true) ?? ($accounts->count() === 1 ? $accounts->first() : null);
        if ($target === null) {
            return ['error', null, "{$employee->employee_code} has {$accounts->count()} bank accounts and none is the salary (primary) account; mark one as primary on Employee 360 first."];
        }
        $same = collect($data)->every(fn ($value, $key) => (string) $target->getAttribute($key) === (string) $value);

        return $same ? ['skip', $target, 'These bank details are already on file.'] : ['update', $target, null];
    }

    /**
     * The employee a row names, its bank details in the form ChangeBankAccountAction takes (blank optional cells left
     * out, so an update keeps what is on file) and what is wrong with it. Never echoes the account number.
     *
     * @param  array<string, int>  $employees
     * @return array{0: ?Employee, 1: array<string, string>, 2: list<string>}
     */
    private function resolve(EmployeeImportRow $row, EmployeeImport $import, array $employees): array
    {
        $r = $this->recordFor($row, $import->mapping ?? []);
        $errors = [];
        foreach (self::FIELDS as $field => [$label, $required]) {
            if ($required && ! isset($r[$field])) {
                $errors[] = "{$label} is required.";
            }
        }

        $employee = null;
        if (isset($r['employee_code'])) {
            $id = $employees[mb_strtolower($r['employee_code'])] ?? null;
            $employee = $id ? Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->find($id) : null;
            if ($employee === null) {
                $errors[] = "Employee code '{$r['employee_code']}' does not exist.";
            }
        }

        $data = array_diff_key($r, ['employee_code' => true]);
        if (isset($data['account_number'])) {
            $data['account_number'] = str_replace([' ', "\u{00A0}"], '', $data['account_number']);
            if (preg_match('/^\d+(\.\d+)?e\+?\d+$/i', $data['account_number'])) {
                $errors[] = 'The account number was turned into a number like 5.01E+13 by the spreadsheet; format the column as Text and enter it again.';
            }
        }
        if (isset($data['ifsc'])) {
            $data['ifsc'] = mb_strtoupper(str_replace(' ', '', $data['ifsc']));
        }
        if (isset($data['account_type'])) {
            $data['account_type'] = mb_strtolower($data['account_type']);
        }

        $validator = Validator::make($data, $this->accounts->rules(true), [], ['ifsc' => 'IFSC', 'account_type' => 'account type', 'account_number' => 'account number',
            'account_holder_name' => 'account holder name', 'bank_name' => 'bank name', 'branch_name' => 'branch name']);
        foreach ($validator->errors()->all() as $message) {
            $errors[] = $message;
        }
        // HROne setup parity CA-5: the account number fits its bank's rule, when the bank has one.
        if (($problem = app(BankAccountRules::class)->problem($data['account_number'] ?? null, $data['ifsc'] ?? null, $data['bank_name'] ?? null)) !== null) {
            $errors[] = $problem;
        }

        return [$employee, $data, array_values(array_unique($errors))];
    }

    /** @return array<string, mixed>|null who the row is for and how it reads on screen, with the reason for a skip */
    private function describe(?Employee $employee, array $data, ?string $reason): ?array
    {
        return array_filter(['employee_id' => $employee?->id, 'employee_code' => $employee?->employee_code, 'name' => $employee?->person?->full_name,
            'matched_on' => $employee ? ['employee code'] : null, 'label' => $this->label($employee, $data), 'reason' => $reason], fn ($v) => $v !== null) ?: null;
    }

    private function label(?Employee $employee, array $data): string
    {
        $account = isset($data['account_number']) ? '••••'.mb_substr((string) $data['account_number'], -4) : null;

        return collect([$employee?->employee_code ?? ($data['employee_code'] ?? null), $employee?->person?->full_name, trim(($data['bank_name'] ?? '').' '.($account ?? ''))])
            ->filter()->implode(' · ');
    }

    /** @return array<string, string> field => non-empty value, read from the sealed copy */
    private function recordFor(EmployeeImportRow $row, array $mapping): array
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

    /** @return array<string, int> lower-cased employee code => id, within the uploader's organisation scope */
    private function employees(): array
    {
        return Employee::query()->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => (int) $id])->all();
    }

    /** Drop the clear-text sources once the upload is finished with: the sealed rows and the file. */
    private function forget(EmployeeImport $import): void
    {
        $import->rows()->toBase()->update(['sealed_data' => null]);
        Storage::disk($import->disk)->delete($import->path);
    }

    /**
     * HROne setup parity P2: the uploader approves their own file, unless the upload type is under maker-checker or
     * workflow approval (ImportGovernance); then another person allowed to make such uploads approves it (the upload
     * model refuses the uploader).
     */
    private function assertApprover(EmployeeImport $import, User $user): void
    {
        if (! ImportGovernance::checkedByAnother((string) $import->type)) {
            $this->assertUploader($import, $user);

            return;
        }
        if (! self::allowed($user)) {
            throw new RuntimeException('Uploading bank details needs employee.import and '.ChangeBankAccountAction::PERMISSION.'.');
        }
    }

    private function assertUploader(EmployeeImport $import, User $user): void
    {
        if ((int) $import->uploaded_by !== (int) $user->getKey()) {
            throw new RuntimeException('Only the person who uploaded a bank details file works on it.');
        }
        if (! self::allowed($user)) {
            throw new RuntimeException('Uploading bank details needs employee.import and '.ChangeBankAccountAction::PERMISSION.'.');
        }
    }

    private function assert(EmployeeImport $import, array $allowed): void
    {
        if ($import->type !== self::TYPE) {
            throw new RuntimeException('This import is not a bank details upload.');
        }
        if (! in_array($import->status, $allowed, true)) {
            throw new RuntimeException("This import is {$import->status}; expected ".implode(' or ', $allowed).'.');
        }
    }
}

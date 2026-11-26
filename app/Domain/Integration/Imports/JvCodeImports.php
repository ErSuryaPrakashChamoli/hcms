<?php

namespace App\Domain\Integration\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\ReferenceCells;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Models\JvCode;
use App\Domain\Integration\Services\JvCodes;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Data import DI-1 (Integration → JV … mapping): the accounting (journal voucher) code of companies, branches, sub
 * branches, business units, departments, sub departments, designations, dimension values, employees, grades, levels and
 * regions, by their name (names first: their PeopleOS code works too; employees by employee code, work e-mail or full
 * name). The process fixes the dimension (a blank Dimension cell is read as the process's).
 * Many records may share one JV code. A code can be set for every accounting system (Accounting system code blank) or
 * for one integration system. Written through JvCodes, as on Integrations → JV codes.
 */
final class JvCodeImports extends RowImports
{
    public const TYPE = 'jv_mappings';

    /** Names first: the list a dimension's records are named from (MasterCatalogue keys). */
    private const MASTERS = [
        'company' => 'company', 'location' => 'location', 'sub_branch' => 'location', 'business_unit' => 'business_unit', 'department' => 'department',
        'sub_department' => 'department', 'designation' => 'designation', 'dimension' => 'dimension_value', 'employee' => 'employee', 'grade' => 'grade',
        'level' => 'level', 'region' => 'region', 'pay_component' => 'salary_component', 'pay_component_payable' => 'salary_component',
    ];

    public static function fields(): array
    {
        return ['dimension' => ['Dimension', true], 'code' => ['Record', true], 'jv_code' => ['JV code', true], 'system_code' => ['Accounting system code', false]];
    }

    public function label(): string
    {
        return 'JV mapping upload';
    }

    protected function module(): string
    {
        return 'integration';
    }

    protected function entityType(): string
    {
        return JvCode::class;
    }

    protected function aliases(): array
    {
        return ['peopleos_code' => 'code', 'record_code' => 'code', 'master_code' => 'code', 'employee_code' => 'code', 'branch_code' => 'code', 'department_code' => 'code',
            'jv' => 'jv_code', 'ledger_code' => 'jv_code', 'accounting_code' => 'jv_code', 'gl_code' => 'jv_code', 'system' => 'system_code', 'integration_system' => 'system_code',
            'accounting_system' => 'system_code', 'type' => 'dimension', 'mapping' => 'dimension', 'name' => 'code', 'record_name' => 'code'];
    }

    /**
     * Names first: the Record cell names a record of the row's dimension (a branch, a department, an employee, a pay
     * component …), so the list it is looked up in follows the dimension; a name becomes that record's code before the
     * row is read. A row whose dimension is not known yet is read as typed (check() says what is wrong).
     */
    protected function readReferences(array $r, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $master = self::MASTERS[$this->dimension($r['dimension'] ?? null) ?? ''] ?? null;
        if ($master === null) {
            return [$r, [], []];
        }

        return ReferenceCells::translate($r, ['code' => $master], self::fields(), $import->sheet,
            (int) ($row->data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1));
    }

    protected function prepare(EmployeeImport $import): array
    {
        return ['systems' => IntegrationSystem::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all(), 'seen' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $jv = app(JvCodes::class);
        $errors = [];
        $dimension = $this->dimension($r['dimension'] ?? null);
        if ($dimension === null) {
            $errors[] = "Dimension '".($r['dimension'] ?? '')."' is not one of: ".implode(', ', array_keys(JvCodes::DIMENSIONS)).'.';
        }
        $code = trim((string) ($r['jv_code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 64) {
            $errors[] = 'JV code is needed (at most 64 characters).';
        }
        $systemId = null;
        if (($r['system_code'] ?? '') !== '') {
            $systemId = $context['systems'][mb_strtolower($r['system_code'])] ?? null;
            if ($systemId === null) {
                $errors[] = "Accounting system code '{$r['system_code']}' is not an integration system (Integrations → Systems).";
            }
        }
        $record = null;
        if ($dimension !== null) {
            $record = $jv->find($dimension, (string) ($r['code'] ?? ''));
            if ($record === null) {
                $errors[] = JvCodes::label($dimension)." '".($r['code'] ?? '')."' does not exist.";
            } elseif (($problem = $jv->mismatch($dimension, $record)) !== null) {
                $errors[] = "'{$this->nameOf($record, (string) $r['code'])}': {$problem}";
            }
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors];
        }

        $key = "{$dimension}|{$record->getKey()}|{$systemId}";
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same record (and system) is in row '.$context['seen'][$key].'.']];
        }
        $context['seen'][$key] = $row->row_number;
        $system = $systemId === null ? null : IntegrationSystem::query()->find($systemId);
        $current = $jv->current($dimension, $record, $system);
        if ($current === null) {
            return ['action' => 'create', 'match' => ['dimension' => $dimension, 'record_id' => $record->getKey()]];
        }

        return $current->code === $code
            ? ['action' => 'skip', 'match' => ['id' => $current->getKey(), 'reason' => 'This JV code is already on file.']]
            : ['action' => 'update', 'match' => ['id' => $current->getKey(), 'from' => $current->code]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $jv = app(JvCodes::class);
        $dimension = $this->dimension($r['dimension'] ?? null) ?? throw new RuntimeException('Unknown dimension.');
        $record = $jv->find($dimension, (string) $r['code']) ?? throw new RuntimeException(JvCodes::label($dimension)." '{$r['code']}' does not exist.");
        $system = ($r['system_code'] ?? '') === '' ? null : IntegrationSystem::query()->whereRaw('lower(code) = ?', [mb_strtolower($r['system_code'])])->firstOrFail();
        $before = $jv->current($dimension, $record, $system)?->code;
        $saved = $jv->set($dimension, $record, (string) $r['jv_code'], $system, $this->label()." #{$import->getKey()} ({$import->original_name})");
        $what = JvCodes::label($dimension).' '.$this->nameOf($record, trim((string) $r['code'])).($system !== null ? " ({$system->code})" : '');

        return $before === null ? "Set JV code {$saved->code} for {$what}" : "Changed the JV code of {$what} from {$before} to {$saved->code}";
    }

    /** "Head office (HO)" for messages: the record's name with the code the row named it by. */
    private function nameOf(Model $record, string $code): string
    {
        $name = $record->getAttributes()['name'] ?? null;

        return is_string($name) && $name !== '' && mb_strtolower($name) !== mb_strtolower($code) ? "{$name} ({$code})" : $code;
    }

    /** The dimension key a cell names: the key (sub_branch) or its label (Sub branch), any case. */
    private function dimension(?string $value): ?string
    {
        $wanted = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value)));
        foreach (JvCodes::DIMENSIONS as $key => $definition) {
            if ($wanted === $key || $wanted === str_replace(' ', '_', mb_strtolower($definition[0]))) {
                return $key;
            }
        }

        return null;
    }

    public function format(): array
    {
        $dimensions = implode(', ', array_map(fn (string $key) => $key.' ('.JvCodes::label($key).')', array_keys(JvCodes::DIMENSIONS)));

        return [
            'columns' => [
                ['Dimension', 'Yes', "What the code is for: {$dimensions}. Each JV process fills it in for blank cells.", 'department'],
                ['Record', 'Yes', 'The record of the dimension, by its name (its PeopleOS code works too); an employee by employee code, work e-mail or full name. A branch is a location without a parent, a sub branch one with a parent (departments likewise).', 'Engineering'],
                ['JV code', 'Yes', 'The code in your accounting system (ledger, cost centre or segment code; up to 64 characters). Several records may share one.', '4100-ENG'],
                ['Accounting system code', 'No', 'Code of an integration system (Integrations → Systems) when the code is only for that system; blank for every system.', ''],
            ],
            'notes' => [
                'Rows are matched on the dimension, the record and the accounting system. A record without a code gets one; a different code replaces the one on file; the same code is skipped.',
                'JV codes are kept per record (Integrations → JV codes) for journal voucher exports; a code for one system wins over the code for every system.',
                ReferenceGuide::NOTE,
            ],
        ];
    }

    /**
     * Two rows per dimension (records on file where there are some, by name; employees by employee code), so each JV
     * process's sample shows its own. A name two records share is written "Name (CODE)".
     */
    protected function examples(): array
    {
        $ledgers = ['company' => '1000', 'location' => '2000', 'sub_branch' => '2100', 'business_unit' => '3000', 'department' => '4100', 'sub_department' => '4200',
            'designation' => '5000', 'dimension' => '6000', 'employee' => '7000', 'grade' => '8000', 'level' => '8100', 'region' => '9000',
            'pay_component' => '5100', 'pay_component_payable' => '2200'];
        $fallback = ['company' => ['Acme Industries', 'Acme India'], 'location' => ['Bengaluru office', 'Pune plant'], 'sub_branch' => ['Whitefield annexe', 'Hosur Road warehouse'],
            'business_unit' => ['Retail banking', 'Corporate banking'], 'department' => ['Engineering', 'Finance'], 'sub_department' => ['Quality assurance', 'Platform'],
            'designation' => ['Software engineer', 'Team lead'], 'dimension' => ['Financial services', 'Banking and insurance'], 'employee' => ['EMP0001', 'EMP0002'],
            'grade' => ['Grade 1', 'Grade 2'], 'level' => ['Associate', 'Senior associate'], 'region' => ['South zone', 'West zone'],
            'pay_component' => ['Basic', 'House rent allowance'], 'pay_component_payable' => ['Employer PF', 'Employer ESI']];
        $rows = [];
        foreach (JvCodes::DIMENSIONS as $dimension => [, $class, $column, , $parentColumn, $level]) {
            $query = $class::query()->withoutGlobalScope(AccessScope::class)->orderBy('id');
            if ($parentColumn !== null) {
                $level === 'top' ? $query->whereNull($parentColumn) : $query->whereNotNull($parentColumn);
            }
            $records = $query->limit(2)->get();
            $named = array_values($records->map(fn (Model $record) => $this->exampleName($record, $class, $column))->all()) + $fallback[$dimension];
            foreach ([0, 1] as $i) {
                // Records may share a JV code (both rows post to one ledger); employees and pay components usually have their own.
                $own = in_array($dimension, ['employee', 'pay_component', 'pay_component_payable'], true);
                $suffix = (string) ($records->get($i)?->getAttribute($column) ?? strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $named[$i])));
                $rows[] = [$dimension, $named[$i], $own ? $ledgers[$dimension].'-'.$suffix : $ledgers[$dimension], ''];
            }
        }

        return $rows;
    }

    /** A record as a sample cell: an employee's code; another record's name, or "Name (CODE)" when another record of its list has that name. */
    private function exampleName(Model $record, string $class, string $column): string
    {
        $code = (string) $record->getAttribute($column);
        $name = $record->getAttributes()['name'] ?? null;
        if ($column !== 'code' || ! is_string($name) || trim($name) === '') {
            return $code;
        }
        $shared = $class::query()->withoutGlobalScope(AccessScope::class)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->count() > 1;

        return $shared ? "{$name} ({$code})" : $name;
    }
}

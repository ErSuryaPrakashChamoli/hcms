<?php

namespace App\Domain\Integration\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Models\JvCode;
use App\Domain\Integration\Services\JvCodes;
use RuntimeException;

/**
 * Data import DI-1 (Integration → JV … mapping): the accounting (journal voucher) code of companies, branches, sub
 * branches, business units, departments, sub departments, designations, dimension values, employees, grades, levels and
 * regions, by their PeopleOS code. The process fixes the dimension (a blank Dimension cell is read as the process's).
 * Many records may share one JV code. A code can be set for every accounting system (Accounting system code blank) or
 * for one integration system. Written through JvCodes, as on Integrations → JV codes.
 */
final class JvCodeImports extends RowImports
{
    public const TYPE = 'jv_mappings';

    public static function fields(): array
    {
        return ['dimension' => ['Dimension', true], 'code' => ['Code', true], 'jv_code' => ['JV code', true], 'system_code' => ['Accounting system code', false]];
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
            'accounting_system' => 'system_code', 'type' => 'dimension', 'mapping' => 'dimension'];
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
                $errors[] = JvCodes::label($dimension)." code '".($r['code'] ?? '')."' does not exist.";
            } elseif (($problem = $jv->mismatch($dimension, $record)) !== null) {
                $errors[] = "'{$r['code']}': {$problem}";
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
        $record = $jv->find($dimension, (string) $r['code']) ?? throw new RuntimeException(JvCodes::label($dimension)." code '{$r['code']}' does not exist.");
        $system = ($r['system_code'] ?? '') === '' ? null : IntegrationSystem::query()->whereRaw('lower(code) = ?', [mb_strtolower($r['system_code'])])->firstOrFail();
        $before = $jv->current($dimension, $record, $system)?->code;
        $saved = $jv->set($dimension, $record, (string) $r['jv_code'], $system, $this->label()." #{$import->getKey()} ({$import->original_name})");
        $what = JvCodes::label($dimension).' '.trim((string) $r['code']).($system !== null ? " ({$system->code})" : '');

        return $before === null ? "Set JV code {$saved->code} for {$what}" : "Changed the JV code of {$what} from {$before} to {$saved->code}";
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
                ['Code', 'Yes', 'The PeopleOS code of the record (the employee code for employees). A branch is a location without a parent, a sub branch one with a parent (departments likewise).', 'ENG'],
                ['JV code', 'Yes', 'The code in your accounting system (ledger, cost centre or segment code; up to 64 characters). Several records may share one.', '4100-ENG'],
                ['Accounting system code', 'No', 'Code of an integration system (Integrations → Systems) when the code is only for that system; blank for every system.', ''],
            ],
            'notes' => [
                'Rows are matched on the dimension, the record and the accounting system. A record without a code gets one; a different code replaces the one on file; the same code is skipped.',
                'JV codes are kept per record (Integrations → JV codes) for journal voucher exports; a code for one system wins over the code for every system.',
            ],
        ];
    }

    /** Two rows per dimension (codes on file where there are some), so each JV process's sample shows its own. */
    protected function examples(): array
    {
        $ledgers = ['company' => '1000', 'location' => '2000', 'sub_branch' => '2100', 'business_unit' => '3000', 'department' => '4100', 'sub_department' => '4200',
            'designation' => '5000', 'dimension' => '6000', 'employee' => '7000', 'grade' => '8000', 'level' => '8100', 'region' => '9000',
            'pay_component' => '5100', 'pay_component_payable' => '2200'];
        $fallback = ['company' => ['ACME', 'ACME-IN'], 'location' => ['BLR', 'PUN'], 'sub_branch' => ['BLR-WF', 'BLR-WH'], 'business_unit' => ['RETAIL', 'CORP'],
            'department' => ['ENG', 'FIN'], 'sub_department' => ['ENG-QA', 'ENG-PLAT'], 'designation' => ['SE', 'TL'], 'dimension' => ['FIN-VERT', 'BFSI'],
            'employee' => ['EMP0001', 'EMP0002'], 'grade' => ['G1', 'G2'], 'level' => ['L1', 'L2'], 'region' => ['SOUTH', 'WEST'],
            'pay_component' => ['BASIC', 'HRA'], 'pay_component_payable' => ['PF_ER', 'ESI_ER']];
        $rows = [];
        foreach (JvCodes::DIMENSIONS as $dimension => [, $class, $column, , $parentColumn, $level]) {
            $query = $class::query()->withoutGlobalScope(AccessScope::class)->orderBy('id');
            if ($parentColumn !== null) {
                $level === 'top' ? $query->whereNull($parentColumn) : $query->whereNotNull($parentColumn);
            }
            $codes = array_values($query->limit(2)->pluck($column)->map(fn ($c) => (string) $c)->all()) + $fallback[$dimension];
            foreach ([0, 1] as $i) {
                // Records may share a JV code (both rows post to one ledger); employees and pay components usually have their own.
                $rows[] = [$dimension, $codes[$i], in_array($dimension, ['employee', 'pay_component', 'pay_component_payable'], true) ? $ledgers[$dimension].'-'.$codes[$i] : $ledgers[$dimension], ''];
            }
        }

        return $rows;
    }
}

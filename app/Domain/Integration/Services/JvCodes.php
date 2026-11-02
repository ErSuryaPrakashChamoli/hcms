<?php

namespace App\Domain\Integration\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Models\JvCode;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Region;
use App\Domain\Payroll\Models\SalaryComponent;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Data import DI-1 (Integration → JV mappings): the accounting (journal voucher) code of each record, per dimension.
 * integration_mappings cannot hold these: its key (system, dimension, external value) maps one external value to one
 * record, while a JV code is commonly shared (many departments post to one ledger) and needs no integration system.
 * A record has one code per dimension for every system, plus optionally one per accounting system (which wins for that
 * system). Records are found by their PeopleOS code across the whole organisation (system context, like integration
 * lookups); a branch is a location without a parent and a sub branch one with a parent (departments likewise).
 */
final class JvCodes
{
    /** Who maintains JV codes. */
    public const PERMISSION = 'integration.manage';

    /** dimension => [label, model, code column, record type, hierarchy column (top-level / sub records) or null, 'top'|'sub'|null] */
    public const DIMENSIONS = [
        'company' => ['Company', Company::class, 'code', 'company', null, null],
        'location' => ['Branch', Location::class, 'code', 'location', 'parent_location_id', 'top'],
        'sub_branch' => ['Sub branch', Location::class, 'code', 'location', 'parent_location_id', 'sub'],
        'business_unit' => ['Business unit', BusinessUnit::class, 'code', 'business_unit', null, null],
        'department' => ['Department', Department::class, 'code', 'department', 'parent_department_id', 'top'],
        'sub_department' => ['Sub department', Department::class, 'code', 'department', 'parent_department_id', 'sub'],
        'designation' => ['Designation', Designation::class, 'code', 'designation', null, null],
        'dimension' => ['Dimension value', DimensionValue::class, 'code', 'dimension_value', null, null],
        'employee' => ['Employee', Employee::class, 'employee_code', 'employee', null, null],
        'grade' => ['Grade', Grade::class, 'code', 'grade', null, null],
        'level' => ['Level', Level::class, 'code', 'level', null, null],
        'region' => ['Region', Region::class, 'code', 'region', null, null],
        // Decision D2: the ledger a pay component posts to (expense for earnings and employer contributions, liability for
        // deductions), and the liability an employer contribution is owed to; read by the payroll journal voucher.
        'pay_component' => ['Pay component', SalaryComponent::class, 'code', 'pay_component', null, null],
        'pay_component_payable' => ['Pay component payable', SalaryComponent::class, 'code', 'pay_component', null, null],
    ];

    /** @return array<string, string> dimension => label */
    public static function dimensions(): array
    {
        return array_map(fn (array $d) => $d[0], self::DIMENSIONS);
    }

    public static function label(string $dimension): string
    {
        return self::DIMENSIONS[$dimension][0] ?? $dimension;
    }

    /** The record of a dimension by its PeopleOS code (case-insensitive), or null. */
    public function find(string $dimension, string $code): ?Model
    {
        [, $class, $column] = self::DIMENSIONS[$dimension] ?? throw new RuntimeException("Unknown JV dimension '{$dimension}'.");

        return $class::query()->withoutGlobalScope(AccessScope::class)->whereRaw("lower({$column}) = ?", [mb_strtolower(trim($code))])->first();
    }

    /** Why the record does not belong to the dimension (a sub branch named as a branch …), or null. */
    public function mismatch(string $dimension, Model $record): ?string
    {
        [$label, $class, , , $column, $level] = self::DIMENSIONS[$dimension] ?? throw new RuntimeException("Unknown JV dimension '{$dimension}'.");
        if (! $record instanceof $class) {
            return "That record is not a {$label}.";
        }
        if ($column === null) {
            return null;
        }
        $hasParent = ($record->getAttributes()[$column] ?? null) !== null;
        if ($level === 'top' && $hasParent) {
            return $dimension === 'location' ? 'It is a sub branch: use the JV sub branch mapping.' : 'It is a sub department: use the JV sub department mapping.';
        }
        if ($level === 'sub' && ! $hasParent) {
            return $dimension === 'sub_branch' ? 'It is a branch, not a sub branch: use the JV branch mapping.' : 'It is a department, not a sub department: use the JV department mapping.';
        }

        return null;
    }

    /** The code on file for the record (for one system, or for every system when $system is null). */
    public function current(string $dimension, Model $record, ?IntegrationSystem $system = null): ?JvCode
    {
        return JvCode::query()->where('dimension', $dimension)->where('record_id', $record->getKey())
            ->where('system_key', (string) ($system?->getKey() ?? ''))->first();
    }

    /** The code to post the record under in a system's journal: the system's own, else the one for every system. */
    public function codeFor(string $dimension, Model $record, ?IntegrationSystem $system = null): ?string
    {
        return ($system !== null ? $this->current($dimension, $record, $system)?->code : null) ?? $this->current($dimension, $record)?->code;
    }

    /**
     * Every code of a dimension at once (for exports): record id => code, a system's own code winning over the code for
     * every system.
     *
     * @return array<int, string>
     */
    public function codes(string $dimension, ?IntegrationSystem $system = null): array
    {
        $rows = JvCode::query()->where('dimension', $dimension)->whereIn('system_key', array_unique(['', (string) ($system?->getKey() ?? '')]))
            ->orderByRaw("case when system_key = '' then 0 else 1 end")->get(['record_id', 'code']);

        return $rows->mapWithKeys(fn (JvCode $jv) => [(int) $jv->record_id => (string) $jv->code])->all();
    }

    /** Set (or change) a record's JV code. Returns the row; audited on it. */
    public function set(string $dimension, Model $record, string $code, ?IntegrationSystem $system = null, ?string $reason = null): JvCode
    {
        if (($problem = $this->mismatch($dimension, $record)) !== null) {
            throw new RuntimeException($problem);
        }
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 64) {
            throw new RuntimeException('A JV code is needed (at most 64 characters).');
        }
        $existing = $this->current($dimension, $record, $system);
        if ($existing !== null) {
            if ($existing->code !== $code) {
                $existing->withAuditReason($reason)->update(['code' => $code]);
            }

            return $existing;
        }
        $jv = new JvCode(['dimension' => $dimension, 'record_type' => self::DIMENSIONS[$dimension][3], 'record_id' => $record->getKey(), 'integration_system_id' => $system?->getKey(), 'code' => $code]);
        $jv->withAuditReason($reason)->save();

        return $jv;
    }

    /** The record a JV code row is for (whatever the viewer's organisation scope), or null when it is gone. */
    public function recordOf(JvCode $jv): ?Model
    {
        $class = self::DIMENSIONS[$jv->dimension][1] ?? null;

        return $class === null ? null : $class::query()->withoutGlobalScope(AccessScope::class)->find($jv->record_id);
    }

    /** "ENG — Engineering" for a JV code row's record. */
    public function recordLabel(JvCode $jv): string
    {
        $record = $this->recordOf($jv);
        if ($record === null) {
            return 'Record #'.$jv->record_id.' (removed)';
        }
        if ($record instanceof Employee) {
            $record->loadMissing('person');
            [$code, $name] = [$record->employee_code, $record->person?->display_name];
        } else {
            [$code, $name] = [$record->getAttributes()['code'] ?? null, $record->getAttributes()['name'] ?? null];
        }

        return implode(' — ', array_filter([(string) $code, (string) $name], fn (string $part) => $part !== ''));
    }
}

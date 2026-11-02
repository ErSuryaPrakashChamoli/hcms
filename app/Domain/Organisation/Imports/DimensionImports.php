<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\DimensionValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Core → Dimension): the values of the organisation's own dimension by code, optionally under a
 * parent value (on file, or an earlier row of the same file). A value never sits under itself or its own sub-values.
 */
final class DimensionImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'dimensions';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'parent_code' => ['Parent code', false], 'description' => ['Description', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Dimension upload';
    }

    protected function updatePermission(): string
    {
        return 'organisation.update';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function model(): string
    {
        return DimensionValue::class;
    }

    protected function aliases(): array
    {
        return ['dimension_code' => 'code', 'dimension_name' => 'name', 'value' => 'name', 'parent' => 'parent_code', 'parent_dimension' => 'parent_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $parentCode = $r['parent_code'] ?? null;
        $parentId = null;
        if ($parentCode !== null && mb_strtolower(trim($parentCode)) === mb_strtolower(trim((string) ($r['code'] ?? '')))) {
            $errors[] = 'A value cannot sit under itself.';
        } else {
            $parentId = $this->parentId(DimensionValue::class, $parentCode, $context, 'Parent code', $errors);
        }
        if ($parentId !== null && $parentId > 0 && DimensionValue::wouldLoop($existing, $parentId)) {
            $errors[] = "'{$parentCode}' sits under '{$existing->code}': a value cannot sit under one of its own values.";
        }

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 120, 'Name', $errors),
            'parent_id' => $parentId,
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The value\'s code (up to 32 characters). A code on file updates that value.', 'BFSI'],
                ['Name', 'Yes', 'The value\'s name.', 'Banking and financial services'],
                ['Parent code', 'No', 'Code of the value it sits under (on file, or an earlier row of this file).', 'FIN-VERT'],
                ['Description', 'No', 'What it covers.', 'Banks, insurers and NBFC clients'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
            ],
            'notes' => [
                'Your dimension is your own extra way to group the organisation (verticals, practices, programmes …). Each row is one of its values.',
                'Rows are matched on Code (any case). A new code adds a value; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. List a parent value before the values under it when both are in the file.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [['FIN-VERT', 'Financial services', '', 'All financial services clients', 'active'], ['BFSI', 'Banking and insurance', 'FIN-VERT', 'Banks, insurers and NBFC clients', 'active']];
    }
}

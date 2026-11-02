<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1 (Core → Grades): grades by code with their level and rank. */
final class GradeImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'grades';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'level_code' => ['Level code', false], 'rank' => ['Rank', false], 'description' => ['Description', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Grades upload';
    }

    protected function updatePermission(): string
    {
        return 'people_setup.update';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function model(): string
    {
        return Grade::class;
    }

    protected function aliases(): array
    {
        return ['grade_code' => 'code', 'grade_name' => 'name', 'grade' => 'name', 'level' => 'level_code', 'order' => 'rank', 'sequence' => 'rank'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $rank = $this->number($r['rank'] ?? null, 'Rank', $errors, 0, 9999, true);

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'level_id' => $this->lookup($context, Level::class, $r['level_code'] ?? null, 'Level code', $errors),
            'rank' => $rank === null ? null : (int) $rank,
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The grade code (up to 32 characters). A code on file updates that grade.', 'G1'],
                ['Name', 'Yes', 'The grade name.', 'Grade 1'],
                ['Level code', 'No', 'Code of its level (People setup → Levels).', 'L1'],
                ['Rank', 'No', 'A whole number that orders grades (0 to 9999).', '1'],
                ['Description', 'No', 'A short description.', 'Entry grade'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a grade; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. Changes to an existing grade follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $level = Level::query()->orderBy('id')->value('code') ?? '';

        return [['G1', 'Grade 1', $level, '1', 'Entry grade', 'active'], ['G2', 'Grade 2', $level, '2', 'Experienced grade', 'active']];
    }
}

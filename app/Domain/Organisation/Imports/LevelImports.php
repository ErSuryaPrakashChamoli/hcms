<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\Level;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1 (Core → Levels): levels by code with their rank (1 = the most senior or the first, as you rank them). */
final class LevelImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'levels';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'rank' => ['Rank', false], 'description' => ['Description', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Levels upload';
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
        return Level::class;
    }

    protected function aliases(): array
    {
        return ['level_code' => 'code', 'level_name' => 'name', 'level' => 'name', 'order' => 'rank', 'sequence' => 'rank'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $rank = $this->number($r['rank'] ?? null, 'Rank', $errors, 0, 9999, true);

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'rank' => $rank === null ? null : (int) $rank,
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The level code (up to 32 characters). A code on file updates that level.', 'L1'],
                ['Name', 'Yes', 'The level name.', 'Associate'],
                ['Rank', 'No', 'A whole number that orders levels (0 to 9999).', '1'],
                ['Description', 'No', 'A short description.', 'Entry level'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a level; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. Changes to an existing level follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [['L1', 'Associate', '1', 'Entry level', 'active'], ['L2', 'Senior associate', '2', 'Works independently', 'active']];
    }
}

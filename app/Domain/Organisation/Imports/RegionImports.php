<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\Region;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1 (Core → Regions): regions (zones) by code. Branches name their region in the Branches upload. */
class RegionImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'regions';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'description' => ['Description', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Regions upload';
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
        return Region::class;
    }

    protected function what(): string
    {
        return 'region';
    }

    protected function aliases(): array
    {
        return ['region_code' => 'code', 'region_name' => 'name', 'zone_code' => 'code', 'zone' => 'name'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        return array_filter([
            'name' => $this->text($r['name'] ?? null, 120, 'Name', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        $what = $this->what();

        return [
            'columns' => [
                ['Code', 'Yes', "The {$what} code (up to 32 characters). A code on file updates that {$what}.", $this->examples()[0][0]],
                ['Name', 'Yes', "The {$what} name.", $this->examples()[0][1]],
                ['Description', 'No', 'What it covers.', $this->examples()[0][2]],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds one; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. Branches are put in a '.$what.' with the Branches upload or on the location screen.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [['SOUTH', 'South zone', 'Karnataka, Tamil Nadu, Kerala, Telangana and Andhra Pradesh', 'active'], ['WEST', 'West zone', 'Maharashtra, Gujarat and Goa', 'active']];
    }
}

<?php

namespace App\Filament\Pages;

use App\Domain\Workforce\Services\WorkforceSnapshot;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Phase 10 position hierarchy on a date (parent positions from the versions in force). Not the reporting hierarchy. */
class PositionHierarchyPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Position hierarchy';

    protected static ?string $title = 'Position hierarchy';

    protected static ?string $slug = 'position-hierarchy';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.position-hierarchy';

    public ?string $on = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('workforce.view') ?? false;
    }

    /** @return list<array{depth: int, code: string, title: string, status: string, seats: int, occupied: int}> */
    public function rows(): array
    {
        $day = Carbon::parse($this->on ?: now())->toDateString();
        $versions = app(WorkforceSnapshot::class)->positionsOn($day)->with('position:id,code')->limit(2000)->get();
        $byParent = $versions->groupBy(fn ($v) => (int) $v->parent_position_id);
        $present = $versions->pluck('position_id')->map(fn ($id) => (int) $id)->flip();
        $rows = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent) {
            foreach ($byParent->get($parentId, collect())->sortBy('title') as $v) {
                $rows[] = ['depth' => $depth, 'code' => $v->position?->code, 'title' => $v->title, 'status' => $v->status, 'seats' => (int) $v->headcount, 'occupied' => (int) $v->occupied_seats];
                if ($depth < 30) {
                    $walk((int) $v->position_id, $depth + 1);
                }
            }
        };
        $walk(0, 0);
        // Positions whose parent is not in force on the date are shown as roots.
        foreach ($byParent as $parentId => $children) {
            if ($parentId !== 0 && ! $present->has($parentId)) {
                $walk($parentId, 0);
            }
        }

        return $rows;
    }
}

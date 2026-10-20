<?php

namespace App\Filament\Pages;

use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\Workforce\Services\WorkforceSnapshot;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Phase 10 workforce on any date, reconstructed from effective-dated records (no stored snapshots). */
class WorkforceSnapshotsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Workforce snapshots';

    protected static ?string $title = 'Workforce snapshot';

    protected static ?string $slug = 'workforce-snapshots';

    protected static ?int $navigationSort = 70;

    protected string $view = 'filament.pages.workforce-snapshots';

    public ?string $on = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('workforce.view') ?? false;
    }

    public function day(): string
    {
        return Carbon::parse($this->on ?: now())->toDateString();
    }

    /** @return array<string, mixed> */
    public function headcount(): array
    {
        return app(WorkforceSnapshot::class)->headcount($this->day());
    }

    /** @return list<array<string, mixed>> */
    public function byDepartment(): array
    {
        return $this->named(app(WorkforceSnapshot::class)->by('department_id', $this->day()), Department::class);
    }

    /** @return list<array<string, mixed>> */
    public function byLocation(): array
    {
        return $this->named(app(WorkforceSnapshot::class)->by('location_id', $this->day()), Location::class);
    }

    private function named(array $rows, string $model): array
    {
        $names = $model::query()->whereIn('id', array_filter(array_column($rows, 'id')))->pluck('name', 'id');

        return array_map(fn ($r) => ['name' => $r['id'] ? ($names[$r['id']] ?? '#'.$r['id']) : 'Not set', ...$r], $rows);
    }
}

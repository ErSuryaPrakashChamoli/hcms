<?php

namespace App\Filament\Pages;

use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionChangeRequest;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Services\WorkforceSnapshot;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Phase 10 workforce overview: seats, FTE, occupancy, vacancies, planned positions, approvals waiting, critical roles. */
class WorkforceDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Workforce overview';

    protected static ?string $slug = 'workforce-dashboard';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.workforce-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('workforce.view') || $user->can('workforce.analytics'));
    }

    /** @return array<string, mixed> */
    public function getHeadcount(): array
    {
        return app(WorkforceSnapshot::class)->headcount();
    }

    /** @return array<string, int> */
    public function getQueues(): array
    {
        return [
            'proposed_positions' => Position::query()->where('status', 'proposed')->count(),
            'planned_positions' => Position::query()->whereIn('status', ['approved', 'planned'])->count(),
            'change_requests' => PositionChangeRequest::query()->where('status', 'pending')->whereHas('position')->count(),
            'plans_waiting' => WorkforcePlanVersion::query()->whereIn('status', ['submitted', 'under_review'])->whereHas('plan')->count(),
            'active_plans' => WorkforcePlanVersion::query()->where('status', 'active')->whereHas('plan')->count(),
            'critical_positions' => CriticalPosition::query()->where('status', 'active')->count(),
        ];
    }
}

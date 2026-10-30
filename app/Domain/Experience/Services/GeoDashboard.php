<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * HROne parity CA-9 (Access rights → Geo access), the part that needs no map: today's check-ins that carry a location and
 * the headcount by location, for the people the viewer looks after — a manager their direct reports today, HR their
 * access scope. Who sees each panel is the organisation's choice (geo_dashboard.checkins_visible_to /
 * geo_dashboard.headcount_visible_to, manager | hr; check-ins default to nobody — their coordinates are shown on no screen
 * before this one — and the headcount to HR); a stored value that is not a list shows nothing.
 * Read through the attendance and employee records under the viewer's own access scope, with the module permissions
 * (attendance.view for check-ins, employee.view for headcount). The map waits for the map component (CA-8).
 */
final class GeoDashboard
{
    public const AUDIENCES = ['manager' => 'Managers (their team)', 'hr' => 'HR (their access scope)'];

    public const PANELS = [
        'checkins' => ['setting' => 'geo_dashboard.checkins_visible_to', 'permission' => 'attendance.view'],
        'headcount' => ['setting' => 'geo_dashboard.headcount_visible_to', 'permission' => 'employee.view'],
    ];

    public const CAP = 500;

    public function __construct(private readonly RoleLens $lenses, private readonly TenantContext $tenants) {}

    /** @return array<string, string> panel => the audience the signed-in viewer sees it as (hr before manager) */
    public function panels(User $viewer): array
    {
        if (! (auth()->check() && (int) auth()->id() === (int) $viewer->getKey() && $this->tenants->has()) || $viewer->isPlatformAdmin()) {
            return [];
        }
        $out = [];
        foreach (self::PANELS as $panel => $definition) {
            $allowed = app(SettingsRepository::class)->get($definition['setting'], []);
            if (! is_array($allowed) || ! $viewer->hasPermission($definition['permission'])) {
                continue;
            }
            if (in_array('hr', $allowed, true) && $this->lenses->has($viewer, RoleLens::HR)) {
                $out[$panel] = 'hr';
            } elseif (in_array('manager', $allowed, true) && $this->lenses->has($viewer, RoleLens::MANAGER)) {
                $out[$panel] = 'manager';
            }
        }

        return $out;
    }

    /** @return Collection<int, AttendancePunch> today's punches with a location (newest first) */
    public function checkins(User $viewer): Collection
    {
        $audience = $this->panels($viewer)['checkins'] ?? null;
        if ($audience === null) {
            return collect();
        }
        $today = CarbonImmutable::now();

        return AttendancePunch::query()->with('employee.person')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('punched_at', [$today->startOfDay(), $today->endOfDay()])
            ->when($audience === 'manager', fn ($q) => $q->whereIn('employee_id', $this->team($viewer)))
            ->orderByDesc('punched_at')->limit(self::CAP)->get()
            ->filter(fn (AttendancePunch $punch) => $punch->employee !== null && $viewer->can('view', $punch))->values();
    }

    /** @return array<int, string> geo-fence names by id (for the punches' matched work location) */
    public function fenceNames(Collection $punches): array
    {
        $ids = $punches->pluck('attendance_geo_fence_id')->filter()->unique()->all();

        return $ids === [] ? [] : GeoFence::query()->whereKey($ids)->pluck('name', 'id')->all();
    }

    /** @return list<array{location: string, count: int, pin: ?string}> employed people by their location today */
    public function headcount(User $viewer): array
    {
        $audience = $this->panels($viewer)['headcount'] ?? null;
        if ($audience === null) {
            return [];
        }
        $employed = array_map(fn (LifecycleState $s) => $s->value, array_filter(LifecycleState::cases(), fn (LifecycleState $s) => $s->isEmployed()));
        $people = Employee::query()->select('employees.id')->whereIn('lifecycle_state', $employed)
            ->when($audience === 'manager', fn ($q) => $q->whereKey($this->team($viewer)));
        $counts = EmployeePosition::query()->effectiveOn()->whereIn('employee_id', $people)
            ->selectRaw('location_id, count(*) as n')->groupBy('location_id')->toBase()->get()
            ->mapWithKeys(fn ($row) => [(int) ($row->location_id ?? 0) => (int) $row->n]);
        $names = Location::query()->whereKey($counts->keys()->filter()->all())->pluck('name', 'id');
        // Work-location pins only for people who may already see work locations (attendance configuration, GeoFence policy).
        $pins = ! $viewer->hasPermission('attendance.view') ? collect() : GeoFence::query()->whereIn('location_id', $counts->keys()->filter()->all())->whereNull('employee_id')
            ->whereNotNull('latitude')->get(['location_id', 'latitude', 'longitude'])->keyBy('location_id');

        return $counts->map(fn (int $n, int $id) => [
            'location' => $id === 0 ? 'No location' : (string) ($names[$id] ?? 'A location outside your scope'),
            'count' => $n,
            'pin' => isset($pins[$id]) ? round((float) $pins[$id]->latitude, 5).', '.round((float) $pins[$id]->longitude, 5) : null,
        ])->sortByDesc('count')->values()->all();
    }

    /** @return list<int> */
    private function team(User $viewer): array
    {
        $me = $this->lenses->employee($viewer);

        return $me === null ? [] : $me->directReports()->currentlyEffective()->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}

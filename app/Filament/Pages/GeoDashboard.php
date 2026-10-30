<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Experience\Services\GeoDashboard as GeoDashboardReadModel;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * HROne parity CA-9 (Access rights → Geo access): today's located check-ins and the headcount by location, as lists (the
 * map waits for the map component of CA-8). Who sees each panel follows Setup → Access rights → Geo access; location data
 * is personal, so every opening is audited (VIEW, the panels shown, never the coordinates).
 */
class GeoDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $title = 'Geo dashboard';

    protected static ?string $slug = 'geo-dashboard';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.access.geo-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(TenantContext::class)->has() && app(GeoDashboardReadModel::class)->panels($user) !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        app(AuditRecorder::class)->record(AuditAction::View, 'attendance', null, [], 'Geo dashboard opened', metadata: [
            'screen' => 'geo_dashboard', 'panels' => app(GeoDashboardReadModel::class)->panels(auth()->user()),
        ], entityLabel: 'Geo dashboard');
    }

    /** @return array<string, string> */
    public function panels(): array
    {
        return app(GeoDashboardReadModel::class)->panels(auth()->user());
    }

    /** @return list<array{name: string, time: string, direction: string, coordinates: string, place: ?string, status: ?string}> */
    #[Computed]
    public function checkins(): array
    {
        abort_unless(static::canAccess(), 403);
        $model = app(GeoDashboardReadModel::class);
        $punches = $model->checkins(auth()->user());
        $fences = $model->fenceNames($punches);

        return $punches->map(fn ($p) => [
            'name' => (string) ($p->employee->person?->display_name ?? $p->employee->employee_code),
            'time' => $p->punched_at->format('H:i'),
            'direction' => (string) $p->direction,
            'coordinates' => round((float) $p->latitude, 5).', '.round((float) $p->longitude, 5),
            'place' => $fences[$p->attendance_geo_fence_id] ?? null,
            'status' => $p->geo_status,
        ])->all();
    }

    /** @return list<array{location: string, count: int, pin: ?string}> */
    #[Computed]
    public function headcount(): array
    {
        abort_unless(static::canAccess(), 403);

        return app(GeoDashboardReadModel::class)->headcount(auth()->user());
    }
}

<?php

namespace App\Filament\Pages;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Services\PositionOccupancy;
use App\Domain\Workforce\Services\WorkforceAccess;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Phase 10 manager view: the positions under the positions I hold and those held by the employees I
 * manage (configured relationship types only) — headcount, open and planned positions, vacancies.
 * No plans, scenarios or costs.
 */
class TeamWorkforce extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Team positions';

    protected static ?string $title = 'Team positions';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.team-workforce';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('workforce.team') && TalentActions::me() !== null && app(WorkforceAccess::class)->teamPositionIds($user)->isNotEmpty();
    }

    /** @return list<array<string, mixed>> */
    public function positions(): array
    {
        $ids = app(WorkforceAccess::class)->teamPositionIds(auth()->user());
        $positions = Position::query()->withoutGlobalScope(AccessScope::class)->with('currentVersion')->whereIn('id', $ids)->orderBy('code')->get();
        $occupancy = app(PositionOccupancy::class)->occupancyFor($positions->pluck('id')->all());

        return $positions->map(fn (Position $p) => [
            'code' => $p->code, 'title' => $p->title, 'status' => $p->status,
            'seats' => (int) $p->currentVersion?->headcount, 'occupied' => (int) ($occupancy[$p->id]->seats ?? 0),
        ])->all();
    }
}

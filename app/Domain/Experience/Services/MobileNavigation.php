<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\OrganisationMap;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Pages\People;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Users\UserResource;
use Illuminate\Support\Str;
use Throwable;

/**
 * UX.17: the phone and tablet bar, composed per experience (RoleLens). Five places: Home, the role's most
 * frequent destinations, and Actions in the centre. A slot shows the first candidate the person may open
 * (each destination's own canAccess()); a slot with no allowed candidate is dropped. The decision count
 * always rides on Approvals or, when Approvals is not in the bar, on Work, so a view switch never hides a
 * decision. The bar only links to screens; it grants nothing.
 */
final class MobileNavigation
{
    /** Experience → slots; each slot lists its candidates in order. 'actions' is the centre button. */
    public const BAR = [
        'employee' => [['home'], ['work'], ['actions'], ['services'], ['people']],
        'manager' => [['home'], ['approvals', 'work'], ['actions'], ['team', 'people'], ['work', 'services']],
        'hr' => [['home'], ['work'], ['actions'], ['people'], ['requests', 'services']],
        'payroll' => [['home'], ['work'], ['actions'], ['payroll', 'services'], ['people', 'services']],
        'executive' => [['home'], ['pulse', 'work'], ['actions'], ['org', 'people'], ['work', 'services']],
        'admin' => [['home'], ['admin', 'services'], ['actions'], ['users', 'people'], ['work', 'services']],
    ];

    /**
     * The bar for the signed-in person. Fails closed (an empty bar) for anyone else: destinations decide
     * access from the session, so composing it for another user would describe the wrong person's access.
     *
     * @return list<array{key: string, label: string, icon: string, url: ?string, active: bool, badge: int, action: bool}>
     */
    public function items(User $user, ?string $experience = null, ?string $route = null): array
    {
        if (auth()->id() !== $user->getKey()) {
            return [];
        }
        $experience ??= app(ExperienceNavigation::class)->experience($user);
        $route ??= (string) request()->route()?->getName();
        $slots = self::BAR[$experience] ?? self::BAR['employee'];

        $chosen = [];
        foreach ($slots as $candidates) {
            foreach ($candidates as $key) {
                if (isset($chosen[$key])) {
                    continue;
                }
                $item = $this->destination($key);
                if ($item !== null) {
                    $chosen[$key] = $item;
                    break;
                }
            }
        }

        // Decisions are never lost: the count rides on Approvals, otherwise on Work.
        $badgeOn = isset($chosen['approvals']) ? 'approvals' : 'work';
        if (! isset($chosen['approvals']) && isset($chosen['work'])) {
            $chosen['work']['routes'][] = 'filament.admin.pages.approvals';
        }
        $count = isset($chosen[$badgeOn]) ? $this->decisionCount($user) : 0;

        $items = [];
        $activeTaken = false;
        foreach ($chosen as $key => $item) {
            $active = ! $activeTaken && $route !== '' && Str::is($item['routes'], $route);
            $activeTaken = $activeTaken || $active;
            $items[] = [
                'key' => $key,
                'label' => $item['label'],
                'icon' => $item['icon'],
                'url' => $item['url'],
                'active' => $active,
                'badge' => $key === $badgeOn ? $count : 0,
                'action' => $key === 'actions',
            ];
        }

        return $items;
    }

    /** @return array{label: string, icon: string, url: ?string, routes: list<string>}|null */
    private function destination(string $key): ?array
    {
        try {
            return match ($key) {
                'actions' => ['label' => 'Actions', 'icon' => 'heroicon-m-plus', 'url' => null, 'routes' => []],
                'home' => ['label' => 'Home', 'icon' => 'heroicon-o-sun', 'url' => Home::getUrl(), 'routes' => ['filament.admin.pages.home', 'filament.admin.pages.dashboard']],
                'work' => MyWork::canAccess() ? ['label' => 'Work', 'icon' => 'heroicon-o-inbox-stack', 'url' => MyWork::getUrl(), 'routes' => ['filament.admin.pages.my-work', 'filament.admin.pages.task-inbox', 'filament.admin.pages.notifications']] : null,
                'approvals' => Approvals::canAccess() ? ['label' => 'Approvals', 'icon' => 'heroicon-o-check-badge', 'url' => Approvals::getUrl(), 'routes' => ['filament.admin.pages.approvals']] : null,
                'team' => MyTeam::canAccess() ? ['label' => 'Team', 'icon' => 'heroicon-o-user-group', 'url' => MyTeam::getUrl(), 'routes' => ['filament.admin.pages.my-team', 'filament.admin.pages.team-*', 'filament.admin.pages.people', 'filament.admin.resources.employees.*']] : null,
                'people' => People::canAccess() ? ['label' => 'People', 'icon' => 'heroicon-o-users', 'url' => People::getUrl(), 'routes' => ['filament.admin.pages.people', 'filament.admin.resources.employees.*', 'filament.admin.pages.organisation-map']] : null,
                'services' => MyHr::canAccess() ? ['label' => 'Services', 'icon' => 'heroicon-o-lifebuoy', 'url' => MyHr::getUrl(), 'routes' => ['filament.admin.pages.my-hr']] : null,
                'requests' => TicketResource::canAccess() ? ['label' => 'Requests', 'icon' => 'heroicon-o-chat-bubble-left-right', 'url' => TicketResource::getUrl('index'), 'routes' => ['filament.admin.resources.tickets.*']] : null,
                'payroll' => PayrollControlRoom::canAccess() ? ['label' => 'Payroll', 'icon' => 'heroicon-o-banknotes', 'url' => PayrollControlRoom::getUrl(), 'routes' => ['filament.admin.pages.payroll-control-room', 'filament.admin.resources.payroll-runs.*']] : null,
                'pulse' => WorkforceCommandCentre::canAccess() ? ['label' => 'Pulse', 'icon' => 'heroicon-o-presentation-chart-line', 'url' => WorkforceCommandCentre::getUrl(), 'routes' => ['filament.admin.pages.workforce-command-centre']] : null,
                'org' => OrganisationMap::canAccess() ? ['label' => 'Org map', 'icon' => 'heroicon-o-building-office-2', 'url' => OrganisationMap::getUrl(), 'routes' => ['filament.admin.pages.organisation-map']] : null,
                'admin' => AdminCentre::canAccess() ? ['label' => 'Admin', 'icon' => 'heroicon-o-cog-6-tooth', 'url' => AdminCentre::getUrl(), 'routes' => ['filament.admin.pages.admin-centre']] : null,
                'users' => UserResource::canAccess() ? ['label' => 'Users', 'icon' => 'heroicon-o-key', 'url' => UserResource::getUrl('index'), 'routes' => ['filament.admin.resources.users.*', 'filament.admin.resources.roles.*']] : null,
                default => null,
            };
        } catch (Throwable) {
            // A destination that cannot build its URL (missing route) is simply not offered.
            return null;
        }
    }

    private function decisionCount(User $user): int
    {
        try {
            return app(ApprovalCenter::class)->count($user);
        } catch (Throwable) {
            return 0;
        }
    }
}

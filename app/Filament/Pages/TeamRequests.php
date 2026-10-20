<?php

namespace App\Filament\Pages;

use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 12: a manager's view of HR requests raised by the people they manage. It covers only the
 * configured reporting relationships (PerformanceRelationships; never mentors, buddies or project
 * leads). It shows status only, for standard cases of services marked manager-visible: no form data,
 * no comments, no sensitive or confidential cases. Managers may raise manager-available services for a
 * report.
 */
class TeamRequests extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Team HR requests';

    protected static ?string $title = 'Team HR requests';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.team-requests';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasPermission('servicedesk.team');
    }

    /** @return Collection<int, Ticket> */
    public function getRequests(): Collection
    {
        return app(CaseAccess::class)->team(Ticket::query()->with(['service', 'category', 'employee.person']), auth()->user())->orderByDesc('tickets.id')->limit(100)->get();
    }

    public function statusLabel(string $status): string
    {
        return TicketResource::statusLabel($status);
    }

    /** Services a manager may raise for a report (manager-available, approved, in force). */
    public function getServices(): Collection
    {
        return app(ServiceCatalogue::class)->availableTo(auth()->user(), null, 'manager');
    }

    public function requestServiceAction(): Action
    {
        return ServiceDeskActions::requestService();
    }
}

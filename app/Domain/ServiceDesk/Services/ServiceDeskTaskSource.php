<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Filament\Resources\Tickets\TicketResource;
use Illuminate\Support\Collection;

/**
 * Phase 12: service-desk items that need the user. As the employee, requests waiting for their input or
 * resolved and waiting to be closed. As an agent, open cases assigned to them. Both lists are read
 * through CaseAccess.
 */
final class ServiceDeskTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly CaseAccess $access) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        $mine = $employee ? $this->access->visible(Ticket::query()->with(['service', 'category']), $user)->where('tickets.employee_id', $employee->id)->whereIn('tickets.status', ['waiting_employee', 'resolved'])->limit(20)->get() : collect();
        $assigned = $user->hasPermission('servicedesk.agent') ? $this->access->visible(Ticket::query()->with(['service', 'category']), $user)->where('tickets.assignee_id', $user->id)->whereIn('tickets.status', Ticket::OPEN)->orderBy('tickets.due_at')->limit(20)->get() : collect();

        return $mine->map(fn (Ticket $t) => new ExperienceTask('servicedesk', $t->status === 'resolved' ? 'confirm' : 'respond', ($t->status === 'resolved' ? 'Confirm and close: ' : 'HR needs your input: ').$t->serviceName(), $t->number, null, TicketResource::getUrl('view', ['record' => $t])))
            ->merge($assigned->map(fn (Ticket $t) => new ExperienceTask('servicedesk', 'case', 'Work case: '.$t->serviceName(), $t->number, $t->due_at, TicketResource::getUrl('view', ['record' => $t]))));
    }
}

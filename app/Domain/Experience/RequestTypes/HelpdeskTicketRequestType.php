<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Pages\Home;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\ServiceDeskActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: helpdesk ticket (Home "Raise an HR request" = "Ask HR"; agents raise one for an employee from the
 * HR queue). Lists every HR request of the person — "Ask HR" questions and catalogue services — as CaseAccess shows them.
 */
final class HelpdeskTicketRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'helpdesk';
    }

    public function label(): string
    {
        return 'Helpdesk ticket';
    }

    public function description(): string
    {
        return 'Ask HR a question or report a problem';
    }

    public function module(): string
    {
        return 'Helpdesk';
    }

    public function icon(): string
    {
        return 'heroicon-o-chat-bubble-left-ellipsis';
    }

    public function keywords(): array
    {
        return ['helpdesk', 'ticket', 'ask hr', 'hr request', 'question', 'problem'];
    }

    public function permission(): string
    {
        return 'servicedesk.request';
    }

    /** "Ask HR" is offered with the ticket create right; the helpdesk access policy must leave at least one category. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('create', Ticket::class) && ServiceDeskActions::askableCategories() !== [];
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(Home::getUrl(), 'askHr');
    }

    public function onBehalfPermission(): ?string
    {
        return 'servicedesk.agent';
    }

    public function onBehalfUrl(User $user): ?string
    {
        return $this->signedIn($user) && TicketResource::canAccess() && $user->can('create', Ticket::class) && $user->can('servicedesk.agent')
            ? $this->withAction(TicketResource::getUrl('index'), 'askHr') : null;
    }

    public function model(): ?string
    {
        return Ticket::class;
    }

    protected function resource(): ?string
    {
        return TicketResource::class;
    }

    /** The person's tickets as CaseAccess lets them see them (drafts only the ones they saved themselves). */
    public function ownRecords(User $user, ?Employee $employee): ?Builder
    {
        if ($employee === null || ! $employee->exists) {
            return null;
        }

        return app(CaseAccess::class)->visible(Ticket::query()->with(['service', 'category']), $user)->where('tickets.employee_id', $employee->getKey());
    }

    public function statusMap(): array
    {
        $map = ['draft' => RequestStatus::Draft, 'resolved' => RequestStatus::Approved, 'closed' => RequestStatus::Approved, 'cancelled' => RequestStatus::Withdrawn];
        foreach (Ticket::OPEN as $status) {
            $map[$status] = RequestStatus::Pending;
        }

        return $map;
    }

    public function statusLabel(Model $record): string
    {
        $raw = $this->rawStatus($record);

        return (string) (config('peopleos.servicedesk.statuses')[$raw] ?? ucfirst(str_replace('_', ' ', $raw)));
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Raised on', 'column' => 'created_at'],
            'decided' => ['label' => 'Resolved on', 'column' => 'resolved_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'subject'];
    }

    public function summary(Model $record): array
    {
        /** @var Ticket $record */
        return [
            'title' => $this->join($record->number, $record->serviceName()),
            'detail' => $this->short($record->subject),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['servicedesk.ticket.created'];
    }

    public function builtInApprovers(): string
    {
        return 'The HR helpdesk team that handles the category';
    }

    public function approverPermissions(): array
    {
        return [];
    }

    public function knowledgeCategory(): ?string
    {
        return 'hr_policy';
    }
}

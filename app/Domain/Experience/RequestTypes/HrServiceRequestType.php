<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Filament\Pages\MyHr;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: the HR service catalogue (My HR → Services: letters, certificates, changes). A launcher entry only:
 * each service request is a helpdesk ticket, listed under "Helpdesk ticket".
 */
final class HrServiceRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'hr_service';
    }

    public function label(): string
    {
        return 'HR service';
    }

    public function description(): string
    {
        return 'Letters, certificates, changes and other services from HR';
    }

    public function module(): string
    {
        return 'Helpdesk';
    }

    public function icon(): string
    {
        return 'heroicon-o-squares-2x2';
    }

    public function keywords(): array
    {
        return ['service', 'catalogue', 'letter', 'certificate', 'hr service'];
    }

    public function permission(): string
    {
        return 'servicedesk.request';
    }

    /** My HR opens and the catalogue offers the person at least one service now. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && MyHr::canAccess()
            && app(ServiceCatalogue::class)->availableTo($user, $employee, 'employee')->isNotEmpty();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return MyHr::getUrl(['tab' => 'services']);
    }

    public function historyVisibleTo(User $user): bool
    {
        return false;
    }

    public function statusMap(): array
    {
        return [];
    }

    public function summary(Model $record): array
    {
        return ['title' => $this->label(), 'detail' => null];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function builtInApprovers(): string
    {
        return 'The HR helpdesk team that handles the service';
    }

    public function knowledgeCategory(): ?string
    {
        return 'hr_policy';
    }
}

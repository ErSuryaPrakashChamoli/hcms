<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\ServiceDeskActions;
use Filament\Resources\Pages\ViewRecord;

/** Case detail. Opening a sensitive or restricted case is audited (CONFIDENTIAL_CASE_VIEWED). */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(CaseAccess::class)->recordSensitiveRead(auth()->user(), $this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return ServiceDeskActions::forTicket();
    }
}

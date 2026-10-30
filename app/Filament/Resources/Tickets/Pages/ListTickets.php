<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RegisterReport;
use App\Filament\Support\ServiceDeskActions;

class ListTickets extends PeopleListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity XO-1 (matrix 26 §26.1 #4): the Tickets page's Reports tab — the service desk's library reports
            // (shown only to people who may read the service desk dataset).
            RegisterReport::action(['tickets_by_category', 'sla_breaches']),
            ServiceDeskActions::askHr(),
        ];
    }
}

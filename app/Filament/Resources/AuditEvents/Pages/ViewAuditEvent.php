<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewAuditEvent extends PeopleViewRecord
{
    protected static string $resource = AuditEventResource::class;
}

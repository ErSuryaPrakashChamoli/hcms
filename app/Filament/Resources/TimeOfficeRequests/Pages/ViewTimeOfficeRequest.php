<?php

namespace App\Filament\Resources\TimeOfficeRequests\Pages;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** HROne parity TX-1: a time-office request's detail, read-only (decisions stay on the register and in the Approval Center). */
class ViewTimeOfficeRequest extends PeopleViewRecord
{
    protected static string $resource = TimeOfficeRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var TimeOfficeRequest $request */
        $request = $this->getRecord();

        return $request->auditLabel();
    }
}
